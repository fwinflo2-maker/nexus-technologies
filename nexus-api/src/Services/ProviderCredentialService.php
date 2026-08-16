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
     * Nexus qui contracte avec Stripe ou pawaPay. Elle vaut donc pour tous
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
