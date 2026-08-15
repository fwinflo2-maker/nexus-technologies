<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Auth\Jwt;
use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use PDOException;

// Note : AccountController est importé via autoloader, pas besoin de use

/**
 * Authentification : inscription (email/téléphone), connexion, déconnexion et profil.
 *
 * Toutes les méthodes sont statiques et reçoivent la requête normalisée.
 * Les réponses sont émises via Nexus\Core\Response (jamais de sortie brute).
 */
final class AuthController
{
    /** Nombre maximal de tentatives de connexion échouées dans la fenêtre. */
    private const LOGIN_MAX_ATTEMPTS = 5;

    /** Fenêtre de débit (secondes) : 5 tentatives / 5 minutes. */
    private const LOGIN_WINDOW_SECONDS = 300;

    /** Types de compte acceptés à l'inscription. */
    private const ALLOWED_ACCOUNT_TYPES = ['personal', 'business'];

    /** Wallets de bienvenue (données de démonstration). */
    private const WELCOME_WALLETS = [
        ['currency' => 'EUR',  'amount' => '2500.00'],
        ['currency' => 'USD',  'amount' => '1200.00'],
        ['currency' => 'GBP',  'amount' => '500.00'],
        ['currency' => 'XAF',  'amount' => '1500000.00'],
        ['currency' => 'USDT', 'amount' => '1200.00'],
        ['currency' => 'USDC', 'amount' => '500.00'],
    ];

    /**
     * Transactions de bienvenue (données de démonstration) : alimentent le
     * dashboard (KPIs, activité, historique) dès l'inscription.
     *
     * @var list<array{type: string, direction: string, label: string, provider: string, destination: string, amount: float, currency: string, status: string, execution_time: int, hours_ago: int}>
     */
    private const DEMO_TRANSACTIONS = [
        ['type' => 'receive', 'direction' => 'in',  'label' => 'Réception SEPA',       'provider' => 'Swan',           'destination' => 'Virement entrant',        'amount' => 1200, 'currency' => 'EUR',  'status' => 'completed',  'execution_time' => 45,   'hours_ago' => 5],
        ['type' => 'send',    'direction' => 'out', 'label' => 'Envoi Mobile Money',   'provider' => 'pawaPay',        'destination' => 'Congo — +242 06 123456', 'amount' => 500,  'currency' => 'EUR',  'status' => 'completed',  'execution_time' => 180,  'hours_ago' => 2],
        ['type' => 'send',    'direction' => 'out', 'label' => 'Paiement fournisseur', 'provider' => 'Thunes',          'destination' => 'Thunes — XAF',             'amount' => 750,  'currency' => 'EUR',  'status' => 'processing', 'execution_time' => null, 'hours_ago' => 1],
        ['type' => 'fx',      'direction' => 'fx',  'label' => 'Conversion FX',        'provider' => 'Currencycloud',  'destination' => 'EUR → USD',                'amount' => 300,  'currency' => 'EUR',  'status' => 'completed',  'execution_time' => 95,   'hours_ago' => 26],
        ['type' => 'receive', 'direction' => 'in',  'label' => 'Reçu — Mobile Money',  'provider' => 'pawaPay',        'destination' => '+242 06 654321',          'amount' => 120,  'currency' => 'EUR',  'status' => 'completed',  'execution_time' => 60,   'hours_ago' => 49],
    ];

    /** POST /api/register — crée un compte + wallets de démo. */
    public static function register(Request $request): void
    {
        $fullName    = trim((string) $request->input('full_name', ''));
        $email       = strtolower(trim((string) $request->input('email', '')));
        $password    = (string) $request->input('password', '');
        $accountType = (string) $request->input('account_type', '');
        $phoneCode   = trim((string) $request->input('phone_code', ''));
        $phone       = trim((string) $request->input('phone', ''));
        $fullPhone   = ($phone !== '' && $phoneCode !== '') ? $phoneCode . $phone : null;

        // --- Profil riche (collecté à l'inscription, stocké en base) ---------
        $birthDate    = trim((string) $request->input('birth_date', ''));
        $gender       = trim((string) $request->input('gender', ''));
        $city         = trim((string) $request->input('city', ''));
        $postalCode   = trim((string) $request->input('postal_code', ''));
        $address      = trim((string) $request->input('address', ''));
        $countryCode  = strtoupper(trim((string) $request->input('country_of_residence', '')));
        // Entreprise
        $companyName  = trim((string) $request->input('company_name', ''));
        $legalForm    = trim((string) $request->input('legal_form', ''));
        $regNumber    = trim((string) $request->input('company_registration_number', ''));
        $industry     = trim((string) $request->input('industry', ''));
        $companySize  = trim((string) $request->input('company_size', ''));
        $website      = trim((string) $request->input('website', ''));

        // --- Validation -------------------------------------------------------
        if ($fullName === '' || mb_strlen($fullName) > 120) {
            Response::badRequest('Le nom complet est requis (120 caractères maximum).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            Response::badRequest('Adresse email invalide.');
        }
        if (strlen($password) < 8) {
            Response::badRequest('Le mot de passe doit contenir au moins 8 caractères.');
        }
        if (!in_array($accountType, self::ALLOWED_ACCOUNT_TYPES, true)) {
            Response::badRequest('Le type de compte doit être « personal » ou « business ».');
        }
        if ($fullPhone !== null && strlen($fullPhone) > 20) {
            Response::badRequest('Numéro de téléphone invalide (20 caractères maximum).');
        }

        $pdo = Database::getConnection();

        // --- Email unique -----------------------------------------------------
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch() !== false) {
            Response::conflict('Un compte existe déjà avec cette adresse email.');
        }

        // --- Création du compte (transaction : user + wallets + notification) -
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO users (full_name, email, phone, password_hash, account_type, auth_provider, status, kyc_level,
                                    birth_date, gender, city, postal_code, address, country_of_residence,
                                    company_name, legal_form, company_registration_number, industry, company_size, website)
                 VALUES (:full_name, :email, :phone, :password_hash, :account_type, :auth_provider, :status, :kyc_level,
                         :birth_date, :gender, :city, :postal_code, :address, :country_of_residence,
                         :company_name, :legal_form, :reg_number, :industry, :company_size, :website)'
            );
            $stmt->execute([
                'full_name'     => $fullName,
                'email'         => $email,
                'phone'         => $fullPhone,
                'password_hash' => $passwordHash,
                'account_type'  => $accountType,
                'auth_provider' => 'local',
                'status'        => 'PENDING',
                'kyc_level'     => 'none',
                'birth_date'    => $birthDate !== '' ? $birthDate : null,
                'gender'        => $gender !== '' ? $gender : null,
                'city'          => $city !== '' ? $city : null,
                'postal_code'   => $postalCode !== '' ? $postalCode : null,
                'address'       => $address !== '' ? $address : null,
                'country_of_residence' => $countryCode !== '' && strlen($countryCode) === 2 ? $countryCode : null,
                'company_name'  => $companyName !== '' ? $companyName : null,
                'legal_form'    => $legalForm !== '' ? $legalForm : null,
                'reg_number'    => $regNumber !== '' ? $regNumber : null,
                'industry'      => $industry !== '' ? $industry : null,
                'company_size'  => $companySize !== '' ? $companySize : null,
                'website'       => $website !== '' ? $website : null,
            ]);
            $userId = (int) $pdo->lastInsertId();

            // Remplacement des INSERT directs par WalletService et LedgerService
            // §29 : les wallets de bienvenue sont un jeu de démonstration —
            // jamais crédités automatiquement en production.
            foreach (\Nexus\Core\DemoMode::seedingAllowed() ? self::WELCOME_WALLETS : [] as $wallet) {
                $currency = $wallet['currency'];
                $amount   = $wallet['amount'];

                // 1. Garantie de l'existence du wallet
                $w = \Nexus\Services\WalletService::ensureWallet($userId, $currency);

                // 2. Crédit initial via LedgerService
                // L'environnement est fourni EXPLICITEMENT (= sandbox).
                // Sans contexte, LedgerService::credit() retombe sur
                // ProviderConfig::defaultEnvironment() : sur un déploiement
                // dont PROVIDERS_ENV vaut « production », un bonus fictif de
                // 2500 EUR était écrit au ledger en ARGENT RÉEL. La boucle 17
                // avait corrigé seedDemoTransactions(), mais ce chemin-ci
                // passe par le ledger et avait été manqué.
                \Nexus\Services\LedgerService::credit(
                    $userId,
                    $w['id'],
                    $amount,
                    $currency,
                    'welcome_bonus',
                    'welcome_bonus:register:' . $userId . ':' . $currency,
                    'Bonus de bienvenue à l\'inscription',
                    ['source' => 'registration_seed'],
                    \Nexus\Execution\ExecutionContext::explicit(
                        actorUserId: $userId,
                        environment: \Nexus\Execution\ExecutionEnvironment::SANDBOX,
                        accountType: $accountType
                    )
                );
            }

            self::seedDemoTransactions($pdo, $userId);

            // Comptes de démo (sources & destinations)
            AccountController::seedDemoAccountsAtLogin($pdo, $userId);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                Response::conflict('Un compte existe déjà avec cette adresse email.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $user = self::loadUser($userId);
        self::audit($userId, 'auth.register', 'users', $userId, [
            'account_type' => $accountType,
            'phone'        => $fullPhone,
        ], $request);

        $token = Jwt::encode(['sub' => $userId]);

        Response::success([
            'token' => $token,
            'user'  => self::publicUser($user),
        ], 201);
    }

    /**
     * POST /api/login — vérifie les identifiants.
     *
     * L'identifiant est soit une adresse email, soit un numéro de téléphone
     * au format international (commençant par '+'). Le rate-limit s'applique
     * quelle que soit la méthode d'identification.
     */
    public static function login(Request $request): void
    {
        $identifier = trim((string) $request->input('identifier', ''));
        $password   = (string) $request->input('password', '');

        // Rétro-compatibilité : accepter aussi le champ `email`
        if ($identifier === '') {
            $identifier = strtolower(trim((string) $request->input('email', '')));
        }

        if ($identifier === '' || $password === '') {
            Response::badRequest('Identifiant et mot de passe requis.');
        }

        $pdo = Database::getConnection();
        $ip  = self::clientIp($request);

        // Détection : email ou téléphone ?
        $isPhone = str_starts_with($identifier, '+');
        if ($isPhone) {
            // Normalisation : supprimer tous les espaces
            $identifier = preg_replace('/\s+/', '', $identifier);
        } else {
            $identifier = strtolower($identifier);
        }

        // Nettoyage des tentatives obsolètes (fenêtre glissante).
        $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL ' . self::LOGIN_WINDOW_SECONDS . ' SECOND)');

        // Comptage des échecs récents pour cet identifiant.
        $lookupKey = $isPhone ? 'phone' : 'email';
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :identifier AND success = 0
               AND created_at >= (NOW() - INTERVAL ' . self::LOGIN_WINDOW_SECONDS . ' SECOND)'
        );
        $stmt->execute(['identifier' => $identifier]);

        if ((int) $stmt->fetchColumn() >= self::LOGIN_MAX_ATTEMPTS) {
            Response::tooManyRequests('Trop de tentatives de connexion. Réessayez dans quelques minutes.');
        }

        // Chargement de l'utilisateur par email OU téléphone.
        if ($isPhone) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = :phone AND auth_provider = :provider LIMIT 1');
            $stmt->execute(['phone' => $identifier, 'provider' => 'local']);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND auth_provider = :provider LIMIT 1');
            $stmt->execute(['email' => $identifier, 'provider' => 'local']);
        }
        $user = $stmt->fetch();

        $valid = $user !== false && password_verify($password, $user['password_hash']);

        if (!$valid) {
            // On enregistre l'échec même si l'utilisateur n'existe pas (anti-énumération).
            $pdo->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, 0)')
                ->execute(['email' => $identifier, 'ip' => $ip]);

            Response::unauthorized('Identifiants incorrects.');
        }

        // Succès : purge des tentatives + trace d'audit.
        $pdo->prepare('DELETE FROM login_attempts WHERE email = :email')->execute(['email' => $identifier]);

        $userId = (int) $user['id'];
        self::audit($userId, 'auth.login', 'users', $userId, [$lookupKey => $identifier], $request);

        // Notifications de démo au premier login (idempotent : uniquement si
        // l'utilisateur n'a encore aucune notification).
        NotificationController::seedDemoNotificationsIfEmpty($pdo, $userId);

        // Comptes de démo (sources & destinations) — idempotent.
        AccountController::seedDemoAccountsAtLogin($pdo, $userId);

        $token = Jwt::encode(['sub' => $userId]);

        Response::success([
            'token' => $token,
            'user'  => self::publicUser($user),
        ]);
    }

    /** POST /api/logout — révoque le jeton courant côté serveur. */
    public static function logout(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $payload = $request->attribute('jwt_payload');
        $user    = $request->attribute('user');

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO revoked_tokens (jti, user_id, expires_at)
             VALUES (:jti, :user_id, :expires_at)'
        );
        $stmt->execute([
            'jti'        => (string) $payload['jti'],
            'user_id'    => (int) $user['id'],
            'expires_at' => gmdate('Y-m-d H:i:s', (int) $payload['exp']),
        ]);

        self::audit((int) $user['id'], 'auth.logout', 'users', (int) $user['id'], [], $request);

        Response::success(['revoked' => true]);
    }

    /** GET /api/me — profil complet (protégé). */
    public static function me(Request $request): void
    {
        $request = AuthMiddleware::handle($request);

        Response::success(['user' => $request->attribute('user')]);
    }

    // --- Helpers privés --------------------------------------------------------

    /** Charge un utilisateur complet depuis la base (null si introuvable). */
    private static function loadUser(int $userId): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    /**
     * Insère les transactions de démonstration de bienvenue.
     *
     * À appeler dans la même transaction que la création du compte :
     * les agrégats du dashboard (KPIs, activité, historique) sont ainsi
     * alimentés dès l'inscription.
     */
    private static function seedDemoTransactions(\PDO $pdo, int $userId): void
    {
        // §29 : jamais de données de démonstration en production.
        if (!\Nexus\Core\DemoMode::seedingAllowed()) {
            return;
        }

        $stmt = $pdo->prepare(
            // `environment` est fourni EXPLICITEMENT.
            //
            // La colonne a pour défaut 'production' : omettre le champ
            // marquait ces transactions de démonstration comme de l'argent
            // réel. Vérifié en base avant correctif — 5 lignes de démo en
            // `production` après une simple inscription. Elles apparaissaient
            // donc dans les vues production et dans les totaux comptables.
            'INSERT INTO transactions
                (user_id, type, direction, label, description, amount, currency,
                 amount_ref, ref_currency, amount_xaf, fee, fee_currency,
                 status, provider, destination, execution_time_seconds, created_at,
                 environment)
             VALUES
                (:user_id, :type, :direction, :label, :description, :amount, :currency,
                 :amount_ref, :ref_currency, :amount_xaf, :fee, :fee_currency,
                 :status, :provider, :destination, :execution_time, :created_at,
                 \'sandbox\')'
        );

        foreach (self::DEMO_TRANSACTIONS as $tx) {
            $amount    = (float) $tx['amount'];
            $currency  = $tx['currency'];
            $fee       = round($amount * 0.015, 2);   // ~1,5 % de frais de démo.
            $createdAt = gmdate('Y-m-d H:i:s', time() - ((int) $tx['hours_ago'] * 3600));

            $stmt->execute([
                'user_id'        => $userId,
                'type'           => $tx['type'],
                'direction'      => $tx['direction'],
                'label'          => $tx['label'],
                'description'    => 'Transaction de bienvenue (démo)',
                'amount'         => (string) $amount,
                'currency'       => $currency,
                'amount_ref'     => (string) round($amount * Currency::rateToRef($currency), 2),
                'ref_currency'   => Currency::REF,
                'amount_xaf'     => (string) round($amount * Currency::rateToXaf($currency), 2),
                'fee'            => (string) $fee,
                'fee_currency'   => $currency,
                'status'         => $tx['status'],
                'provider'       => $tx['provider'],
                'destination'    => $tx['destination'],
                'execution_time' => $tx['execution_time'] > 0 ? $tx['execution_time'] : null,
                'created_at'     => $createdAt,
            ]);
        }
    }

    /** Retire les champs sensibles avant envoi au client. */
    private static function publicUser(array $user): array
    {
        unset($user['password_hash'], $user['provider_id']);

        return $user;
    }

    /** Trace une action dans audit_logs (ip + metadata JSON). */
    private static function audit(
        int $userId,
        string $action,
        ?string $entityType,
        ?int $entityId,
        array $metadata,
        Request $request
    ): void {
        $stmt = Database::getConnection()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :metadata, :ip_address)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'metadata'    => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address'  => self::clientIp($request),
        ]);
    }

    /** Adresse IP du client (avec support des proxys communs). */
    private static function clientIp(Request $request): string
    {
        $forwarded = $request->header('X-Forwarded-For', '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }
}
