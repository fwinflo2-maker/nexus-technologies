<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * ProviderCustomerService — mapping utilisateur Nexus → customer provider.
 *
 * Un customer provider est l'identité chez le partenaire (Cashramp, Stripe, …).
 * Le service est provider-agnostic : le provisioning spécifique est injecté via
 * callable ou adaptateur futur, jamais via des branches `if ($slug === …)`.
 *
 * Isolation environnement :
 *   (user_id, provider_slug, sandbox) et (user_id, provider_slug, production)
 *   coexistent ; jamais de repli sandbox → production.
 *
 * Idempotence :
 *   UNIQUE(user_id, provider_slug, environment) + transaction + gestion 23000.
 */
final class ProviderCustomerService
{
    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_ACTIVE    = 'ACTIVE';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_FAILED    = 'FAILED';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_FAILED,
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getCustomer(int $userId, string $providerSlug, string $environment): ?array
    {
        self::assertUserId($userId);
        $providerSlug = self::normalizeSlug($providerSlug);
        $environment  = self::normalizeEnvironment($environment);

        $pdo = Database::getConnection();
        $row = self::findRow($pdo, $userId, $providerSlug, $environment);

        return $row === null ? null : self::formatRow($row);
    }

    /**
     * @param array{provider_customer_id?:string,id?:string,customer_id?:string,status?:string,metadata?:array<string,mixed>|null} $providerData
     * @return array<string,mixed>
     */
    public static function createCustomer(
        int $userId,
        string $providerSlug,
        string $environment,
        array $providerData
    ): array {
        self::assertUserId($userId);
        $providerSlug = self::normalizeSlug($providerSlug);
        $environment  = self::normalizeEnvironment($environment);

        $pdo = Database::getConnection();
        if (self::findRow($pdo, $userId, $providerSlug, $environment) !== null) {
            throw new HttpException(
                409,
                'Un customer provider existe déjà pour cet utilisateur, ce provider et cet environnement.',
                'PROVIDER_CUSTOMER_EXISTS'
            );
        }

        return self::insertCustomer($pdo, $userId, $providerSlug, $environment, $providerData);
    }

    /**
     * @param callable():array{provider_customer_id?:string,id?:string,customer_id?:string,status?:string,metadata?:array<string,mixed>|null} $provisioner
     * @return array<string,mixed>
     */
    public static function getOrCreateCustomer(
        int $userId,
        string $providerSlug,
        string $environment,
        callable $provisioner
    ): array {
        self::assertUserId($userId);
        $providerSlug = self::normalizeSlug($providerSlug);
        $environment  = self::normalizeEnvironment($environment);

        $existing = self::getCustomer($userId, $providerSlug, $environment);
        if ($existing !== null) {
            return $existing;
        }

        $pdo = Database::getConnection();

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $locked = self::findRowForUpdate($pdo, $userId, $providerSlug, $environment);
            if ($locked !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return self::formatRow($locked);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $providerData = $provisioner();

        $pdo->beginTransaction();
        try {
            $locked = self::findRowForUpdate($pdo, $userId, $providerSlug, $environment);
            if ($locked !== null) {
                $pdo->commit();

                return self::formatRow($locked);
            }

            $created = self::insertCustomer($pdo, $userId, $providerSlug, $environment, $providerData);
            $pdo->commit();

            return $created;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (self::isDuplicateKey($e)) {
                $existing = self::getCustomer($userId, $providerSlug, $environment);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * @param array{provider_customer_id?:string,id?:string,customer_id?:string,status?:string,metadata?:array<string,mixed>|null} $providerData
     * @return array<string,mixed>
     */
    public static function syncCustomer(
        int $userId,
        string $providerSlug,
        string $environment,
        array $providerData
    ): array {
        self::assertUserId($userId);
        $providerSlug = self::normalizeSlug($providerSlug);
        $environment  = self::normalizeEnvironment($environment);

        $pdo = Database::getConnection();
        $existing = self::findRow($pdo, $userId, $providerSlug, $environment);
        if ($existing === null) {
            throw new HttpException(
                404,
                'Aucun customer provider à synchroniser pour cet utilisateur.',
                'PROVIDER_CUSTOMER_NOT_FOUND'
            );
        }

        $providerCustomerId = self::extractProviderCustomerId($providerData);
        $status             = self::normalizeStatus($providerData['status'] ?? $existing['status']);
        $metadata           = self::encodeMetadata($providerData['metadata'] ?? self::decodeMetadata($existing['metadata'] ?? null));

        $stmt = $pdo->prepare(
            'UPDATE provider_customers
                SET provider_customer_id = :pcid,
                    status = :status,
                    metadata = :metadata,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );
        $stmt->execute([
            'pcid'   => $providerCustomerId,
            'status' => $status,
            'metadata' => $metadata,
            'id'     => (int) $existing['id'],
        ]);

        $updated = self::findRowById($pdo, (int) $existing['id']);

        return self::formatRow($updated ?? $existing);
    }

    /**
     * @return array<string,mixed>
     */
    private static function insertCustomer(
        PDO $pdo,
        int $userId,
        string $providerSlug,
        string $environment,
        array $providerData
    ): array {
        $providerCustomerId = self::extractProviderCustomerId($providerData);
        $status             = self::normalizeStatus($providerData['status'] ?? self::STATUS_PENDING);
        $metadata           = self::encodeMetadata($providerData['metadata'] ?? null);

        $stmt = $pdo->prepare(
            'INSERT INTO provider_customers
                (user_id, provider_slug, provider_customer_id, environment, status, metadata)
             VALUES
                (:uid, :slug, :pcid, :env, :status, :metadata)'
        );
        $stmt->execute([
            'uid'      => $userId,
            'slug'     => $providerSlug,
            'pcid'     => $providerCustomerId,
            'env'      => $environment,
            'status'   => $status,
            'metadata' => $metadata,
        ]);

        $row = self::findRowById($pdo, (int) $pdo->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Échec de lecture du customer provider créé.');
        }

        return self::formatRow($row);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findRow(PDO $pdo, int $userId, string $providerSlug, string $environment): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, provider_slug, provider_customer_id, environment,
                    status, metadata, created_at, updated_at
               FROM provider_customers
              WHERE user_id = :uid
                AND provider_slug = :slug
                AND environment = :env
              LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'slug' => $providerSlug, 'env' => $environment]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findRowForUpdate(PDO $pdo, int $userId, string $providerSlug, string $environment): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, provider_slug, provider_customer_id, environment,
                    status, metadata, created_at, updated_at
               FROM provider_customers
              WHERE user_id = :uid
                AND provider_slug = :slug
                AND environment = :env
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute(['uid' => $userId, 'slug' => $providerSlug, 'env' => $environment]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findRowById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, provider_slug, provider_customer_id, environment,
                    status, metadata, created_at, updated_at
               FROM provider_customers
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function formatRow(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'user_id'              => (int) $row['user_id'],
            'provider_slug'        => (string) $row['provider_slug'],
            'provider_customer_id' => (string) $row['provider_customer_id'],
            'environment'          => (string) $row['environment'],
            'status'               => (string) $row['status'],
            'metadata'             => self::decodeMetadata($row['metadata'] ?? null),
            'created_at'           => (string) $row['created_at'],
            'updated_at'           => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array{provider_customer_id?:string,id?:string,customer_id?:string} $providerData
     */
    private static function extractProviderCustomerId(array $providerData): string
    {
        foreach (['provider_customer_id', 'id', 'customer_id'] as $key) {
            $value = trim((string) ($providerData[$key] ?? ''));
            if ($value !== '') {
                return substr($value, 0, 191);
            }
        }

        throw new HttpException(
            422,
            'Identifiant customer provider manquant (provider_customer_id).',
            'PROVIDER_CUSTOMER_ID_REQUIRED'
        );
    }

    private static function normalizeStatus(mixed $status): string
    {
        $normalized = strtoupper(trim((string) $status));
        if (!in_array($normalized, self::STATUSES, true)) {
            throw new HttpException(422, 'Statut customer provider invalide.', 'INVALID_PROVIDER_CUSTOMER_STATUS');
        }

        return $normalized;
    }

    private static function normalizeEnvironment(string $environment): string
    {
        $normalized = ProviderCredentialService::normalizeEnvironment($environment);
        if ($normalized === null) {
            throw new HttpException(422, 'Environnement invalide.', 'INVALID_ENVIRONMENT');
        }

        return $normalized;
    }

    private static function normalizeSlug(string $providerSlug): string
    {
        $slug = strtolower(trim($providerSlug));
        if ($slug === '' || strlen($slug) > 50) {
            throw new HttpException(422, 'Slug provider invalide.', 'INVALID_PROVIDER_SLUG');
        }
        if (!ProviderCatalog::exists($slug)) {
            throw new HttpException(404, 'Provider inconnu : ' . $slug . '.', 'PROVIDER_UNKNOWN');
        }

        return $slug;
    }

    private static function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new HttpException(422, 'Identifiant utilisateur invalide.', 'INVALID_USER_ID');
        }
    }

    /**
     * @param array<string,mixed>|null $metadata
     */
    private static function encodeMetadata(?array $metadata): ?string
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }

        self::assertMetadataSafe($metadata);

        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function decodeMetadata(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function assertMetadataSafe(array $metadata): void
    {
        $forbidden = ['secret', 'password', 'token', 'api_key', 'credentials', 'authorization'];
        foreach (array_keys($metadata) as $key) {
            $lower = strtolower((string) $key);
            foreach ($forbidden as $needle) {
                if (str_contains($lower, $needle)) {
                    throw new HttpException(
                        422,
                        'Les secrets ne doivent pas être stockés dans metadata.',
                        'PROVIDER_CUSTOMER_METADATA_FORBIDDEN'
                    );
                }
            }
        }
    }

    private static function isDuplicateKey(PDOException $e): bool
    {
        if ($e->getCode() === '23000') {
            return true;
        }

        $info = $e->errorInfo ?? [];

        return ($info[0] ?? '') === '23000';
    }
}
