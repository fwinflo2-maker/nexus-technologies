<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Providers\ProviderCapabilityMatrix;
use Nexus\Providers\ProviderRegistry;
use PDO;
use Throwable;

/**
 * ProviderReconciliationService — réconciliation EXPLICITE entre Nexus et le
 * provider, par polling du statut côté adaptateur.
 *
 * Le webhook est un canal d'annonce ; il peut être perdu (réponse acceptée
 * mais callback jamais délivré, callback livré après expiration du retry…).
 * La réconciliation est le filet de sécurité : elle interroge le provider
 * pour les transactions restées 'processing' au-delà d'un délai et applique
 * l'état réel — ou signale les écarts qui exigent une décision humaine.
 *
 * Providers pollables = ceux dont la matrice déclare reconciliation=IMPLEMENTED
 * (aujourd'hui : pawapay). Aucun hardcode métier hors de la matrice.
 *
 * Cas détectés :
 *   - provider COMPLETED   → Nexus 'processing' : régler (completed).
 *   - provider FAILED      → Nexus 'processing' : régler + remboursement.
 *   - provider introuvable → transaction Nexus sans trace provider : à
 *     examiner (payout jamais matérialisé OU identifiant inconnu).
 *   - montant/devise différents entre le provider et la quote : écart à
 *     examiner (ne JAMAIS corriger un montant automatiquement).
 *
 * Aucun montant n'est corrigé automatiquement : la réconciliation ne tranche
 * que le STATUT, jamais l'argent.
 */
final class ProviderReconciliationService
{
    /** Délai minimum avant de considérer une transaction « à réconcilier ». */
    public const DEFAULT_STALE_SECONDS = 120;

    private function __construct()
    {
    }

    /**
     * Réconcilie les transactions 'processing' immobiles depuis un délai donné.
     *
     * @return array<string,mixed> Rapport détaillé.
     */
    public static function reconcile(
        string $environment,
        int $staleSeconds = self::DEFAULT_STALE_SECONDS,
        bool $apply = false,
        ?ExecutionContext $context = null
    ): array {
        $staleSeconds = max(30, $staleSeconds);
        $pdo = Database::getConnection();

        $report = [
            'environment'        => $environment,
            'examined'           => 0,
            'settled_completed'  => 0,
            'settled_failed'     => 0,
            'still_processing'   => [],
            'missing_at_provider'=> [],
            'discrepancies'      => [],
            'errors'             => [],
            'pollable_providers' => self::pollableProviders(),
        ];

        $pollable = self::pollableProviders();
        if ($pollable === []) {
            $report['errors'][] = [
                'error'   => 'NO_POLLABLE_PROVIDER',
                'message' => 'Aucun provider avec reconciliation=IMPLEMENTED.',
            ];
            return $report;
        }

        $placeholders = implode(',', array_fill(0, count($pollable), '?'));
        $sql = "SELECT id, user_id, provider, provider_operation_id, provider_status,
                       dest_amount, dest_currency, amount, currency, environment, updated_at
                  FROM transactions
                 WHERE status = 'processing'
                   AND provider IN ({$placeholders})
                   AND provider_operation_id IS NOT NULL
                   AND environment = ?
                   AND TIMESTAMPDIFF(SECOND, updated_at, NOW()) >= ?
                 ORDER BY updated_at ASC
                 LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $params = array_merge($pollable, [$environment, $staleSeconds]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $report['examined']++;
            $providerSlug = (string) ($row['provider'] ?? '');

            try {
                $providerStatus = ProviderRegistry::adapter($providerSlug)
                    ->getPaymentStatus((string) $row['provider_operation_id']);
            } catch (HttpException $e) {
                $report['errors'][] = [
                    'transaction_id' => (int) $row['id'],
                    'error'          => $e->errorCode(),
                    'message'        => $e->getMessage(),
                ];
                continue;
            } catch (Throwable $e) {
                $report['errors'][] = [
                    'transaction_id' => (int) $row['id'],
                    'error'          => 'UNEXPECTED',
                    'message'        => $e->getMessage(),
                ];
                continue;
            }

            $mapped = (string) ($providerStatus['status'] ?? 'processing');

            // ── Contrôle de cohérence montant/devise (jamais corrigé) ──
            $providerAmount   = (string) ($providerStatus['amount'] ?? '');
            $providerCurrency = strtoupper((string) ($providerStatus['currency'] ?? ''));
            $txAmount   = (string) ($row['dest_amount'] ?? '');
            $txCurrency = strtoupper((string) ($row['dest_currency'] ?? ''));
            $discrepancy = $providerAmount !== '' && $txAmount !== ''
                && (bccomp($providerAmount, $txAmount, 2) !== 0 || $providerCurrency !== $txCurrency);
            if ($discrepancy) {
                $report['discrepancies'][] = [
                    'transaction_id'      => (int) $row['id'],
                    'nexus_dest_amount'   => $txAmount,
                    'nexus_dest_currency' => $txCurrency,
                    'provider_amount'     => $providerAmount,
                    'provider_currency'   => $providerCurrency,
                    'note'                => 'Écart montant/devise : aucun correctif automatique — décision requise.',
                ];
            }

            // ── État réel chez le provider ──────────────────────────────
            // Un écart montant/devise bloque TOUT règlement automatique :
            // la transaction reste 'processing' (reconciliation_required) et
            // une décision humaine est exigée. Ne jamais « compléter » une
            // opération dont le montant ne concorde pas avec le provider.
            if ($discrepancy) {
                $report['still_processing'][] = [
                    'transaction_id'   => (int) $row['id'],
                    'provider_status'  => $providerStatus['provider_status'] ?? 'UNKNOWN',
                    'action'           => 'reconciliation_required',
                ];
                continue;
            }

            if ($mapped === 'completed') {
                if ($apply) {
                    self::settleTransaction($row, 'completed', (string) ($providerStatus['provider_status'] ?? 'COMPLETED'), $context);
                    $report['settled_completed']++;
                } else {
                    $report['still_processing'][] = ['transaction_id' => (int) $row['id'], 'provider_status' => 'COMPLETED', 'action' => 'would_complete'];
                }
                continue;
            }

            if ($mapped === 'failed') {
                if ($apply) {
                    self::settleTransaction($row, 'failed', (string) ($providerStatus['provider_status'] ?? 'FAILED'), $context);
                    $report['settled_failed']++;
                } else {
                    $report['still_processing'][] = ['transaction_id' => (int) $row['id'], 'provider_status' => 'FAILED', 'action' => 'would_fail_and_refund'];
                }
                continue;
            }

            // Provider ne connaît pas ce payout (statut inconnu/vide) : à examiner.
            if (($providerStatus['provider_status'] ?? '') === 'UNKNOWN'
                || ($providerStatus['amount'] ?? '') === ''
            ) {
                $report['missing_at_provider'][] = [
                    'transaction_id'      => (int) $row['id'],
                    'provider_operation_id' => (string) $row['provider_operation_id'],
                    'note'                => 'Transaction Nexus sans trace chez le provider : vérification manuelle requise.',
                ];
                continue;
            }

            $report['still_processing'][] = [
                'transaction_id' => (int) $row['id'],
                'provider_status'=> $providerStatus['provider_status'] ?? 'UNKNOWN',
                'action'         => 'keep_polling',
            ];
        }

        return $report;
    }

    /** Applique un règlement (statut) via ExecutionSettlementService. */
    private static function settleTransaction(array $tx, string $mapped, string $rawStatus, ?ExecutionContext $context): void
    {
        ExecutionSettlementService::settle($tx, $mapped, $rawStatus, ['provider_status' => $rawStatus], $context);
    }

    /**
     * Providers réellement réconciliables (matrice : reconciliation=IMPLEMENTED).
     *
     * @return list<string>
     */
    public static function pollableProviders(): array
    {
        $out = [];
        foreach (array_keys(ProviderCatalog::all()) as $slug) {
            if (ProviderCapabilityMatrix::for($slug)['reconciliation']
                === ProviderCapabilityMatrix::IMPLEMENTED
            ) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Réconciliation des BALANCES (provider_balances vs PROVIDER_ASSET)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Enregistre une OBSERVATION externe du solde chez le provider.
     * Jamais créatrice d'argent : seule la réconciliation confronte
     * l'observation au ledger (PROVIDER_ASSET).
     */
    public static function recordBalanceObservation(
        int $providerAccountId,
        string $currency,
        string $available,
        string $pending,
        string $source = 'api',
        ?string $raw = null
    ): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO provider_balances
                (provider_account_id, currency, available_balance, pending_balance, reported_at, source, raw)
             VALUES (:acc, :cur, :avail, :pend, NOW(), :src, :raw)'
        );
        $stmt->execute([
            'acc'   => $providerAccountId,
            'cur'   => strtoupper($currency),
            'avail' => bcadd($available, '0', 8),
            'pend'  => bcadd($pending, '0', 8),
            'src'   => in_array($source, ['api', 'webhook', 'statement'], true) ? $source : 'api',
            'raw'   => $raw,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Confronte, pour chaque compte provider de l'environnement, la position
     * ATTENDUE (PROVIDER_ASSET au ledger) au solde OBSERVÉ (provider_balances) :
     *
     *   provider_balances (provider)  vs  PROVIDER_ASSET (Nexus attend)
     *
     * Un écart produit un reconciliation_run 'discrepancy' + un item — JAMAIS
     * de correction automatique (le modèle ne corrige que les statuts, jamais
     * les montants).
     *
     * @return array<string,mixed> Rapport par compte.
     */
    public static function reconcileBalances(string $environment, bool $apply = true): array
    {
        $pdo = Database::getConnection();
        $report = [
            'environment' => $environment,
            'accounts'    => [],
            'matched'     => 0,
            'discrepancy' => 0,
            'errors'      => [],
        ];

        $stmt = $pdo->prepare(
            "SELECT pa.*, (SELECT pb.available_balance FROM provider_balances pb
                            WHERE pb.provider_account_id = pa.id ORDER BY pb.reported_at DESC LIMIT 1) AS observed_available,
                    (SELECT pb.pending_balance FROM provider_balances pb
                            WHERE pb.provider_account_id = pa.id ORDER BY pb.reported_at DESC LIMIT 1) AS observed_pending,
                    (SELECT pb.reported_at FROM provider_balances pb
                            WHERE pb.provider_account_id = pa.id ORDER BY pb.reported_at DESC LIMIT 1) AS observed_at
               FROM provider_accounts pa
              WHERE pa.environment = :env AND pa.status = 'active'
              ORDER BY pa.provider_slug, pa.currency"
        );
        $stmt->execute(['env' => $environment]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($accounts as $account) {
            $expected = ProviderAccountService::expectedAssetBalance(
                (int) $account['id'],
                (string) $account['provider_slug'],
                (string) $account['currency']
            );
            $reported = (string) ($account['observed_available'] ?? '');
            $hasObservation = $reported !== '' && $account['observed_available'] !== null;

            if (!$hasObservation) {
                $report['accounts'][] = [
                    'provider_account_id' => (int) $account['id'],
                    'provider'            => $account['provider_slug'],
                    'currency'            => $account['currency'],
                    'expected'            => $expected,
                    'reported'            => null,
                    'difference'          => null,
                    'status'              => 'unobserved',
                    'note'                => 'Aucune observation de solde provider — réconciliation impossible.',
                ];
                continue;
            }

            $difference = bcsub($expected, $reported, 8);
            $isMatch = bccomp($difference, '0', 8) === 0;
            $status = $isMatch ? 'matched' : 'discrepancy';
            if ($isMatch) {
                $report['matched']++;
            } else {
                $report['discrepancy']++;
            }

            $report['accounts'][] = [
                'provider_account_id' => (int) $account['id'],
                'provider'            => $account['provider_slug'],
                'currency'            => $account['currency'],
                'expected'            => $expected,
                'reported'            => $reported,
                'difference'          => $difference,
                'status'              => $status,
                'observed_at'         => $account['observed_at'],
            ];

            if (!$apply) {
                continue;
            }

            // Traçabilité : reconciliation_runs (une période = un compte).
            $periodStart = date('Y-m-d');
            $stmtRun = $pdo->prepare(
                'INSERT INTO reconciliation_runs
                    (provider_account_id, environment, period_start, period_end,
                     opening_balance, expected_balance, provider_balance, difference, status)
                 VALUES (:acc, :env, :ps, :pe, :open, :exp, :prov, :diff, :status)
                 ON DUPLICATE KEY UPDATE
                     expected_balance = VALUES(expected_balance),
                     provider_balance = VALUES(provider_balance),
                     difference       = VALUES(difference),
                     status           = VALUES(status)'
            );
            $stmtRun->execute([
                'acc'    => (int) $account['id'],
                'env'    => $environment,
                'ps'     => $periodStart,
                'pe'     => $periodStart,
                'open'   => $expected, // ouverture : position attendue en début de période
                'exp'    => $expected,
                'prov'   => $reported,
                'diff'   => $difference,
                'status' => $status,
            ]);

            // Note : les écarts de SOLDE (non liés à une transaction) vivent
            // dans reconciliation_runs — la table reconciliation_items porte
            // un user_id NOT NULL FK (rappel par transaction) et n'est pas
            // adaptée au niveau balance. Le run 'discrepancy' EST l'artefact
            // de traitement humain (jamais auto-résolu).
        }

        return $report;
    }
}
