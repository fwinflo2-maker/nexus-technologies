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

    /**
     * GET /api/control/access — surfaces internes accessibles au rôle.
     *
     * Détermine, côté SERVEUR, quels dashboards/surfaces le rôle connecté a
     * le droit de voir. Le frontend n'a qu'à afficher ce que le backend
     * accepte de renvoyer (le backend reste l'autorité sur chaque ressource).
     */
    public static function access(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $role    = PlatformRole::of($user);

        $dashboard = PlatformRole::dashboardOf($user);

        Response::success([
            'role'      => $role,
            'dashboard' => $dashboard,
            'surfaces'  => [
                'overview'            => PlatformRole::canViewOperations($user),
                'providers'           => PlatformRole::canViewCredentialInventory($user),
                'clients'             => PlatformRole::isSuperadmin($user),
                'audit'               => PlatformRole::canViewAudit($user),
                'kyc'                 => PlatformRole::canViewKyc($user),
                'maintenance'         => PlatformRole::canRunMaintenance($user),
                'credentials'         => PlatformRole::canAdministerCredentials($user),
                'dashboard'           => $dashboard,
            ],
        ]);
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
        // CORRECTIF CRITICAL (boucle 18). Cette surface était gardée par
        // `operations`, donc ouverte aux 9 rôles d'exploitation. Un
        // `qa_engineer` obtenait 200 et lisait nom, e-mail, `applicant_id` et
        // MOTIF DE REJET des dossiers d'identité — « suspicion de fraude
        // documentaire » pour un client nommé. Prouvé en HTTP réel.
        //
        // Le besoin d'en connaître prime sur le confort de diagnostic : ces
        // dossiers relèvent de la conformité, pas de l'exploitation.
        $user = self::authorize($request, 'kyc_read');
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

    /**
     * GET /api/control/clients — registre complet des clients & entreprises.
     *
     * Liste tous les utilisateurs (personnes et entreprises) avec leurs
     * informations agrégées : profil, pays de résidence, type de compte,
     * statut, niveau KYC, soldes par devise et compteurs d'activité.
     *
     * DONNÉES SENSIBLES : concerne tous les clients. Réservé au SUPERADMIN
     * (capacité `superadmin`), pas aux autres rôles d'exploitation.
     * Aucun secret (mot de passe, token, credential) n'est renvoyé.
     */
    public static function clients(Request $request): void
    {
        $user = self::authorize($request, 'superadmin');
        $pdo  = Database::getConnection();

        // Profil + agrégats wallet (soldes par devise) + compteurs.
        $stmt = $pdo->query(
            'SELECT u.id, u.full_name, u.email, u.phone, u.account_type, u.platform_role,
                    u.status, u.kyc_level, u.country_of_residence, u.avatar, u.auth_provider,
                    u.created_at, u.updated_at,
                    COALESCE(SUM(CASE WHEN w.currency = \'EUR\' THEN w.balance ELSE 0 END), 0)  AS balance_eur,
                    COALESCE(SUM(CASE WHEN w.currency = \'USD\' THEN w.balance ELSE 0 END), 0)  AS balance_usd,
                    COALESCE(SUM(CASE WHEN w.currency = \'XAF\' THEN w.balance ELSE 0 END), 0)  AS balance_xaf,
                    (SELECT COUNT(*) FROM transactions t WHERE t.user_id = u.id) AS tx_count
             FROM users u
             LEFT JOIN wallets w ON w.user_id = u.id
             GROUP BY u.id
             ORDER BY u.created_at DESC'
        );

        $clients = array_map(static function (array $row): array {
            return [
                'id'                   => (int) $row['id'],
                'full_name'            => $row['full_name'],
                'email'                => $row['email'],
                'phone'                => $row['phone'],
                'account_type'         => $row['account_type'],
                'platform_role'        => $row['platform_role'],
                'status'               => $row['status'],
                'kyc_level'            => $row['kyc_level'],
                'country_of_residence' => $row['country_of_residence'],
                'avatar'               => $row['avatar'],
                'auth_provider'        => $row['auth_provider'],
                'created_at'           => $row['created_at'],
                'updated_at'           => $row['updated_at'],
                'balances'             => [
                    'EUR' => $row['balance_eur'],
                    'USD' => $row['balance_usd'],
                    'XAF' => $row['balance_xaf'],
                ],
                'transactions'         => (int) $row['tx_count'],
            ];
        }, $stmt->fetchAll());

        Response::success([
            'items'      => $clients,
            'total'      => count($clients),
            'generated_at' => gmdate(DATE_ATOM),
        ]);
    }

    /**
     * GET /api/control/clients/{id} — fiche détaillée d'un client.
     *
     * Renvoie toutes les informations d'un client : profil complet, pays de
     * résidence, adresse/ville (déchiffrées depuis ses comptes de paiement),
     * comptes de paiement, soldes par devise et historique des transactions.
     *
     * Réservé au SUPERADMIN. Aucun secret (mot de passe, token, credential)
     * n'est renvoyé — seule l'adresse chiffrée dans payment_accounts est
     * déchiffrée car c'est une donnée d'identification du client.
     */
    public static function clientDetail(Request $request): void
    {
        $user = self::authorize($request, 'superadmin');
        $pdo  = Database::getConnection();
        $id   = (int) $request->param('id', '0');

        if ($id <= 0) {
            Response::badRequest('Identifiant de client invalide.');
        }

        // Profil + soldes par devise.
        $stmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.phone, u.account_type, u.platform_role,
                    u.status, u.kyc_level, u.country_of_residence, u.avatar, u.auth_provider,
                    u.birth_date, u.gender, u.city, u.postal_code, u.address,
                    u.company_name, u.legal_form, u.company_registration_number, u.industry, u.company_size, u.website,
                    u.created_at, u.updated_at,
                    COALESCE(SUM(CASE WHEN w.currency = \'EUR\' THEN w.balance ELSE 0 END), 0) AS balance_eur,
                    COALESCE(SUM(CASE WHEN w.currency = \'USD\' THEN w.balance ELSE 0 END), 0) AS balance_usd,
                    COALESCE(SUM(CASE WHEN w.currency = \'XAF\' THEN w.balance ELSE 0 END), 0) AS balance_xaf
             FROM users u
             LEFT JOIN wallets w ON w.user_id = u.id
             WHERE u.id = :id
             GROUP BY u.id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::notFound('Client introuvable.');
        }

        // Comptes de paiement (avec adresse/ville déchiffrées).
        $acc = $pdo->prepare(
            'SELECT id, role, kind, label, holder_name, country, city, operator, network,
                    is_default, verification_status, status, provider_slug, address_enc, phone_enc, created_at
             FROM payment_accounts
             WHERE user_id = :id
             ORDER BY created_at DESC'
        );
        $acc->execute(['id' => $id]);
        $accounts = array_map(static function (array $a): array {
            return [
                'id'                 => (int) $a['id'],
                'role'               => $a['role'],
                'kind'               => $a['kind'],
                'label'              => $a['label'],
                'holder_name'        => $a['holder_name'],
                'country'            => $a['country'],
                'city'               => $a['city'],
                'operator'           => $a['operator'],
                'network'            => $a['network'],
                'is_default'         => (bool) $a['is_default'],
                'verification_status'=> $a['verification_status'],
                'status'             => $a['status'],
                'provider_slug'      => $a['provider_slug'],
                // Adresse et téléphone déchiffrés (données d'identification client).
                'address'            => \Nexus\Core\Crypto::decrypt($a['address_enc']),
                'phone'              => \Nexus\Core\Crypto::decrypt($a['phone_enc']),
                'created_at'         => $a['created_at'],
            ];
        }, $acc->fetchAll());

        // Adresse/ville agrégée : la première adresse non vide parmi les comptes.
        $address = null;
        $city    = null;
        foreach ($accounts as $a) {
            if (($address === null) && $a['address'] !== null && $a['address'] !== '') {
                $address = $a['address'];
            }
            if (($city === null) && $a['city'] !== null && $a['city'] !== '') {
                $city = $a['city'];
            }
            if ($address !== null && $city !== null) {
                break;
            }
        }

        // Historique des transactions (récentes d'abord, limité).
        $tx = $pdo->prepare(
            'SELECT id, type, direction, label, description, amount, currency,
                    status, provider, destination, created_at, environment
             FROM transactions
             WHERE user_id = :id
             ORDER BY created_at DESC
             LIMIT 100'
        );
        $tx->execute(['id' => $id]);

        Response::success([
            'client' => [
                'id'                   => (int) $row['id'],
                'full_name'            => $row['full_name'],
                'email'                => $row['email'],
                'phone'                => $row['phone'],
                'account_type'         => $row['account_type'],
                'platform_role'        => $row['platform_role'],
                'status'               => $row['status'],
                'kyc_level'            => $row['kyc_level'],
                'country_of_residence' => $row['country_of_residence'],
                'avatar'               => $row['avatar'],
                'auth_provider'        => $row['auth_provider'],
                'created_at'           => $row['created_at'],
                'updated_at'           => $row['updated_at'],
                'address'              => $address,
                'city'                 => $city,
                // Profil riche (collecté à l'inscription)
                'birth_date'           => $row['birth_date'],
                'gender'               => $row['gender'],
                'postal_code'          => $row['postal_code'],
                'company_name'         => $row['company_name'],
                'legal_form'           => $row['legal_form'],
                'company_registration_number' => $row['company_registration_number'],
                'industry'             => $row['industry'],
                'company_size'         => $row['company_size'],
                'website'              => $row['website'],
                'balances'             => [
                    'EUR' => $row['balance_eur'],
                    'USD' => $row['balance_usd'],
                    'XAF' => $row['balance_xaf'],
                ],
                'accounts'             => $accounts,
                'transactions'         => $tx->fetchAll(),
            ],
        ]);
    }
}
