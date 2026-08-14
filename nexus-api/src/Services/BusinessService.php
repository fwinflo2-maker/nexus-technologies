<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Currency;
use Nexus\Core\Crypto;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use PDO;
use Throwable;

/**
 * BusinessService — logique métier Business : RBAC, bénéficiaires, paiements,
 * rapprochement et agrégats (trésorerie, analytics).
 *
 * Sécurité : chaque action vérifie le rôle de l'acteur côté backend.
 * Le frontend ne peut jamais contourner une approbation.
 */
final class BusinessService
{
    public const ROLES = ['owner', 'admin', 'finance_manager', 'accountant', 'operator', 'viewer'];

    private const CAN_MANAGE_TEAM    = ['owner', 'admin'];
    private const CAN_WRITE_BENEF    = ['owner', 'admin', 'finance_manager', 'operator'];
    private const CAN_VERIFY_BENEF   = ['owner', 'admin', 'finance_manager'];
    private const CAN_CREATE_PAYMENT = ['owner', 'admin', 'finance_manager', 'operator'];
    private const CAN_APPROVE        = ['owner', 'admin', 'finance_manager'];
    private const CAN_EXECUTE        = ['owner', 'admin', 'finance_manager'];
    private const CAN_RECONCILE      = ['owner', 'admin', 'finance_manager', 'accountant'];

    // ──────────────────────────────────────────────────────────────────────
    // RBAC
    // ──────────────────────────────────────────────────────────────────────

    /** Résout le rôle effectif de l'acteur sur une entité Business. */
    public static function roleOf(int $businessUserId, int $actorUserId): string
    {
        if ($businessUserId === $actorUserId) {
            return 'owner';
        }
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("SELECT role FROM team_members WHERE business_user_id = :b AND member_user_id = :m AND status = 'active' LIMIT 1");
        $stmt->execute(['b' => $businessUserId, 'm' => $actorUserId]);
        $role = $stmt->fetchColumn();
        return $role === false ? 'none' : (string) $role;
    }

    /**
     * Vérifie que l'acteur possède l'un des rôles requis (sinon 403).
     *
     * @param list<string> $allowedRoles
     */
    public static function requireRole(int $businessUserId, int $actorUserId, array $allowedRoles, string $action): void
    {
        $role = self::roleOf($businessUserId, $actorUserId);
        if (!in_array($role, $allowedRoles, true)) {
            throw new HttpException(
                403,
                sprintf('Permission refusée : votre rôle (%s) ne permet pas « %s ».', $role, $action),
                'FORBIDDEN_ROLE'
            );
        }
    }

    /** Résout l'identifiant Business à partir de l'acteur authentifié. */
    public static function resolveBusinessUserId(array $actor, mixed $businessIdInput): int
    {
        $actorId = (int) $actor['id'];
        if (($actor['account_type'] ?? '') === 'business') {
            return $actorId;
        }
        $businessId = (int) ($businessIdInput ?? 0);
        if ($businessId <= 0) {
            throw new HttpException(400, 'Paramètre business_id requis.', 'BUSINESS_ID_REQUIRED');
        }
        return $businessId;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Paiements
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Calcule le quote d'un paiement (pipeline réel Capability→Policy→Quote→Routing).
     *
     * @return array{intent: array<string,mixed>, routes: list<array<string,mixed>>, best: array<string,mixed>}
     */
    public static function quotePayment(int $businessUserId, array $beneficiary, array $draft): array
    {
        $intent = IntentParser::parse([
            'amount'          => (float) ($draft['amount'] ?? 0),
            'sourceCurrency'  => (string) ($draft['source_currency'] ?? ''),
            'destCountry'     => (string) $beneficiary['country'],
            'destCurrency'    => (string) ($draft['dest_currency'] ?? $beneficiary['currency']),
            'receivingMethod' => (string) $beneficiary['method'],
            'objective'       => (string) ($draft['objective'] ?? 'optimized'),
        ]);

        $businessUser = self::loadUser($businessUserId);
        $routes       = QuoteService::computeRoutes($businessUser, $intent);

        return [
            'intent' => $intent,
            'routes' => $routes,
            'best'   => $routes[0] ?? [],
        ];
    }

    /**
     * Exécute un paiement approuvé via la saga déterministe.
     *
     * @return array<string,mixed> La transaction enregistrée.
     */
    public static function executePayment(int $businessUserId, array $payment, array $beneficiary): array
    {
        $spec = [
            'source_currency' => (string) $payment['source_currency'],
            'dest_currency'   => (string) $payment['dest_currency'],
            'amount'          => (string) $payment['amount'],
            'fee'             => (string) $payment['fee'],
            'dest_amount'     => (float) $payment['dest_amount'],
            'fx_rate'         => $payment['fx_rate'] !== null ? (float) $payment['fx_rate'] : 0.0,
            'provider'        => (string) ($payment['provider'] ?? ''),
            'route_id'        => (string) ($payment['route_id'] ?? ''),
            'destination'     => self::paymentDestination($beneficiary),
            'label'           => sprintf('Paiement — %s', $beneficiary['name'] ?? 'Bénéficiaire'),
            'type'            => 'send',
            'metadata'        => ['payment_id' => (int) $payment['id']],
        ];

        $idemKey = 'payment:' . (int) $payment['id'] . ':execute';
        return ExecutionEngine::executeTransfer($businessUserId, $spec, $idemKey);
    }

    /** Libellé de destination d'un paiement (bénéficiaire + méthode). */
    public static function paymentDestination(array $beneficiary): string
    {
        $methodLabels = [
            'mobile_money' => 'Mobile Money',
            'bank'         => 'Banque',
            'crypto'       => 'Crypto',
            'cash_pickup'  => 'Espèces',
        ];
        $method = strtolower((string) ($beneficiary['method'] ?? 'mobile_money'));
        return substr(strtoupper((string) ($beneficiary['country'] ?? '')) . ' · ' . ($methodLabels[$method] ?? $method), 0, 190);
    }

    /** Statut de rapprochement calculé pour une transaction. */
    public static function reconciliationStatus(?array $item, array $tx): string
    {
        if ($item === null) {
            return 'pending';
        }
        return (string) $item['status'];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Agrégats (trésorerie / analytics / overview)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed> Overview Business : actifs, flux, KPIs, exposure FX.
     */
    public static function overview(int $businessUserId): array
    {
        $pdo      = Database::getConnection();
        $balances = WalletService::getAllBalances($businessUserId);

        $totalRef = 0.0; $availableRef = 0.0; $pendingRef = 0.0;
        $inTransitRef = 0.0; $settlementRef = 0.0;
        $wallets = [];
        foreach ($balances as $w) {
            $rate = Currency::rateToRef((string) $w['currency']);
            $totalRef      += (float) $w['balance'] * $rate;
            $availableRef  += (float) $w['available_balance'] * $rate;
            $pendingRef    += (float) $w['pending_balance'] * $rate;
            $inTransitRef  += (float) $w['in_transit_balance'] * $rate;
            $settlementRef += (float) $w['settlement_balance'] * $rate;
            $wallets[] = [
                'currency'   => (string) $w['currency'],
                'balance'    => (float) $w['balance'],
                'available'  => (float) $w['available_balance'],
                'pending'    => (float) $w['pending_balance'],
                'in_transit' => (float) $w['in_transit_balance'],
                'settlement' => (float) $w['settlement_balance'],
                'ref_value'  => round((float) $w['balance'] * $rate, 2),
            ];
        }

        $since = date('Y-m-d 00:00:00', strtotime('-29 days'));

        // Volume + frais + taux de réussite (30 jours).
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_xaf),0), COALESCE(SUM(status='completed'),0),
                    COALESCE(SUM(status='failed'),0), COALESCE(SUM(status='cancelled'),0)
             FROM transactions WHERE user_id = :uid AND created_at >= :since"
        );
        $stmt->execute(['uid' => $businessUserId, 'since' => $since]);
        [$volumeXaf, $completed, $failed, $cancelled] = array_map('floatval', $stmt->fetch(PDO::FETCH_NUM));
        $executed = $completed + $failed + $cancelled;
        $successRate = $executed > 0 ? round($completed / $executed * 100, 1) : 0.0;

        // Frais totaux (ref EUR).
        $stmt = $pdo->prepare(
            "SELECT fee, fee_currency FROM transactions WHERE user_id = :uid AND status <> 'cancelled' AND created_at >= :since"
        );
        $stmt->execute(['uid' => $businessUserId, 'since' => $since]);
        $feesRef = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $feesRef += (float) $row['fee'] * Currency::rateToRef((string) $row['fee_currency']);
        }

        // Temps moyen d'exécution.
        $stmt = $pdo->prepare(
            "SELECT COALESCE(AVG(execution_time_seconds),0) FROM transactions WHERE user_id = :uid AND status = 'completed' AND execution_time_seconds IS NOT NULL AND created_at >= :since"
        );
        $stmt->execute(['uid' => $businessUserId, 'since' => $since]);
        $avgExec = (float) $stmt->fetchColumn();

        // Payables (paiements en attente d'approbation / approuvés non réglés).
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_ref),0) FROM payments WHERE user_id = :uid AND status IN ('pending_approval','approved','executing')"
        );
        $stmt->execute(['uid' => $businessUserId]);
        $payablesRef = round((float) $stmt->fetchColumn(), 2);

        return [
            'totals' => [
                'total_assets'   => round($totalRef, 2),
                'available'      => round($availableRef, 2),
                'pending'        => round($pendingRef, 2),
                'in_transit'     => round($inTransitRef, 2),
                'settlement'     => round($settlementRef, 2),
                'receivables'    => 0.0, // pas encore de flux entrants Business réels
                'payables'       => $payablesRef,
                'volume_xaf'     => round($volumeXaf, 2),
                'fees_ref'       => round($feesRef, 2),
                'success_rate'   => $successRate,
                'avg_exec_sec'   => $avgExec > 0 ? (int) round($avgExec) : null,
                'ref_currency'   => Currency::REF,
            ],
            'wallets' => $wallets,
            'cash_flow' => self::cashFlowSeries($pdo, $businessUserId),
            'providers' => self::providerBreakdown($pdo, $businessUserId),
        ];
    }

    /** @return list<array<string,mixed>> Flux entrants/sortants par jour (30 j). */
    private static function cashFlowSeries(PDO $pdo, int $businessUserId): array
    {
        $since = date('Y-m-d', strtotime('-29 days'));
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS d,
                    COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_ref ELSE 0 END),0) AS inflow,
                    COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_ref ELSE 0 END),0) AS outflow
             FROM transactions
             WHERE user_id = :uid AND created_at >= :since AND status <> 'cancelled'
             GROUP BY DATE(created_at) ORDER BY d ASC"
        );
        $stmt->execute(['uid' => $businessUserId, 'since' => $since . ' 00:00:00']);
        $series = [];
        foreach ($stmt->fetchAll() as $row) {
            $series[] = [
                'date'    => (string) $row['d'],
                'inflow'  => round((float) $row['inflow'], 2),
                'outflow' => round((float) $row['outflow'], 2),
            ];
        }
        return $series;
    }

    /** @return list<array<string,mixed>> Volume / réussite par provider (30 j). */
    private static function providerBreakdown(PDO $pdo, int $businessUserId): array
    {
        $since = date('Y-m-d 00:00:00', strtotime('-29 days'));
        $stmt = $pdo->prepare(
            "SELECT provider,
                    COUNT(*) AS n,
                    COALESCE(SUM(amount_xaf),0) AS volume,
                    COALESCE(SUM(status='completed'),0) AS completed
             FROM transactions
             WHERE user_id = :uid AND created_at >= :since AND provider IS NOT NULL
             GROUP BY provider ORDER BY volume DESC"
        );
        $stmt->execute(['uid' => $businessUserId, 'since' => $since]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $n = (int) $row['n'];
            $out[] = [
                'provider'     => (string) $row['provider'],
                'transactions' => $n,
                'volume_xaf'   => round((float) $row['volume'], 2),
                'success_rate' => $n > 0 ? round(((int) $row['completed'] / $n) * 100, 1) : 0.0,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function loadUser(int $userId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(404, 'Utilisateur introuvable.', 'USER_NOT_FOUND');
        }
        return $row;
    }
}
