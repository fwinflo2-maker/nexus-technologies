<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Crypto;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Providers\ProviderRegistry;
use Nexus\Services\ProviderCatalog;

/**
 * Configuration des providers — métadonnées + identifiants chiffrés.
 *
 * Routes :
 *  - GET    /api/providers                  → catalogue (slugs + métadonnées)
 *  - GET    /api/providers/credentials      → état des credentials (statut, sans secrets)
 *  - PUT    /api/providers/{slug}/credentials → enregistre les credentials (chiffrés)
 *  - POST   /api/providers/{slug}/test      → ping simple pour vérifier la connectivité
 *  - DELETE /api/providers/{slug}/credentials → supprime les credentials
 *
 * Sécurité : les valeurs sensibles sont chiffrées en AES-256-GCM via APP_KEY
 * avant insertion, et NE SONT JAMAIS renvoyées par l'API. La lecture renvoie
 * uniquement les champs masqués ou non sensibles.
 *
 * Accès : compte personnel (lecture seule) ou business (lecture + écriture).
 * Les utilisateurs personnels peuvent consulter le catalogue mais ne peuvent
 * pas configurer de credentials.
 */
final class ProviderCredentialController
{
    /** Rôles autorisés à configurer des credentials. */
    private const WRITE_ROLES = ['business'];

    /**
     * GET /api/providers
     * Catalogue public : liste des providers + métadonnées (sans secrets).
     */
    public static function catalog(Request $request): void
    {
        AuthMiddleware::handle($request);

        $providers = [];
        foreach (ProviderCatalog::all() as $slug => $provider) {
            $providers[] = self::publicProvider($slug, $provider);
        }

        Response::success([
            'categories' => ProviderCatalog::CATEGORIES,
            'providers'  => $providers,
            'total'      => count($providers),
        ]);
    }

    /**
     * GET /api/providers/status
     * Statut de configuration & santé de tous les providers (SANS AUCUN secret).
     *
     * Destiné au futur Admin Dashboard (§11) et aux diagnostics : seuls les
     * statuts (configured / missing_credentials / disabled / …) sont exposés.
     * Jamais une valeur de credential.
     */
    public static function status(Request $request): void
    {
        AuthMiddleware::handle($request);

        Response::success([
            'strict_mode' => ProviderRegistry::isStrictMode(),
            'providers'   => ProviderRegistry::summary(),
        ]);
    }

    /**
     * GET /api/providers/credentials
     * État des credentials pour l'utilisateur connecté (sans secrets).
     */
    public static function list(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, provider_slug, environment, status, last_tested_at, last_error, created_at, updated_at
             FROM provider_credentials WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);

        $items = array_map(static function (array $row): array {
            return [
                'id'             => (int) $row['id'],
                'provider_slug'  => $row['provider_slug'],
                'environment'    => $row['environment'],
                'status'         => $row['status'],
                'has_credentials' => $row['status'] !== 'not_configured',
                'last_tested_at' => self::toIso8601($row['last_tested_at']),
                'last_error'     => $row['last_error'],
                'created_at'     => self::toIso8601($row['created_at']),
                'updated_at'     => self::toIso8601($row['updated_at']),
            ];
        }, $stmt->fetchAll());

        Response::success([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * PUT /api/providers/{slug}/credentials
     * Enregistre (upsert) les credentials chiffrés pour le provider.
     */
    public static function upsert(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];
        $slug    = (string) $request->param('slug', '');

        if (!ProviderCatalog::exists($slug)) {
            Response::notFound('Provider inconnu.');
        }

        $user = $request->attribute('user');
        if (!in_array($user['account_type'] ?? 'personal', self::WRITE_ROLES, true)) {
            Response::forbidden('Seuls les comptes business peuvent configurer des providers.');
        }

        $provider = ProviderCatalog::get($slug);
        $body     = $request->body();

        // Collecte et validation des champs.
        $env = strtolower((string) ($body['environment'] ?? 'sandbox'));
        if (!in_array($env, ['sandbox', 'production'], true)) {
            Response::badRequest('Environnement invalide (sandbox|production).');
        }

        $fields = [];
        foreach ($provider['credentials'] as $field) {
            $key      = $field['key'];
            $value    = $body['credentials'][$key] ?? $body[$key] ?? null;
            $required = (bool) $field['required'];

            if ($required && ($value === null || $value === '')) {
                Response::badRequest('Le champ « ' . $field['label'] . ' » est requis.');
            }
            // Si le champ est facultatif et non fourni, on conserve la valeur existante.
            $fields[$key] = is_string($value) ? trim($value) : null;
        }

        // Au moins un champ non vide doit être fourni.
        $nonEmpty = array_filter($fields, static fn ($v) => $v !== null && $v !== '');
        if (empty($nonEmpty)) {
            Response::badRequest('Au moins un identifiant doit être fourni.');
        }

        $payload = [
            'credentials' => array_map(static fn ($v) => $v ?? '', $fields),
            'updated_by'  => $userId,
            'updated_at'  => date(DATE_ATOM),
        ];

        $pdo = Database::getConnection();

        // Upsert (INSERT ... ON DUPLICATE KEY UPDATE).
        $stmt = $pdo->prepare(
            'INSERT INTO provider_credentials (user_id, provider_slug, environment, credentials_enc, status, updated_at)
             VALUES (:uid, :slug, :env, :enc, :status, NOW())
             ON DUPLICATE KEY UPDATE
                 environment = VALUES(environment),
                 credentials_enc = VALUES(credentials_enc),
                 status = VALUES(status),
                 last_error = NULL,
                 updated_at = NOW()'
        );
        $stmt->execute([
            'uid'    => $userId,
            'slug'   => $slug,
            'env'    => $env,
            'enc'    => Crypto::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'status' => $env === 'sandbox' ? 'sandbox_only' : 'active',
        ]);

        self::audit($pdo, $userId, 'provider.credentials.upsert', $slug, ['environment' => $env], $request);

        Response::success([
            'provider_slug' => $slug,
            'environment'   => $env,
            'status'        => $env === 'sandbox' ? 'sandbox_only' : 'active',
            'updated'       => true,
        ]);
    }

    /**
     * DELETE /api/providers/{slug}/credentials
     */
    public static function delete(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];
        $slug    = (string) $request->param('slug', '');

        if (!ProviderCatalog::exists($slug)) {
            Response::notFound('Provider inconnu.');
        }

        $user = $request->attribute('user');
        if (!in_array($user['account_type'] ?? 'personal', self::WRITE_ROLES, true)) {
            Response::forbidden('Seuls les comptes business peuvent supprimer les credentials.');
        }

        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM provider_credentials WHERE user_id = :uid AND provider_slug = :slug')
            ->execute(['uid' => $userId, 'slug' => $slug]);

        self::audit($pdo, $userId, 'provider.credentials.delete', $slug, [], $request);

        Response::success([
            'provider_slug' => $slug,
            'deleted'       => true,
        ]);
    }

    /**
     * POST /api/providers/{slug}/test
     * Ping simple : ouvre un socket TCP sur la base URL pour vérifier
     * la connectivité. Endpoint volontairement simplifié : la vérification
     * réelle des credentials est faite par les moteurs déterministes (capability,
     * quote, execution) ; ici on s'assure juste que l'hôte répond.
     */
    public static function test(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];
        $slug    = (string) $request->param('slug', '');

        if (!ProviderCatalog::exists($slug)) {
            Response::notFound('Provider inconnu.');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, environment FROM provider_credentials
             WHERE user_id = :uid AND provider_slug = :slug LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'slug' => $slug]);
        $existing = $stmt->fetch();

        if ($existing === false) {
            Response::badRequest('Aucun identifiant enregistré pour ce provider.');
        }

        $provider = ProviderCatalog::get($slug);
        $url      = ($existing['environment'] === 'production') ? $provider['base_url'] : ($provider['sandbox_url'] ?? $provider['base_url']);

        $parts = parse_url($url);
        $host  = $parts['host'] ?? null;
        $port  = $parts['scheme'] === 'https' ? 443 : (int) ($parts['port'] ?? 80);

        $start = microtime(true);
        $ok    = false;
        $err   = null;
        if ($host !== null) {
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($host, $port, $errno, $errstr, 5.0);
            if ($fp !== false) {
                $ok = true;
                fclose($fp);
            } else {
                $err = $errstr !== '' ? $errstr : "Connection failed ($errno)";
            }
        }
        $latency = round((microtime(true) - $start) * 1000);

        $status = $ok ? ($existing['environment'] === 'production' ? 'active' : 'sandbox_only') : 'error';

        $pdo->prepare(
            'UPDATE provider_credentials
             SET last_tested_at = NOW(), status = :status, last_error = :err
             WHERE user_id = :uid AND provider_slug = :slug'
        )->execute([
            'uid'    => $userId,
            'slug'   => $slug,
            'status' => $status,
            'err'    => $err,
        ]);

        self::audit($pdo, $userId, 'provider.credentials.test', $slug, [
            'reachable' => $ok,
            'latency_ms'=> $latency,
            'environment' => $existing['environment'],
        ], $request);

        Response::success([
            'provider_slug' => $slug,
            'reachable'     => $ok,
            'latency_ms'    => $latency,
            'error'         => $ok ? null : $err,
            'environment'   => $existing['environment'],
        ]);
    }

    // --- Helpers --------------------------------------------------------

    /** Construit la représentation publique d'un provider (catalogue). */
    private static function publicProvider(string $slug, array $provider): array
    {
        return [
            'slug'        => $slug,
            'name'        => $provider['name'],
            'category'    => $provider['category'],
            'icon'        => $provider['icon'],
            'auth_type'   => $provider['auth_type'],
            'doc_url'     => $provider['doc_url'],
            'countries'   => $provider['countries'],
            'base_url'    => $provider['base_url'],
            'sandbox_url' => $provider['sandbox_url'],
            'fields'      => array_map(static function (array $f): array {
                return [
                    'key'         => $f['key'],
                    'label'       => $f['label'],
                    'placeholder' => $f['placeholder'] ?? '',
                    'required'    => $f['required'],
                    'type'        => $f['type'],
                    'has_value'   => false,
                ];
            }, $provider['credentials']),
        ];
    }

    private static function toIso8601(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null) return null;
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? $mysqlDatetime : gmdate('c', $ts);
    }

    private static function audit(\PDO $pdo, int $userId, string $action, string $slug, array $metadata, Request $request): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address, created_at)
                 VALUES (:uid, :act, :etype, :eid, :meta, :ip, NOW())'
            );
            $stmt->execute([
                'uid'   => $userId,
                'act'   => $action,
                'etype' => 'provider_credentials',
                'eid'   => null,
                'meta'  => json_encode(array_merge($metadata, ['provider_slug' => $slug]), JSON_UNESCAPED_UNICODE),
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('[NEXUS audit] ' . $e->getMessage());
        }
    }
}
