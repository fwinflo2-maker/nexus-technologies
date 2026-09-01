<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Providers\CashrampAdapter;
use Nexus\Providers\ProviderRegistry;
use PDO;

/**
 * Politique commerciale Nexus pour la création de cartes virtuelles Cashramp.
 *
 * NEXUS_CARD_CREATION_MINIMUM_USD = 1.00 (configurable Admin, pas une exigence Cashramp).
 */
final class CashrampCardCreationPolicyService
{
    public const DEFAULT_MINIMUM_USD = '1.00';
    private const CONFIG_KEY = 'card_creation_policy';

    private function __construct()
    {
    }

    /** @return array<string,mixed> */
    public static function get(PDO $pdo, string $environment): array
    {
        $row = self::loadRow($pdo, $environment);
        $value = is_array($row) ? ($row['config_json'] ?? []) : [];

        return [
            'minimum_usd'                 => (string) ($value['minimum_usd'] ?? self::DEFAULT_MINIMUM_USD),
            'funding_provider'            => (string) ($value['funding_provider'] ?? 'cashramp'),
            'business_cashramp_account_id'=> (string) ($value['business_cashramp_account_id'] ?? ''),
            'status'                      => ($value['business_cashramp_account_id'] ?? '') !== ''
                ? 'CONFIGURED'
                : 'NOT CONFIGURED',
        ];
    }

    /**
     * @param array{minimum_usd?:string,business_cashramp_account_id?:string,funding_provider?:string} $input
     */
    public static function upsert(PDO $pdo, string $environment, array $input): array
    {
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new HttpException(422, 'Environnement invalide.', 'INVALID_ENVIRONMENT');
        }

        $minimum = (string) ($input['minimum_usd'] ?? self::DEFAULT_MINIMUM_USD);
        if (bccomp($minimum, '0', 2) <= 0) {
            throw new HttpException(422, 'Le minimum de création de carte doit être positif.', 'INVALID_MINIMUM');
        }

        $payload = [
            'minimum_usd'                  => $minimum,
            'funding_provider'             => (string) ($input['funding_provider'] ?? 'cashramp'),
            'business_cashramp_account_id' => trim((string) ($input['business_cashramp_account_id'] ?? '')),
        ];

        self::ensureTableExists($pdo);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare(
            'INSERT INTO provider_platform_config (provider_slug, environment, config_key, config_json)
             VALUES (:slug, :env, :key, :json)
             ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'slug' => 'cashramp',
            'env'  => $environment,
            'key'  => self::CONFIG_KEY,
            'json' => $json,
        ]);

        return self::get($pdo, $environment);
    }

    /** @return array<string,mixed>|null */
    private static function loadRow(PDO $pdo, string $environment): ?array
    {
        try {
            self::ensureTableExists($pdo);
            $stmt = $pdo->prepare(
                'SELECT config_json FROM provider_platform_config
                 WHERE provider_slug = :slug AND environment = :env AND config_key = :key LIMIT 1'
            );
            $stmt->execute(['slug' => 'cashramp', 'env' => $environment, 'key' => self::CONFIG_KEY]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            $decoded = json_decode((string) ($row['config_json'] ?? ''), true);

            return is_array($decoded) ? ['config_json' => $decoded] : null;
        } catch (\Throwable $e) {
            error_log('[NEXUS] CashrampCardCreationPolicyService loadRow degraded: ' . $e->getMessage());
            return null;
        }
    }

    private static function ensureTableExists(PDO $pdo): void
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS provider_platform_config (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider_slug VARCHAR(50) NOT NULL,
                environment ENUM('sandbox','production') NOT NULL,
                config_key VARCHAR(100) NOT NULL,
                config_json JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_provider_platform_config (provider_slug, environment, config_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            error_log('[NEXUS] CashrampCardCreationPolicyService ensureTableExists degraded: ' . $e->getMessage());
        }
    }
}
