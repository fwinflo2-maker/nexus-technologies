<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Providers\ProviderConfig;
use PDO;
use Throwable;

/**
 * FundingService — chemin d'ENTRÉE de fonds réel (modèle cible §6.A).
 *
 * Flux : webhook provider → validation → idempotence → LEDGER POSTING
 * (jamais un `UPDATE wallets SET balance = balance + X` sans écriture) :
 *
 *   DEBIT  PROVIDER_ASSET.{provider}.{devise}   (fonds détenus chez le provider)
 *   CREDIT USER_POSITION.{devise}               (position utilisateur)
 *
 * Le wallet est crédité dans la même transaction (projection), le montant
 * entre dans le bucket `pending` — disponible après `settleDeposit()`
 * (politique de disponibilité ; défaut conservateur : settlement requis).
 *
 * JAMAIS d'argent créé : le posting exige une clé d'idempotence déterministe
 * (le rejeu du webhook ne crédite qu'une fois) et un compte provider résolu.
 */
final class FundingService
{
    private function __construct()
    {
    }

    /**
     * Enregistre un dépôt confirmé par le provider.
     *
     * @return array{operation_id:string, status:string, balance:string}
     */
    public static function recordDeposit(
        int $userId,
        int $walletId,
        string $currency,
        string $amount,
        string $providerCode,
        string $idempotencyKey,
        ?string $providerRef = null,
        ?array $metadata = null,
        ?ExecutionContext $context = null
    ): array {
        if ($amount === '' || bccomp($amount, '0', 8) <= 0) {
            throw new HttpException(422, 'Montant de dépôt invalide.', 'INVALID_DEPOSIT_AMOUNT');
        }

        $env = $context?->environmentValue() ?? ProviderConfig::defaultEnvironment();

        // Idempotence : un webhook dupliqué ne crédite jamais deux fois.
        $cached = IdempotencyService::check($idempotencyKey, $userId, $env);
        if ($cached !== null) {
            if ($cached['status'] === 'completed') {
                return [
                    'operation_id' => (string) $cached['operation_id'],
                    'status'       => 'completed',
                    'balance'      => (string) ($cached['response_json']['balance'] ?? ''),
                ];
            }
            throw new HttpException(409, 'Dépôt déjà en cours.', 'DEPOSIT_IN_PROGRESS');
        }

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $operationId = self::generateUuid();

            // Compte provider (un compte par slug/env/devise).
            $providerAccountId = ProviderAccountService::resolve($providerCode, $env, $currency, 'safeguarding');

            // Opération métier 'deposit' (requise par fk_ledger_operation_env).
            $stmt = $pdo->prepare(
                'INSERT INTO wallet_operations
                    (id, user_id, type, status, environment, source_wallet_id, source_currency,
                     source_amount, fee_amount, provider_account_id, description, metadata,
                     idempotency_key, completed_at)
                 VALUES (:id, :uid, :type, :status, :env, :wid, :cur,
                         :amt, 0, :pacc, :desc, :meta, :idem, NOW())'
            );
            $stmt->execute([
                'id'     => $operationId,
                'uid'    => $userId,
                'type'   => 'deposit',
                'status' => 'processing',
                'env'    => $env,
                'wid'    => $walletId,
                'cur'    => $currency,
                'amt'    => bcadd($amount, '0', 8),
                'pacc'   => $providerAccountId,
                'desc'   => 'Dépôt confirmé par le provider',
                'meta'   => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                'idem'   => $idempotencyKey,
            ]);

            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId, $env);
            if (!$state['created']) {
                throw new HttpException(409, 'Dépôt déjà en cours.', 'DEPOSIT_IN_PROGRESS');
            }

            // Posting double entrée : PROVIDER_ASSET debit / USER_POSITION credit.
            $balance = LedgerService::postFundingCredit(
                $operationId,
                $walletId,
                $currency,
                $amount,
                $providerCode,
                'Dépôt provider — entrée de fonds',
                'deposit',
                $providerRef !== null && $providerRef !== '' ? $providerRef : $operationId,
                array_merge(is_array($metadata) ? $metadata : [], ['provider_ref' => $providerRef]),
                $env
            );

            IdempotencyService::complete($idempotencyKey, $userId, ['operation_id' => $operationId, 'status' => 'processing', 'balance' => $balance], $operationId, $env);

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['operation_id' => $operationId, 'status' => 'processing', 'balance' => $balance];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Politique de disponibilité : déplace le dépôt du bucket pending vers
     * available (settlement du provider confirmé). Idempotent (statut op).
     */
    public static function settleDeposit(string $operationId, int $userId, ?ExecutionContext $context = null): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM wallet_operations WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $operationId]);
            $op = $stmt->fetch();
            if ($op === false || (int) $op['user_id'] !== $userId) {
                throw new HttpException(404, 'Opération de dépôt introuvable.', 'DEPOSIT_NOT_FOUND');
            }
            if ($op['type'] !== 'deposit') {
                throw new HttpException(422, "L'opération n'est pas un dépôt.", 'NOT_A_DEPOSIT');
            }
            if ($op['status'] !== 'processing') {
                $pdo->commit(); // déjà réglé — ne jamais laisser la transaction ouverte
                return;
            }

            $walletId = (int) $op['source_wallet_id'];
            $amount   = (string) $op['source_amount'];
            $currency = (string) $op['source_currency'];

            $stmtW = $pdo->prepare('SELECT * FROM wallets WHERE id = :id FOR UPDATE');
            $stmtW->execute(['id' => $walletId]);
            $wallet = $stmtW->fetch();
            if ($wallet === false) {
                throw new HttpException(404, 'Wallet introuvable.', 'WALLET_NOT_FOUND');
            }

            $newPending   = bcsub((string) $wallet['pending_balance'], $amount, 8);
            $newAvailable = bcadd((string) $wallet['available_balance'], $amount, 8);
            if (bccomp($newPending, '0', 8) < 0) {
                throw new HttpException(409, 'Pending insuffisant pour le settlement du dépôt.', 'PENDING_INSUFFICIENT');
            }

            $upd = $pdo->prepare(
                'UPDATE wallets SET pending_balance = :p, available_balance = :a WHERE id = :id'
            );
            $upd->execute(['p' => $newPending, 'a' => $newAvailable, 'id' => $walletId]);

            $updOp = $pdo->prepare(
                "UPDATE wallet_operations SET status = 'completed', completed_at = NOW() WHERE id = :id"
            );
            $updOp->execute(['id' => $operationId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
