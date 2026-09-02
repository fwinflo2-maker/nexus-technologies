<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Correlation;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\EnvironmentGuard;
use Nexus\Execution\ExecutionContext;
use Nexus\Providers\ProviderConfig;
use PDO;
use Throwable;

/**
 * ExecutionSettlementService — règlement asynchrone des transferts provider.
 *
 * Certaines API provider (ex. mobile money) sont asynchrones : un payout est d'abord accepté
 * (ACCEPTED/ENQUEUED → transaction Nexus 'processing'), puis évolue vers un
 * état final via webhook ou polling :
 *
 *   COMPLETED → transactions.status = 'completed'   (terminal)
 *   FAILED    → transactions.status = 'failed'      (terminal) + REMBOURSEMENT
 *
 * INVARIANTS FINANCIERS :
 *   - Aucune transition illégitime : seules les transitions depuis
 *     processing/pending vers completed/failed sont acceptées. Un webhook
 *     « inventé » (statut déjà terminal, opération inconnue, environnement
 *     incohérent) est rejeté sans effet.
 *   - Aucun double remboursement : le crédit de compensation passe par
 *     LedgerService (type 'refund') avec une clé d'idempotence déterministe
 *     `op:{operationId}:refund` — un rejeu du webhook ne crédite qu'une fois.
 *   - Le montant remboursé est le montant CAPTURÉ (débit hold), lu depuis
 *     l'opération wallet d'origine — jamais un montant fourni par le webhook.
 *   - L'environnement de l'opération fait autorité (sandbox ≠ production).
 *
 * La méthode d'entrée est `settle()` ; elle est appelée par le webhook
 * (ProviderWebhookController) et par la réconciliation (polling).
 */
final class ExecutionSettlementService
{
    /** Statuts Nexus depuis lesquels une transition est autorisée. */
    private const SETTLABLE_FROM = ['processing', 'pending'];

    /** Terminaux : aucune transition possible. */
    private const TERMINAL = ['completed', 'failed', 'cancelled'];

    private function __construct()
    {
    }

    /**
     * Applique un statut provider à une transaction Nexus.
     *
     * @param array<string,mixed> $tx          Transaction (formatExecutionEngine).
     * @param string              $mappedStatus Statut Nexus mappé ('completed'|'failed'|'processing').
     * @param string              $rawStatus    Statut brut du provider (ACCEPTED, COMPLETED…).
     * @param array<string,mixed> $details      Détails (failure_reason, montants…).
     *
     * @return array<string,mixed> Transaction mise à jour + résultat de règlement.
     * @throws HttpException 409 si la transition est illégitime.
     */
    public static function settle(
        array $tx,
        string $mappedStatus,
        string $rawStatus,
        array $details = [],
        ?ExecutionContext $context = null
    ): array {
        $pdo = Database::getConnection();
        $txId = (int) $tx['id'];

        $pdo->beginTransaction();
        try {
            // Relecture sous verrou : deux webhooks concurrents ne peuvent pas
            // régler la même transaction deux fois.
            $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $txId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new HttpException(404, 'Transaction introuvable.', 'TRANSACTION_NOT_FOUND');
            }

            $currentStatus = (string) $row['status'];
            $target = strtolower($mappedStatus);

            // Environnement : le webhook doit concerner la même sandbox/prod.
            $env = (string) ($row['environment'] ?? '');
            if ($context !== null && $env !== '') {
                EnvironmentGuard::assertMatches($env, $context, 'Cette transaction');
            }

            // Idempotence : statut déjà terminal ou identique → acquittement sans effet.
            if (in_array($currentStatus, self::TERMINAL, true)) {
                $pdo->rollBack();
                return self::outcome($row, 'ignored', 'Statut déjà terminal (' . $currentStatus . ').');
            }
            if ($target === $currentStatus) {
                $pdo->rollBack();
                return self::outcome($row, 'ignored', 'Statut inchangé (' . $currentStatus . ').');
            }

            // Transition illégitime (ex. webhook FAILED sur une ligne completed).
            if (!in_array($currentStatus, self::SETTLABLE_FROM, true) || !in_array($target, ['completed', 'failed'], true)) {
                $pdo->rollBack();
                throw new HttpException(
                    409,
                    sprintf(
                        'Transition illégitime : %s → %s pour la transaction %d.',
                        $currentStatus,
                        $target,
                        $txId
                    ),
                    'ILLEGAL_TRANSITION'
                );
            }

            // ── Règlement (modèle cible, double entrée) ───────────────────
            // Le hold (résolu par sa clé d'idempotence) porte les montants
            // EXACTS (8 dp) : jamais un montant fourni par le webhook.
            $hold = self::resolveHold($pdo, $row);
            $env = (string) ($row['environment'] ?? ProviderConfig::defaultEnvironment());
            if ($target === 'completed') {
                // Solde la contrepartie de capture, sans second débit wallet :
                //   DEBIT  OUTBOUND_TRANSIT.{devise}          (principal + frais)
                //   CREDIT PROVIDER_SETTLEMENT.{provider}.{d} (principal)
                //   CREDIT NEXUS_REVENUE.fee                 (frais Nexus, isolés)
                $total  = (string) $hold['source_amount'];
                $fee    = (string) ($hold['fee_amount'] ?? '0');
                $fee    = bcadd($fee, '0', 8);
                $principal = bccomp($total, $fee, 8) >= 0 ? bcsub($total, $fee, 8) : $total;
                LedgerService::postOutboundDebit(
                    (string) $hold['id'],
                    (int) $hold['source_wallet_id'],
                    (string) $hold['source_currency'],
                    $principal,
                    $fee,
                    (string) ($row['provider'] ?? 'unknown'),
                    'Envoi réglé chez le provider',
                    'send',
                    (string) $row['provider_operation_id'],
                    [
                        'kind'            => 'provider_settlement',
                        'transaction_id'  => (int) $row['id'],
                        'provider_status' => $rawStatus,
                    ],
                    $env
                );
            } else {
                // Échec provider : annulation équilibrée de la capture et
                // recrédit du wallet, sans faire confiance au payload.
                LedgerService::postOutboundReturn(
                    (string) $hold['id'],
                    (string) $hold['source_wallet_id'],
                    (string) $hold['source_amount'],
                    (string) $hold['source_currency']
                );
            }

            $upd = $pdo->prepare(
                'UPDATE transactions
                 SET status = :status, provider_status = :pstatus, updated_at = NOW()
                 WHERE id = :id'
            );
            $upd->execute([
                'status'  => $target,
                'pstatus' => substr($rawStatus, 0, 30),
                'id'      => $txId,
            ]);

            self::notify($pdo, $row, $target, $rawStatus);
            self::audit($pdo, $row, $target, $rawStatus, $details);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fresh = $pdo->prepare('SELECT * FROM transactions WHERE id = :id LIMIT 1');
        $fresh->execute(['id' => $txId]);
        $updated = $fresh->fetch() ?: $row;

        return self::outcome($updated, 'settled', 'Transaction réglée : ' . $target . '.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Hold — résolution pour le règlement (montants EXACTS, jamais du webhook)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Retrouve l'opération de hold d'un envoi via sa clé d'idempotence
     * 'op:{operationId}:hold' (posée par ExecutionEngine) et lit ses montants.
     * Le montant réglé/retourné vient TOUJOURS du hold — jamais du webhook
     * (un webhook forgé ne peut pas inventer un montant).
     *
     * @return array<string,mixed> wallet_operations (hold)
     */
    private static function resolveHold(PDO $pdo, array $tx): array
    {
        $operationId = (string) ($tx['provider_operation_id'] ?? '');
        $userId      = (int) $tx['user_id'];
        $env         = (string) ($tx['environment'] ?? ProviderConfig::defaultEnvironment());

        if ($operationId === '') {
            throw new HttpException(409, 'Aucune opération wallet associée : règlement impossible.', 'REFUND_UNKNOWN_OPERATION');
        }

        $cached = IdempotencyService::check('op:' . $operationId . ':hold', $userId, $env);
        $holdOpId = is_array($cached) ? (string) ($cached['operation_id'] ?? '') : '';
        if ($holdOpId === '') {
            throw new HttpException(409, 'Hold introuvable pour le règlement.', 'REFUND_HOLD_NOT_FOUND');
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM wallet_operations WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $holdOpId]);
        $hold = $stmt->fetch();
        if ($hold === false) {
            throw new HttpException(409, 'Hold introuvable pour le règlement.', 'REFUND_HOLD_NOT_FOUND');
        }
        return $hold;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Notification + audit
    // ──────────────────────────────────────────────────────────────────────

    private static function notify(PDO $pdo, array $tx, string $target, string $rawStatus): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message)
             VALUES (:uid, :type, :title, :msg)'
        );
        if ($target === 'completed') {
            $stmt->execute([
                'uid'   => (int) $tx['user_id'],
                'type'  => 'transfert',
                'title' => 'Transfert réglé',
                'msg'   => sprintf(
                    'Votre envoi %s %s a été réglé chez le provider (statut %s).',
                    $tx['amount'],
                    $tx['currency'],
                    $rawStatus
                ),
            ]);
        } else {
            $stmt->execute([
                'uid'   => (int) $tx['user_id'],
                'type'  => 'transfert',
                'title' => 'Transfert échoué — remboursé',
                'msg'   => sprintf(
                    'Le payout (statut %s) a échoué : %s %s ont été remboursés sur votre portefeuille.',
                    $rawStatus,
                    $tx['amount'],
                    $tx['currency']
                ),
            ]);
        }
    }

    /** @param array<string,mixed> $details */
    private static function audit(PDO $pdo, array $tx, string $target, string $rawStatus, array $details = []): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, environment, created_at)
                 VALUES (:uid, :act, :etype, :eid, :meta, :env, NOW())'
            );
            $stmt->execute([
                'uid'   => (int) $tx['user_id'],
                'act'   => $target === 'completed' ? 'transfer.settled' : 'transfer.failed_refunded',
                'etype' => 'transactions',
                'eid'   => (int) $tx['id'],
                'meta'  => json_encode([
                    'provider'                => (string) $tx['provider'],
                    'provider_status'         => $rawStatus,
                    'provider_operation_id'   => (string) $tx['provider_operation_id'],
                    'transaction_id'          => (int) $tx['id'],
                    'request_id'              => (string) ($details['request_id'] ?? Correlation::id()),
                    'event_id'                => (string) ($details['event_id'] ?? ''),
                    'provider_transaction_id' => $details['provider_transaction_id'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'env'   => (string) ($tx['environment'] ?? 'sandbox'),
            ]);
        } catch (Throwable $e) {
            error_log('[NEXUS settlement] audit : ' . $e->getMessage());
        }
    }

    private static function outcome(array $tx, string $action, string $message): array
    {
        return [
            'transaction' => $tx,
            'action'      => $action,
            'message'     => $message,
        ];
    }
}
