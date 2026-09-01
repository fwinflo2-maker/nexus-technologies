<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Crypto;
use PDO;

/**
 * ProviderCredentialService — accès aux credentials providers stockées en base.
 *
 * RÈGLE CRITIQUE (§3, §4) :
 * ─────────────────────────
 * TOUTE opération est qualifiée par le triplet :
 *
 *     user_id + provider_slug + environment
 *
 * jamais par (user_id, provider_slug) seuls. La contrainte SQL
 * `uq_provider_creds_env` autorise désormais la coexistence des credentials
 * sandbox ET production pour un même provider : une requête non qualifiée par
 * l'environnement renverrait donc une ligne NON DÉTERMINISTE, et pourrait
 * résoudre une credential sandbox en production (ou l'inverse).
 *
 * Le Core ne manipule jamais les secrets déchiffrés : ce service est le seul
 * point de déchiffrement, et ses sorties publiques sont toujours redactées.
 */
final class ProviderCredentialService
{
    public const ENV_SANDBOX    = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    private function __construct()
    {
    }

    /** Normalise et valide un environnement. Retourne null si invalide. */
    public static function normalizeEnvironment(?string $env): ?string
    {
        $normalized = strtolower(trim((string) $env));
        return in_array($normalized, [self::ENV_SANDBOX, self::ENV_PRODUCTION], true)
            ? $normalized
            : null;
    }

    /**
     * Lit la ligne de credentials pour le triplet exact.
     *
     * @return array<string,mixed>|null
     */
    public static function findRow(PDO $pdo, int $userId, string $slug, string $environment): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, provider_slug, environment, credentials_enc,
                    status, last_tested_at, last_error, created_at, updated_at
             FROM provider_credentials
             WHERE user_id = :uid AND provider_slug = :slug AND environment = :env
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'slug' => $slug, 'env' => $environment]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Lit la credential de PLATEFORME (`user_id IS NULL`).
     *
     * Une credential provider est un actif de Nexus, pas du client : c'est
     * Nexus qui contracte avec Stripe ou Cashramp. Elle vaut donc pour tous
     * les clients, dans un environnement donné.
     *
     * @return array<string,mixed>|null
     */
    public static function findPlatformRow(PDO $pdo, string $slug, string $environment): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, provider_slug, environment, credentials_enc,
                    status, last_tested_at, last_error, created_at, updated_at
             FROM provider_credentials
             WHERE user_id IS NULL AND provider_slug = :slug AND environment = :env
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'env' => $environment]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Credential effective pour un sujet donné, sans jamais franchir la
     * frontière d'environnement.
     *
     * Ordre de résolution — la PLATEFORME D'ABORD :
     *   1. credential de plateforme (`user_id IS NULL`) ;
     *   2. à défaut, credential propre au sujet (compatibilité ascendante
     *      avec les lignes historiques, qu'aucune migration n'a promues).
     *
     * Cet ordre est délibéré : si un client possédait encore une ligne
     * héritée, elle ne doit pas prendre le pas sur le contrat officiel de la
     * plateforme.
     *
     * @return array<string,mixed>|null
     */
    public static function findEffectiveRow(PDO $pdo, ?int $userId, string $slug, string $environment): ?array
    {
        $platform = self::findPlatformRow($pdo, $slug, $environment);
        if ($platform !== null) {
            return $platform;
        }

        if ($userId === null || $userId <= 0) {
            return null;
        }

        return self::findRow($pdo, $userId, $slug, $environment);
    }

    /**
     * Résout les credentials DÉCHIFFRÉES de la plateforme (user_id IS NULL).
     *
     * Seul point de déchiffrement autorisé pour les credentials de la
     * plateforme : les appels réels (test de connexion, exécution) peuvent
     * les consommer sans jamais les journaliser ni les exposer.
     *
     * @return array<string,string>|null null si absente ou illisible
     */
    public static function resolvePlatform(PDO $pdo, string $slug, string $environment): ?array
    {
        $row = self::findPlatformRow($pdo, $slug, $environment);
        if ($row === null || ($row['credentials_enc'] ?? null) === null) {
            return null;
        }

        $plain = Crypto::decrypt((string) $row['credentials_enc']);
        if ($plain === null || $plain === '') {
            return null;
        }

        $payload = json_decode($plain, true);
        if (!is_array($payload) || !isset($payload['credentials']) || !is_array($payload['credentials'])) {
            return null;
        }

        $out = [];
        foreach ($payload['credentials'] as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Enregistre (upsert) la credential de PLATEFORME.
     *
     * `configured_by` retient l'opérateur : sans cette trace, aucune enquête
     * n'est possible après coup sur un secret de production.
     *
     * @param array<string,string> $credentials
     */
    public static function upsertPlatform(
        PDO $pdo,
        string $slug,
        string $environment,
        array $credentials,
        string $status,
        int $configuredBy
    ): void {
        $payload = [
            'credentials' => $credentials,
            'updated_by'  => $configuredBy,
            'updated_at'  => date(DATE_ATOM),
        ];

        // `owner_scope` vaut 0 pour une ligne de plateforme : c'est lui qui
        // porte l'unicité (MySQL considère les NULL comme distincts, donc
        // `user_id` seul n'empêcherait pas deux credentials concurrentes).
        $stmt = $pdo->prepare(
            'INSERT INTO provider_credentials
                (user_id, provider_slug, environment, credentials_enc, status, configured_by, updated_at)
             VALUES (NULL, :slug, :env, :enc, :status, :by, NOW())
             ON DUPLICATE KEY UPDATE
                 credentials_enc = VALUES(credentials_enc),
                 status          = VALUES(status),
                 configured_by   = VALUES(configured_by),
                 last_error      = NULL,
                 last_tested_at  = NULL,
                 updated_at      = NOW()'
        );
        $stmt->execute([
            'slug'   => $slug,
            'env'    => $environment,
            'enc'    => Crypto::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'status' => $status,
            'by'     => $configuredBy,
        ]);
    }

    /** Supprime la credential de plateforme. Retourne le nombre de lignes. */
    public static function deletePlatform(PDO $pdo, string $slug, string $environment): int
    {
        $stmt = $pdo->prepare(
            'DELETE FROM provider_credentials
             WHERE user_id IS NULL AND provider_slug = :slug AND environment = :env'
        );
        $stmt->execute(['slug' => $slug, 'env' => $environment]);

        return $stmt->rowCount();
    }

    /**
     * Met de nouvelles credentials de plateforme en attente de promotion.
     * Elles ne sont jamais résolues par l'exécution avant activateRotation().
     *
     * @param array<string,string> $credentials
     */
    public static function stagePlatform(PDO $pdo, string $slug, string $environment, array $credentials, int $configuredBy): int
    {
        $env = self::normalizeEnvironment($environment);
        if ($env === null || $credentials === []) {
            throw new \RuntimeException('Rotation de credentials invalide.');
        }
        $payload = [
            'credentials' => $credentials,
            'updated_by' => $configuredBy,
            'updated_at' => gmdate(DATE_ATOM),
        ];
        $stmt = $pdo->prepare(
            "INSERT INTO credential_rotations
                (provider_slug, environment, credentials_enc, status, configured_by)
             VALUES (:slug, :env, :enc, 'staged', :by)"
        );
        $stmt->execute([
            'slug' => $slug,
            'env' => $env,
            'enc' => Crypto::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'by' => $configuredBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,string>|null */
    public static function resolveStaged(PDO $pdo, string $slug, string $environment, int $rotationId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT credentials_enc FROM credential_rotations
             WHERE id = :id AND provider_slug = :slug AND environment = :env AND status = 'staged' LIMIT 1"
        );
        $stmt->execute(['id' => $rotationId, 'slug' => $slug, 'env' => $environment]);
        $encrypted = $stmt->fetchColumn();
        return is_string($encrypted) ? self::decryptCredentials($encrypted) : null;
    }

    /**
     * Promeut une rotation testée. L'ancienne valeur est d'abord archivée,
     * puis la nouvelle devient active dans la même transaction SQL.
     */
    public static function activateRotation(PDO $pdo, string $slug, string $environment, int $rotationId, int $configuredBy): void
    {
        $env = self::normalizeEnvironment($environment);
        if ($env === null) {
            throw new \RuntimeException('Environnement de rotation invalide.');
        }
        $pdo->beginTransaction();
        try {
            $staged = $pdo->prepare(
                "SELECT credentials_enc FROM credential_rotations
                 WHERE id = :id AND provider_slug = :slug AND environment = :env AND status = 'staged' FOR UPDATE"
            );
            $staged->execute(['id' => $rotationId, 'slug' => $slug, 'env' => $env]);
            $newEncrypted = $staged->fetchColumn();
            if (!is_string($newEncrypted) || self::decryptCredentials($newEncrypted) === null) {
                throw new \RuntimeException('Rotation en attente introuvable ou illisible.');
            }

            $current = self::findPlatformRow($pdo, $slug, $env);
            if ($current !== null && is_string($current['credentials_enc'] ?? null)) {
                $archive = $pdo->prepare(
                    "INSERT INTO credential_rotations
                        (provider_slug, environment, credentials_enc, status, configured_by, revoked_at)
                     VALUES (:slug, :env, :enc, 'revoked', :by, NOW())"
                );
                $archive->execute([
                    'slug' => $slug,
                    'env' => $env,
                    'enc' => $current['credentials_enc'],
                    'by' => $configuredBy,
                ]);
            }

            self::upsertPlatform($pdo, $slug, $env, self::decryptCredentials($newEncrypted) ?? [], 'active', $configuredBy);
            $pdo->prepare(
                "UPDATE credential_rotations
                 SET status = 'active', activated_at = NOW()
                 WHERE id = :id AND status = 'staged'"
            )->execute(['id' => $rotationId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Archive une credential active, puis la retire de la résolution. */
    public static function revokePlatform(PDO $pdo, string $slug, string $environment, int $configuredBy): void
    {
        $env = self::normalizeEnvironment($environment);
        if ($env === null) {
            throw new \RuntimeException('Environnement de révocation invalide.');
        }
        $pdo->beginTransaction();
        try {
            $current = self::findPlatformRow($pdo, $slug, $env);
            if ($current !== null && is_string($current['credentials_enc'] ?? null)) {
                $pdo->prepare(
                    "INSERT INTO credential_rotations
                        (provider_slug, environment, credentials_enc, status, configured_by, revoked_at)
                     VALUES (:slug, :env, :enc, 'revoked', :by, NOW())"
                )->execute([
                    'slug' => $slug,
                    'env' => $env,
                    'enc' => $current['credentials_enc'],
                    'by' => $configuredBy,
                ]);
                self::deletePlatform($pdo, $slug, $env);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> Métadonnées seules : jamais de secret. */
    public static function listRotations(PDO $pdo, ?string $slug = null, ?string $environment = null): array
    {
        $where = [];
        $params = [];
        if ($slug !== null) {
            $where[] = 'provider_slug = :slug';
            $params['slug'] = $slug;
        }
        if ($environment !== null) {
            $where[] = 'environment = :env';
            $params['env'] = $environment;
        }
        $sql = 'SELECT id, provider_slug, environment, status, configured_by, last_tested_at, created_at, activated_at, revoked_at FROM credential_rotations';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,string>|null */
    private static function decryptCredentials(string $encrypted): ?array
    {
        $plain = Crypto::decrypt($encrypted);
        $payload = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($payload) || !is_array($payload['credentials'] ?? null)) {
            return null;
        }
        $credentials = [];
        foreach ($payload['credentials'] as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $credentials[$key] = $value;
            }
        }
        return $credentials === [] ? null : $credentials;
    }

    /**
     * Résout les credentials déchiffrées pour un environnement PRÉCIS.
     *
     * Une credential sandbox ne peut JAMAIS être résolue pour la production,
     * et inversement : la requête filtre sur `environment`, il n'y a donc
     * aucun repli (« fallback ») d'un environnement vers l'autre (§4).
     *
     * @return array<string,string>|null null si aucune credential pour cet environnement
     */
    public static function resolve(PDO $pdo, int $userId, string $slug, string $environment): ?array
    {
        $env = self::normalizeEnvironment($environment);
        if ($env === null) {
            return null;
        }

        // Même ordre que la résolution d'exécution : la credential de
        // plateforme prime, la ligne héritée du client sert de repli.
        // Utiliser findRow() ici rendrait la credential officielle
        // indéchiffrable pour tout le monde.
        $row = self::findEffectiveRow($pdo, $userId, $slug, $env);
        if ($row === null || ($row['credentials_enc'] ?? null) === null) {
            return null;
        }

        $plain = Crypto::decrypt((string) $row['credentials_enc']);
        if ($plain === null || $plain === '') {
            return null;
        }

        $payload = json_decode($plain, true);
        if (!is_array($payload) || !isset($payload['credentials']) || !is_array($payload['credentials'])) {
            return null;
        }

        // Garde-fou de cohérence : la ligne lue doit appartenir à l'environnement demandé.
        if (($row['environment'] ?? null) !== $env) {
            return null;
        }

        $out = [];
        foreach ($payload['credentials'] as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Enregistre (upsert) les credentials chiffrées pour le triplet exact.
     *
     * L'upsert s'appuie sur `uq_provider_creds_env (user_id, provider_slug,
     * environment)` : enregistrer la production ne touche donc PLUS la
     * sandbox (régression corrigée en phase SQL).
     */
    public static function upsert(
        PDO $pdo,
        int $userId,
        string $slug,
        string $environment,
        array $credentials,
        string $status
    ): void {
        $payload = [
            'credentials' => $credentials,
            'updated_by'  => $userId,
            'updated_at'  => date(DATE_ATOM),
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO provider_credentials
                (user_id, provider_slug, environment, credentials_enc, status, updated_at)
             VALUES (:uid, :slug, :env, :enc, :status, NOW())
             ON DUPLICATE KEY UPDATE
                 credentials_enc = VALUES(credentials_enc),
                 status          = VALUES(status),
                 last_error      = NULL,
                 last_tested_at  = NULL,
                 updated_at      = NOW()'
        );
        $stmt->execute([
            'uid'    => $userId,
            'slug'   => $slug,
            'env'    => $environment,
            'enc'    => Crypto::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'status' => $status,
        ]);
    }

    /** Supprime les credentials du triplet exact. Retourne le nombre de lignes. */
    public static function delete(PDO $pdo, int $userId, string $slug, string $environment): int
    {
        $stmt = $pdo->prepare(
            'DELETE FROM provider_credentials
             WHERE user_id = :uid AND provider_slug = :slug AND environment = :env'
        );
        $stmt->execute(['uid' => $userId, 'slug' => $slug, 'env' => $environment]);

        return $stmt->rowCount();
    }

    /** Met à jour le résultat d'un test de connectivité de la credential de plateforme. */
    public static function markPlatformTested(
        PDO $pdo,
        string $slug,
        string $environment,
        string $status,
        ?string $error
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE provider_credentials
             SET last_tested_at = NOW(), status = :status, last_error = :err
             WHERE user_id IS NULL AND provider_slug = :slug AND environment = :env'
        );
        $stmt->execute([
            'slug'   => $slug,
            'env'    => $environment,
            'status' => $status,
            'err'    => $error,
        ]);
    }

    /** Met à jour le résultat d'un test de connectivité pour le triplet exact. */
    public static function markTested(
        PDO $pdo,
        int $userId,
        string $slug,
        string $environment,
        string $status,
        ?string $error
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE provider_credentials
             SET last_tested_at = NOW(), status = :status, last_error = :err
             WHERE user_id = :uid AND provider_slug = :slug AND environment = :env'
        );
        $stmt->execute([
            'uid'    => $userId,
            'slug'   => $slug,
            'env'    => $environment,
            'status' => $status,
            'err'    => $error,
        ]);
    }
}
