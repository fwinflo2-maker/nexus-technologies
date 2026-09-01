<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Kyc\SumsubAdapter;
use Nexus\Providers\ProviderConfig;
use PDO;

/**
 * Agrégats des consoles d'exploitation.
 *
 * Les tables qui portent un environnement sont toujours filtrées. Les données
 * globales (utilisateurs, support et projection wallets) sont explicitement
 * signalées comme telles : leur schéma ne permet pas d'inventer un scope.
 */
final class StaffDashboardService
{
    private function __construct()
    {
    }

    /** @return array<string,mixed> */
    public static function dashboard(
        PDO $pdo,
        int $actorId,
        string $role,
        string $dashboard,
        ExecutionEnvironment $environment
    ): array {
        $section = match ($dashboard) {
            'operations' => self::operations($pdo, $environment),
            'finance' => self::finance($pdo, $environment),
            'compliance' => self::compliance($pdo, $environment),
            'risk' => self::risk($pdo, $environment),
            'providers' => self::providers($pdo, $environment),
            'support' => self::support($pdo),
            'technical' => self::technical($pdo, $environment),
            'business' => self::business($pdo, $environment),
            'executive' => [
                'note' => 'Vue exécutive volontairement non sensible. Utilisez les surfaces dédiées selon le besoin d’en connaître.',
                'environment' => $environment->value,
            ],
            default => ['note' => 'Aucune donnée disponible.', 'environment' => $environment->value],
        };

        return [
            'role' => $role,
            'dashboard' => $dashboard,
            'environment' => $environment->value,
            'generated_at' => gmdate(DATE_ATOM),
            'sections' => [$dashboard => $section],
        ];
    }

    /** @return array<string,mixed> */
    public static function webhookEvents(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $provider = $pdo->prepare(
            "SELECT id, 'provider' AS source_type, provider, environment, event_id,
                    event_type, status, received_at AS processed_at
             FROM provider_webhook_events
             WHERE environment = :environment
             ORDER BY received_at DESC
             LIMIT 200"
        );
        $provider->execute(['environment' => $environment->value]);

        $kyc = $pdo->prepare(
            "SELECT id, 'kyc' AS source_type, provider, environment, event_id,
                    NULL AS event_type, status, processed_at
             FROM kyc_webhook_events
             WHERE environment = :environment
             ORDER BY processed_at DESC
             LIMIT 200"
        );
        $kyc->execute(['environment' => $environment->value]);

        $items = array_merge($provider->fetchAll(), $kyc->fetchAll());
        usort($items, static fn (array $a, array $b): int =>
            strcmp((string) $b['processed_at'], (string) $a['processed_at'])
        );
        $items = array_slice($items, 0, 200);

        return [
            'environment' => $environment->value,
            'items' => $items,
            'counters' => self::webhookCounters($pdo, $environment),
        ];
    }

    /** @return array<string,mixed> */
    public static function sourceStatuses(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $cache = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END), 0) AS valid
             FROM fx_rates_cache
             WHERE environment = :environment'
        );
        $cache->execute(['environment' => $environment->value]);
        $cacheRow = $cache->fetch() ?: ['total' => 0, 'valid' => 0];

        return [
            'environment' => $environment->value,
            'fx' => FXSourceStatus::describe() + [
                'cache_entries' => (int) $cacheRow['total'],
                'valid_cache_entries' => (int) $cacheRow['valid'],
            ],
            'sanctions' => SanctionsScreening::describe() + [
                'status' => SanctionsScreening::isConfigured()
                    ? 'CONFIGURED'
                    : SanctionsScreening::UNAVAILABLE,
                'blocks_environment' => !SanctionsScreening::isConfigured()
                    && SanctionsScreening::unavailableBlocks($environment),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function operations(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $queue = $pdo->prepare(
            "SELECT t.id, t.type, t.label, t.amount, t.currency, t.status, t.provider,
                    t.environment, t.execution_time_seconds, t.created_at,
                    u.full_name AS user_name, u.email AS user_email
             FROM transactions t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE t.environment = :environment
               AND t.status IN ('pending','processing')
             ORDER BY t.created_at ASC
             LIMIT 100"
        );
        $queue->execute(['environment' => $environment->value]);

        $stats = $pdo->prepare(
            "SELECT
                COALESCE(SUM(status = 'pending'), 0) AS pending,
                COALESCE(SUM(status = 'processing'), 0) AS processing,
                COALESCE(SUM(status = 'completed'), 0) AS completed,
                COALESCE(SUM(status = 'failed'), 0) AS failed,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN execution_time_seconds END), 0) AS avg_seconds
             FROM transactions
             WHERE environment = :environment"
        );
        $stats->execute(['environment' => $environment->value]);
        $row = $stats->fetch() ?: [];

        return [
            'environment' => $environment->value,
            'counters' => [
                'pending' => (int) ($row['pending'] ?? 0),
                'processing' => (int) ($row['processing'] ?? 0),
                'completed' => (int) ($row['completed'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
            ],
            'avg_execution_seconds' => (float) ($row['avg_seconds'] ?? 0),
            'queue' => $queue->fetchAll(),
            // Les transitions de transactions passent exclusivement par la saga.
            'actions' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function finance(PDO $pdo, ExecutionEnvironment $environment): array
    {
        // wallets n'a pas de colonne environment. Cette projection est globale,
        // mais représente bien les actifs DISPONIBLES, jamais balance.
        $assets = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN currency = 'EUR' THEN available_balance ELSE 0 END), 0) AS EUR,
                COALESCE(SUM(CASE WHEN currency = 'USD' THEN available_balance ELSE 0 END), 0) AS USD,
                COALESCE(SUM(CASE WHEN currency = 'XAF' THEN available_balance ELSE 0 END), 0) AS XAF
             FROM wallets"
        )->fetch();

        $tx = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount_xaf ELSE 0 END), 0) AS volume_xaf,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN fee ELSE 0 END), 0) AS fees
             FROM transactions WHERE environment = :environment"
        );
        $tx->execute(['environment' => $environment->value]);
        $transactions = $tx->fetch() ?: [];

        $breakdown = $pdo->prepare(
            'SELECT status, COUNT(*) AS n
             FROM transactions WHERE environment = :environment
             GROUP BY status ORDER BY status'
        );
        $breakdown->execute(['environment' => $environment->value]);

        return [
            'environment' => $environment->value,
            'assets' => $assets,
            'assets_basis' => 'available_balance',
            'assets_scope' => 'shared_wallet_projection',
            'transactions' => [
                'total' => (int) ($transactions['total'] ?? 0),
                'volume_xaf' => (float) ($transactions['volume_xaf'] ?? 0),
                'fees' => (float) ($transactions['fees'] ?? 0),
            ],
            'status_breakdown' => array_map(
                static fn (array $row): array => ['status' => $row['status'], 'n' => (int) $row['n']],
                $breakdown->fetchAll()
            ),
            'sources' => self::sourceStatuses($pdo, $environment),
            'actions' => ['fx_check'],
        ];
    }

    /** @return array<string,mixed> */
    private static function compliance(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $pending = $pdo->prepare(
            "SELECT k.id, k.user_id, u.full_name, u.email, k.subject_type, k.status,
                    k.reason, k.created_at, k.updated_at
             FROM kyc_verifications k
             JOIN users u ON u.id = k.user_id
             WHERE k.environment = :environment
               AND k.status IN ('in_progress','pending','resubmission_requested','on_hold')
             ORDER BY k.created_at ASC
             LIMIT 100"
        );
        $pending->execute(['environment' => $environment->value]);

        $counts = $pdo->prepare(
            'SELECT subject_type, status, COUNT(*) AS n
             FROM kyc_verifications
             WHERE environment = :environment
             GROUP BY subject_type, status'
        );
        $counts->execute(['environment' => $environment->value]);
        $counters = ['individual' => [], 'company' => [], 'total' => 0];
        foreach ($counts->fetchAll() as $row) {
            $type = (string) $row['subject_type'];
            $n = (int) $row['n'];
            $counters[$type][(string) $row['status']] = $n;
            $counters['total'] += $n;
        }

        return [
            'environment' => $environment->value,
            'counters' => $counters,
            'pending' => $pending->fetchAll(),
            'sanctions' => self::sourceStatuses($pdo, $environment)['sanctions'],
            'actions' => ['kyc_approve', 'kyc_reject', 'kyc_resubmission'],
        ];
    }

    /** @return array<string,mixed> */
    private static function risk(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $txStats = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'failed'), 0) AS failed
             FROM transactions WHERE environment = :environment"
        );
        $txStats->execute(['environment' => $environment->value]);
        $tx = $txStats->fetch() ?: ['total' => 0, 'failed' => 0];

        $kyc = $pdo->prepare(
            "SELECT
                COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                COALESCE(SUM(status = 'resubmission_requested'), 0) AS resubmission
             FROM kyc_verifications WHERE environment = :environment"
        );
        $kyc->execute(['environment' => $environment->value]);
        $kycRow = $kyc->fetch() ?: [];

        $recent = $pdo->prepare(
            "SELECT t.id, t.label, t.amount, t.currency, t.provider, t.created_at,
                    u.email AS user_email
             FROM transactions t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE t.environment = :environment AND t.status = 'failed'
             ORDER BY t.created_at DESC LIMIT 15"
        );
        $recent->execute(['environment' => $environment->value]);

        $provider = $pdo->prepare(
            "SELECT provider, COUNT(*) AS n,
                    COALESCE(SUM(status = 'failed'), 0) AS fails
             FROM transactions
             WHERE environment = :environment AND provider IS NOT NULL
             GROUP BY provider ORDER BY n DESC LIMIT 8"
        );
        $provider->execute(['environment' => $environment->value]);
        $byProvider = array_map(static function (array $row): array {
            $n = (int) $row['n'];
            $fails = (int) $row['fails'];
            return [
                'provider' => $row['provider'],
                'n' => $n,
                'fails' => $fails,
                'fail_rate' => $n > 0 ? round($fails / $n * 100, 1) : 0.0,
            ];
        }, $provider->fetchAll());

        $flagged = $pdo->query(
            "SELECT id, full_name, email, status, risk_level
             FROM users
             WHERE COALESCE(platform_role, 'user') = 'user'
               AND (status = 'SUSPENDED' OR risk_level IN ('medium','high'))
             ORDER BY FIELD(risk_level, 'high', 'medium', 'low'), id DESC
             LIMIT 100"
        )->fetchAll();

        $total = (int) $tx['total'];
        $failed = (int) $tx['failed'];
        return [
            'environment' => $environment->value,
            'risk' => [
                'suspended_accounts' => self::scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'SUSPENDED' AND COALESCE(platform_role,'user') = 'user'"),
                'failed_transactions' => $failed,
                'kyc_rejected' => (int) ($kycRow['rejected'] ?? 0),
                'kyc_resubmission' => (int) ($kycRow['resubmission'] ?? 0),
                'failed_rate' => $total > 0 ? round($failed / $total * 100, 1) : 0.0,
            ],
            'flagged' => $flagged,
            'account_risk_scope' => 'global',
            'recent_failed' => $recent->fetchAll(),
            'by_provider' => $byProvider,
            'actions' => ['suspend', 'unsuspend', 'risk_level'],
        ];
    }

    /** @return array<string,mixed> */
    private static function providers(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $catalog = ProviderCatalog::all();
        $enabled = 0;
        foreach (array_keys($catalog) as $slug) {
            if (ProviderConfig::isEnabled($slug)) {
                $enabled++;
            }
        }

        $credentials = self::credentials($pdo, $environment);
        $configured = count(array_filter(
            $credentials,
            static fn (array $row): bool => $row['configured'] === true
        ));

        return [
            'environment' => $environment->value,
            'providers' => [
                'total' => count($catalog),
                'enabled' => $enabled,
                'configured' => $configured,
            ],
            'credentials' => $credentials,
            // Les tests de connexion restent sur la route credentials dédiée.
            'actions' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function support(PDO $pdo): array
    {
        $recent = $pdo->query(
            "SELECT c.id, c.subject, c.category, c.status, c.priority, c.assigned_to,
                    c.created_at, c.updated_at, u.full_name, u.email
             FROM support_conversations c
             JOIN users u ON u.id = c.user_id
             WHERE c.status IN ('open','waiting')
             ORDER BY c.updated_at DESC LIMIT 100"
        )->fetchAll();

        $counts = $pdo->query(
            'SELECT status, COUNT(*) AS n FROM support_conversations GROUP BY status'
        )->fetchAll();
        $counters = ['open' => 0, 'waiting' => 0, 'resolved' => 0, 'closed' => 0];
        foreach ($counts as $row) {
            if (array_key_exists((string) $row['status'], $counters)) {
                $counters[(string) $row['status']] = (int) $row['n'];
            }
        }

        $specialists = $pdo->query(
            "SELECT u.id, u.full_name, u.platform_role, e.department
             FROM users u
             LEFT JOIN employees e ON e.user_id = u.id
             WHERE u.status = 'ACTIVE' AND COALESCE(u.platform_role, 'user') <> 'user'
             ORDER BY u.full_name"
        )->fetchAll();

        return [
            'environment_scope' => 'global',
            'counters' => $counters,
            'recent' => $recent,
            'specialists' => array_map(static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['dashboard'] = \Nexus\Execution\PlatformRole::dashboardOf($row);
                return $row;
            }, $specialists),
            'actions' => ['ticket_assign', 'ticket_status', 'ticket_escalate'],
        ];
    }

    /** @return array<string,mixed> */
    private static function technical(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $dbOk = true;
        try {
            $pdo->query('SELECT 1')->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[NEXUS] StaffDashboardService technical db check degraded: ' . $e->getMessage());
            $dbOk = false;
        }

        $kyc = new SumsubAdapter();
        $kycAvailable = $kyc->isConfigured() && $kyc->environment() === $environment->value;
        $pending = self::preparedScalar(
            $pdo,
            "SELECT COUNT(*) FROM transactions WHERE environment = :environment AND status IN ('pending','processing')",
            ['environment' => $environment->value]
        );

        return [
            'environment' => $environment->value,
            'services' => [
                ['name' => 'API REST', 'status' => 'operational', 'latency_ms' => null],
                ['name' => 'Base de données', 'status' => $dbOk ? 'operational' : 'down', 'latency_ms' => null],
                ['name' => 'File de transactions', 'status' => $dbOk ? 'operational' : 'unknown', 'latency_ms' => null, 'pending' => $pending],
                ['name' => 'KYC (SumSub)', 'status' => $kycAvailable ? 'configured' : 'unavailable', 'latency_ms' => null],
            ],
            'db_ok' => $dbOk,
            'webhooks' => self::webhookCounters($pdo, $environment),
            'credentials' => self::credentials($pdo, $environment),
            'sources' => self::sourceStatuses($pdo, $environment),
            'actions' => ['service_check'],
        ];
    }

    /** @return array<string,mixed> */
    private static function business(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $accounts = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(kyb_status = 'verified'), 0) AS verified,
                    COALESCE(SUM(status = 'ACTIVE'), 0) AS active,
                    COALESCE(SUM(COALESCE(kyb_status, 'none') IN ('pending','in_progress')), 0) AS pending
             FROM users
             WHERE account_type = 'business' AND COALESCE(platform_role, 'user') = 'user'"
        )->fetch() ?: [];

        $top = $pdo->prepare(
            "SELECT u.id, u.full_name, u.email, u.status, u.kyb_status,
                    COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.amount_xaf ELSE 0 END), 0) AS volume
             FROM users u
             LEFT JOIN transactions t
               ON t.user_id = u.id AND t.environment = :environment
             WHERE u.account_type = 'business' AND COALESCE(u.platform_role, 'user') = 'user'
             GROUP BY u.id
             ORDER BY volume DESC, u.id DESC
             LIMIT 100"
        );
        $top->execute(['environment' => $environment->value]);
        $items = $top->fetchAll();

        return [
            'environment' => $environment->value,
            'accounts' => [
                'total' => (int) ($accounts['total'] ?? 0),
                'verified' => (int) ($accounts['verified'] ?? 0),
                'active' => (int) ($accounts['active'] ?? 0),
                'pending' => (int) ($accounts['pending'] ?? 0),
            ],
            'accounts_scope' => 'global',
            'volume_xaf' => array_sum(array_map(static fn (array $row): float => (float) $row['volume'], $items)),
            'top' => $items,
            'actions' => ['kyb_approve', 'kyb_reject'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function credentials(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $stmt = $pdo->prepare(
            "SELECT provider_slug, environment,
                    CASE
                        WHEN credentials_enc IS NULL OR credentials_enc = '' THEN 'not_configured'
                        WHEN status = 'error' THEN 'error'
                        WHEN last_tested_at IS NULL THEN 'unknown'
                        ELSE status
                    END AS state,
                    credentials_enc IS NOT NULL AND credentials_enc <> '' AS configured,
                    last_tested_at, last_error, updated_at
             FROM provider_credentials
             WHERE user_id IS NULL AND environment = :environment
             ORDER BY provider_slug"
        );
        $stmt->execute(['environment' => $environment->value]);
        return array_map(static function (array $row): array {
            $row['configured'] = (bool) $row['configured'];
            return $row;
        }, $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    private static function webhookCounters(PDO $pdo, ExecutionEnvironment $environment): array
    {
        $provider = self::preparedScalar(
            $pdo,
            'SELECT COUNT(*) FROM provider_webhook_events WHERE environment = :environment',
            ['environment' => $environment->value]
        );
        $kyc = self::preparedScalar(
            $pdo,
            'SELECT COUNT(*) FROM kyc_webhook_events WHERE environment = :environment',
            ['environment' => $environment->value]
        );
        return [
            'processed_total' => $provider + $kyc,
            'provider_events' => $provider,
            'kyc_events' => $kyc,
        ];
    }

    private static function scalar(PDO $pdo, string $sql): int
    {
        return (int) $pdo->query($sql)->fetchColumn();
    }

    /** @param array<string,mixed> $params */
    private static function preparedScalar(PDO $pdo, string $sql, array $params): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
