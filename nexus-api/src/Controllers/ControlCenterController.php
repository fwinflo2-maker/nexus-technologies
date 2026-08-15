<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\PlatformRole;
use Nexus\Providers\ProviderRegistry;
use Nexus\Services\ControlCenterService;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;

/**
 * NEXUS CONTROL CENTER — API du plan de contrôle de l'infrastructure.
 *
 * Routes :
 *   GET /api/control/overview        → vue d'ensemble (valeurs mesurées)
 *   GET /api/control/providers       → matrice des providers
 *   GET /api/control/providers/{slug}→ fiche détaillée
 *   GET /api/control/public-keys     → registre des clés (frontend vs backend)
 *   GET /api/control/kyc             → tableau de bord KYC/KYB
 *   GET /api/control/webhooks        → journal des webhooks
 *   GET /api/control/audit           → journal d'audit
 *
 * SÉCURITÉ (§27) : l'interface n'est JAMAIS une couche de sécurité. Chaque
 * point d'entrée vérifie l'autorisation côté serveur. Aucune de ces réponses
 * ne contient de secret, même partiellement décodable (§14).
 */
final class ControlCenterController
{
    /**
     * Contrôle d'accès au Control Center.
     *
     * Le plan de contrôle administre l'infrastructure : il est réservé au
     * personnel d'exploitation.
     *
     * Auparavant, faute de rôle « opérateur Nexus », l'accès était accordé aux
     * comptes `account_type === 'business'`. Ce repli était dangereux : le
     * type de compte est choisi librement à l'inscription, donc n'importe qui
     * pouvait lire l'état de l'infrastructure (providers, credentials
     * configurées, webhooks, audit). Ce rôle existe désormais.
     *
     * Refus en 403, jamais 400 : c'est une question d'autorisation.
     */
    private static function authorize(Request $request, string $capability = 'operations'): array
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');

        // La capacité est un PARAMÈTRE : toutes les surfaces du Control Center
        // ne se valent pas. La lecture générale reste `operations`, mais
        // l'inventaire des credentials et le journal d'audit exigent des
        // capacités plus étroites (boucle 16).
        PlatformRole::require($user, $capability);

        return $user;
    }

    /** GET /api/control/overview */
    public static function overview(Request $request): void
    {
        $user = self::authorize($request);

        Response::success(
            ControlCenterService::overview(Database::getConnection(), (int) $user['id'])
        );
    }

    /** GET /api/control/providers — matrice complète (§21). */
    public static function providers(Request $request): void
    {
        $user = self::authorize($request);
        $pdo  = Database::getConnection();

        $items = [];
        foreach (array_keys(ProviderCatalog::all()) as $slug) {
            $items[] = ControlCenterService::providerCard($pdo, (int) $user['id'], $slug);
        }

        Response::success([
            'items'       => $items,
            'total'       => count($items),
            'strict_mode' => ProviderRegistry::isStrictMode(),
            'operations'  => ControlCenterService::PROVIDER_OPERATIONS,
        ]);
    }

    /** GET /api/control/providers/{slug} — fiche détaillée (§4). */
    public static function providerDetail(Request $request): void
    {
        $user = self::authorize($request);
        $slug = (string) $request->param('slug', '');

        if (!ProviderCatalog::exists($slug)) {
            Response::notFound('Provider inconnu.');
        }

        $pdo  = Database::getConnection();
        $card = ControlCenterService::providerCard($pdo, (int) $user['id'], $slug);

        // Santé : distingue explicitement joignabilité et authentification (§13).
        $adapter = ProviderRegistry::adapter($slug);
        $health  = $adapter->healthCheck();

        $card['health'] = [
            'status'        => $health['status'],
            // `reachable` = sonde TCP ; `authenticated` reste inconnu tant
            // qu'aucun appel authentifié n'est implémenté (§14).
            'reachable'     => $health['healthy'],
            'authenticated' => null,
            'latency_ms'    => $health['latency_ms'] ?? null,
            'message'       => $health['message'] ?? null,
            'checked_at'    => date(DATE_ATOM),
        ];

        Response::success($card);
    }

    /** GET /api/control/public-keys — registre des clés (§8). */
    public static function publicKeys(Request $request): void
    {
        $user = self::authorize($request);

        $rows = ControlCenterService::publicKeyRegistry(Database::getConnection(), (int) $user['id']);

        Response::success([
            'items' => $rows,
            'total' => count($rows),
            'legend' => [
                'frontend' => 'Exposable au client — documenté par le provider.',
                'backend'  => 'Backend uniquement — ne doit jamais atteindre le navigateur.',
            ],
        ]);
    }

    /** GET /api/control/kyc — tableau de bord KYC/KYB (§17, §18). */
    public static function kyc(Request $request): void
    {
        $user = self::authorize($request);
        $pdo  = Database::getConnection();

        // Applicants : aucune donnée sensible (ni document, ni selfie, ni
        // réponse brute du provider) — uniquement identifiants et statuts.
        $stmt = $pdo->query(
            'SELECT k.id, k.user_id, u.full_name, u.email, k.provider, k.environment,
                    k.subject_type, k.applicant_id, k.level_name, k.status, k.reason,
                    k.reviewed_at, k.created_at, k.updated_at
             FROM kyc_verifications k
             JOIN users u ON u.id = k.user_id
             ORDER BY k.updated_at DESC
             LIMIT 200'
        );

        Response::success([
            'counters'   => ControlCenterService::kycCounters($pdo),
            'applicants' => $stmt->fetchAll(),
        ]);
    }

    /** GET /api/control/webhooks — journal des webhooks (§19). */
    public static function webhooks(Request $request): void
    {
        self::authorize($request);
        $pdo = Database::getConnection();

        // Le secret de signature n'est évidemment jamais exposé.
        $stmt = $pdo->query(
            'SELECT id, provider, environment, event_id, applicant_id, status, processed_at
             FROM kyc_webhook_events
             ORDER BY processed_at DESC
             LIMIT 200'
        );

        Response::success([
            'items'    => $stmt->fetchAll(),
            'counters' => ControlCenterService::webhookCounters($pdo),
        ]);
    }

    /** GET /api/control/audit — journal d'audit (§26). */
    public static function audit(Request $request): void
    {
        // Le journal couvre TOUS les comptes et TOUT le personnel : c'est une
        // surface de surveillance, réservée à la sécurité et à la conformité.
        // Il était lisible par les 9 rôles porteurs de `operations`, dont le
        // support et la QA.
        self::authorize($request, 'audit_read');
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            'SELECT a.id, a.user_id, u.full_name AS actor, a.action, a.entity_type,
                    a.entity_id, a.metadata, a.ip_address, a.created_at
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC
             LIMIT 200'
        );

        $items = array_map(static function (array $row): array {
            // `metadata` ne contient jamais de secret (vérifié en phase SQL),
            // mais on la renvoie décodée pour l'affichage.
            $meta = $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null;
            $row['metadata'] = is_array($meta) ? $meta : null;
            return $row;
        }, $stmt->fetchAll());

        Response::success(['items' => $items, 'total' => count($items)]);
    }

    /**
     * GET /api/control/credentials — état des credentials par environnement (§5).
     *
     * Ne renvoie aucune valeur : uniquement « configuré » ou non (§11).
     */
    public static function credentials(Request $request): void
    {
        // Capacité DÉDIÉE (boucle 16). Savoir quels providers sont
        // configurés, dans quel environnement et depuis quand, c'est un plan
        // de l'infrastructure de paiement : corridors actifs et dépendances
        // externes de Nexus. Ce n'est le métier ni du support ni de la QA,
        // qui y avaient pourtant accès via la capacité `operations`.
        $user = self::authorize($request, 'credential_inventory');
        $pdo  = Database::getConnection();
        $uid  = (int) $user['id'];

        $items = [];
        foreach (ProviderCatalog::all() as $slug => $provider) {
            $envs = [];
            foreach (['sandbox', 'production'] as $env) {
                $row = ProviderCredentialService::findRow($pdo, $uid, $slug, $env);
                $envs[$env] = [
                    'configured'     => $row !== null && ($row['credentials_enc'] ?? null) !== null,
                    'status'         => $row['status'] ?? 'not_configured',
                    'last_tested_at' => $row['last_tested_at'] ?? null,
                    'updated_at'     => $row['updated_at'] ?? null,
                ];
            }
            $items[] = [
                'slug'         => $slug,
                'name'         => $provider['name'] ?? $slug,
                'environments' => $envs,
                'schema'       => \Nexus\Providers\ProviderCredentialSchema::describe($slug),
            ];
        }

        Response::success(['items' => $items, 'total' => count($items)]);
    }
}
