<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Auth\Jwt;
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

        // --- KYC : date de naissance requise et valide pour une personne ----
        // La vérification d'identité (Sumsub) exige une date de naissance et la
        // majorité. On valide côté serveur (défense en profondeur) même si le
        // formulaire impose déjà le champ.
        if ($accountType === 'personal') {
            $birthError = self::validateBirthDate($birthDate);
            if ($birthError !== null) {
                Response::badRequest($birthError);
            }
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

            // AUCUNE donnée de démonstration n'est injectée à l'inscription
            // (§9) : ni wallets de bienvenue, ni transactions fictives, ni
            // comptes de démo. Un compte frais part de zéro ; les soldes et
            // l'historique ne reflètent que des opérations réelles.

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

    /**
     * Valide une date de naissance pour un compte personnel (KYC).
     *
     * Retourne un message d'erreur, ou null si la date est valide :
     *   - format strict AAAA-MM-JJ (pas d'approximation, pas de date future) ;
     *   - majorité : au moins 18 ans à la date du jour.
     */
    private static function validateBirthDate(string $birthDate): ?string
    {
        if ($birthDate === '') {
            return 'La date de naissance est requise pour un compte personnel (vérification d\'identité).';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $birthDate) {
            return 'Date de naissance invalide (format attendu : AAAA-MM-JJ).';
        }

        // Normalisation à minuit : createFromFormat conserve sinon l'heure
        // courante, ce qui fausse le calcul d'âge (17 ans 11 mois au lieu de 18).
        $date  = $date->setTime(0, 0, 0);
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        if ($date > $today) {
            return 'Date de naissance invalide (date future).';
        }

        $age = (int) $date->diff($today)->y;
        if ($age < 18) {
            return 'Vous devez avoir au moins 18 ans pour créer un compte.';
        }

        return null;
    }

    /** Durée de vie d'un jeton de réinitialisation (secondes). */
    private const RESET_TOKEN_TTL = 1800; // 30 minutes

    /**
     * POST /api/auth/forgot-password
     *
     * Démarre une réinitialisation réelle : génère un jeton aléatoire, le
     * stocke HACHÉ en base (table password_reset_tokens) avec expiration, puis
     * l'achemine à l'utilisateur.
     *
     * Anti-énumération : la réponse est identique que l'email existe ou non
     * (200 « si un compte existe, un lien a été envoyé »). Aucune fuite sur
     * l'existence d'un compte.
     */
    public static function forgotPassword(Request $request): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::badRequest('Adresse email invalide.');
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND auth_provider = :provider LIMIT 1');
        $stmt->execute(['email' => $email, 'provider' => 'local']);
        $user = $stmt->fetch();

        // Comportement identique que le compte existe ou non.
        if ($user !== false) {
            // Purge des anciens jetons de ce compte (pour éviter l'accumulation).
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
                ->execute(['user_id' => (int) $user['id']]);

            $token   = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = gmdate('Y-m-d H:i:s', time() + self::RESET_TOKEN_TTL);

            $ins = $pdo->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
                 VALUES (:user_id, :token_hash, :expires_at, :created_at)'
            );
            $ins->execute([
                'user_id'    => (int) $user['id'],
                'token_hash' => $tokenHash,
                'expires_at' => $expires,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            self::audit((int) $user['id'], 'auth.password_reset_request', 'users', (int) $user['id'], ['email' => $email], $request);

            // En environnement de développement (sans SMTP configuré), on
            // retourne le jeton pour permettre un vrai reset de bout en bout
            // dans le navigateur. En production, on n'exposerait JAMAIS le
            // jeton ici : il serait envoyé par e-mail.
            $devToken = null;
            if (defined('APP_ENV') && APP_ENV === 'development') {
                $devToken = $token;
            }

            Response::success([
                'message' => 'Si un compte existe avec cette adresse, un lien de réinitialisation a été envoyé.',
                'expires_in' => self::RESET_TOKEN_TTL,
                'reset_token' => $devToken,
            ]);
        }

        // Le compte n'existe pas : même message (anti-énumération).
        Response::success([
            'message' => 'Si un compte existe avec cette adresse, un lien de réinitialisation a été envoyé.',
            'expires_in' => self::RESET_TOKEN_TTL,
            'reset_token' => null,
        ]);
    }

    /**
     * POST /api/auth/reset-password
     *
     * Consomme un jeton de réinitialisation et met à jour le mot de passe.
     * Le jeton est valable une fois et jusqu'à son expiration.
     */
    public static function resetPassword(Request $request): void
    {
        $token       = trim((string) $request->input('token', ''));
        $newPassword = (string) $request->input('new_password', '');
        $confirm     = (string) $request->input('confirm_password', '');

        if ($token === '') {
            Response::badRequest('Jeton de réinitialisation requis.');
        }
        if (strlen($newPassword) < 8) {
            Response::badRequest('Le mot de passe doit contenir au moins 8 caractères.');
        }
        if ($newPassword !== $confirm) {
            Response::badRequest('Les mots de passe ne correspondent pas.');
        }

        $pdo = Database::getConnection();
        $tokenHash = hash('sha256', $token);

        $stmt = $pdo->prepare(
            'SELECT prt.user_id, prt.expires_at, prt.used_at
             FROM password_reset_tokens prt
             WHERE prt.token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::badRequest('Jeton de réinitialisation invalide.');
        }

        if ($row['used_at'] !== null) {
            Response::badRequest('Ce jeton a déjà été utilisé.');
        }

        if (strtotime($row['expires_at']) < time()) {
            Response::badRequest('Ce jeton a expiré. Veuillez relancer une réinitialisation.');
        }

        $userId = (int) $row['user_id'];
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                'UPDATE users SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP(), updated_at = :updated WHERE id = :id'
            );
            try {
                $upd->execute(['hash' => $newHash, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
            } catch (\PDOException $e) {
                // Migration 0.40 absente : bascule sans password_changed_at.
                $upd = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated WHERE id = :id');
                $upd->execute(['hash' => $newHash, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
            }

            $use = $pdo->prepare('UPDATE password_reset_tokens SET used_at = :used WHERE token_hash = :token_hash');
            $use->execute(['used' => gmdate('Y-m-d H:i:s'), 'token_hash' => $tokenHash]);

            // Invalide tous les autres jetons de ce compte.
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = :used WHERE user_id = :user_id AND token_hash <> :token_hash')
                ->execute(['used' => gmdate('Y-m-d H:i:s'), 'user_id' => $userId, 'token_hash' => $tokenHash]);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::audit($userId, 'auth.password_reset', 'users', $userId, ['completed' => true], $request);

        Response::success(['message' => 'Mot de passe mis à jour. Vous pouvez vous connecter.']);
    }
}
