<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Auth\GoogleIdTokenVerifier;
use Nexus\Auth\Jwt;
use Nexus\Auth\PlatformIdentity;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\PlatformRole;
use Nexus\Services\Mailer;
use PDO;
use PDOException;

// Note : AccountController est importé via autoloader, pas besoin de use

/**
 * Authentification : inscription, connexion (email/téléphone ou Google), déconnexion et profil.
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
        self::ensureEmailVerificationSchema($pdo);

        // --- Email unique (réouverture possible si le compte client est CLOSED) -
        $stmt = $pdo->prepare(
            'SELECT id, status, platform_role, auth_provider FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $reopenClosed = false;
        if (is_array($existing)) {
            $existingStatus = strtoupper((string) ($existing['status'] ?? ''));
            $existingRole = (string) ($existing['platform_role'] ?? PlatformRole::USER);
            $existingProvider = (string) ($existing['auth_provider'] ?? 'local');
            // Un compte client clôturé peut être rouvert via une nouvelle inscription :
            // sans ça, l'e-mail reste bloqué à jamais et l'utilisateur « ne voit » plus son compte.
            if (
                $existingStatus === 'CLOSED'
                && $existingRole === PlatformRole::USER
                && $existingProvider === 'local'
            ) {
                $reopenClosed = true;
            } else {
                Response::conflict('Un compte existe déjà avec cette adresse email.');
            }
        }

        // --- Création / réouverture (transaction) -----------------------------
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userId = 0;
        $verifyToken = '';

        try {
            $pdo->beginTransaction();

            if ($reopenClosed) {
                $userId = (int) $existing['id'];
                try {
                    $pdo->prepare(
                        'UPDATE users SET full_name = :full_name, email_verified_at = NULL, phone = :phone,
                                password_hash = :password_hash, account_type = :account_type, status = :status,
                                kyc_level = :kyc_level, birth_date = :birth_date, gender = :gender, city = :city,
                                postal_code = :postal_code, address = :address, country_of_residence = :country_of_residence,
                                company_name = :company_name, legal_form = :legal_form,
                                company_registration_number = :reg_number, industry = :industry,
                                company_size = :company_size, website = :website, password_changed_at = UTC_TIMESTAMP(),
                                updated_at = NOW()
                          WHERE id = :id'
                    )->execute([
                        'full_name'     => $fullName,
                        'phone'         => $fullPhone,
                        'password_hash' => $passwordHash,
                        'account_type'  => $accountType,
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
                        'id'            => $userId,
                    ]);
                } catch (PDOException) {
                    $pdo->prepare(
                        'UPDATE users SET full_name = :full_name, email_verified_at = NULL, phone = :phone,
                                password_hash = :password_hash, account_type = :account_type, status = :status,
                                kyc_level = :kyc_level, birth_date = :birth_date, gender = :gender, city = :city,
                                postal_code = :postal_code, address = :address, country_of_residence = :country_of_residence,
                                company_name = :company_name, legal_form = :legal_form,
                                company_registration_number = :reg_number, industry = :industry,
                                company_size = :company_size, website = :website, updated_at = NOW()
                          WHERE id = :id'
                    )->execute([
                        'full_name'     => $fullName,
                        'phone'         => $fullPhone,
                        'password_hash' => $passwordHash,
                        'account_type'  => $accountType,
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
                        'id'            => $userId,
                    ]);
                }
                $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = :id')
                    ->execute(['id' => $userId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, email_verified_at, phone, password_hash, account_type, auth_provider, status, kyc_level,
                                        birth_date, gender, city, postal_code, address, country_of_residence,
                                        company_name, legal_form, company_registration_number, industry, company_size, website)
                     VALUES (:full_name, :email, :email_verified_at, :phone, :password_hash, :account_type, :auth_provider, :status, :kyc_level,
                             :birth_date, :gender, :city, :postal_code, :address, :country_of_residence,
                             :company_name, :legal_form, :reg_number, :industry, :company_size, :website)'
                );
                $stmt->execute([
                    'full_name'     => $fullName,
                    'email'         => $email,
                    'email_verified_at' => null,
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
            }

            $verifyToken = self::issueHashedToken(
                $pdo,
                'email_verification_tokens',
                $userId,
                self::VERIFY_TOKEN_TTL
            );

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
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        try {
            self::audit($userId, $reopenClosed ? 'auth.register_reopen' : 'auth.register', 'users', $userId, [
                'account_type' => $accountType,
                'phone'        => $fullPhone,
                'email_confirmation' => true,
                'reopened' => $reopenClosed,
            ], $request);
        } catch (\Throwable $e) {
            error_log('[NEXUS API] audit register: ' . $e->getMessage());
        }

        $verifyUrl = Mailer::frontendBaseUrl() . '/verify-email?token=' . urlencode($verifyToken);
        Mailer::sendEmailVerification(
            $email,
            $fullName,
            $verifyUrl,
            (int) (self::VERIFY_TOKEN_TTL / 3600)
        );

        Response::success([
            'email_confirmation_required' => true,
            'email' => $email,
            'message' => 'Un e-mail de confirmation a été envoyé. Confirmez votre adresse avant de vous connecter.',
            'expires_in' => self::VERIFY_TOKEN_TTL,
            'verify_url' => Mailer::isDevelopment() ? $verifyUrl : null,
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

        // Chargement par email OU téléphone. Un compte Google peut aussi
        // avoir un mot de passe Nexus (défini plus tard dans les paramètres).
        if ($isPhone) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = :phone LIMIT 1');
            $stmt->execute(['phone' => $identifier]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $identifier]);
        }
        $user = $stmt->fetch();

        $hash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';
        $valid = $user !== false
            && \Nexus\Auth\PasswordHash::isUsable($hash)
            && password_verify($password, $hash);

        if (!$valid) {
            // On enregistre l'échec même si l'utilisateur n'existe pas (anti-énumération).
            $pdo->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, 0)')
                ->execute(['email' => $identifier, 'ip' => $ip]);

            Response::unauthorized('Identifiants incorrects.');
        }

        // `account_type` décrit le client, jamais ses privilèges. Pour un
        // employé actif, la relation employees fournit le rôle interne
        // effectif. Les rôles inconnus et employés inactifs sont refusés.
        $user = PlatformIdentity::resolve($pdo, $user);
        PlatformIdentity::assertLoginAllowed($user);
        self::assertEmailVerified($user);
        self::assertLoginAudience($request, $user);

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

    /**
     * GET /api/auth/google-config — client IDs publics (GIS / Capacitor).
     * Le secret n'est pas un secret : l'ID web est destiné au navigateur.
     */
    public static function googleConfig(): void
    {
        $web = GoogleIdTokenVerifier::webClientId();
        $ios = GoogleIdTokenVerifier::iosClientId();

        Response::success([
            'enabled' => $web !== '',
            'client_id' => $web !== '' ? $web : null,
            'ios_client_id' => $ios !== '' ? $ios : null,
        ]);
    }

    /**
     * POST /api/auth/google — connexion ou inscription via ID Token Google.
     *
     * `intent=login` : n'ouvre une session que si le compte Google existe déjà.
     * `intent=register` : crée uniquement un compte personnel. Un compte e-mail
     * local n'est jamais fusionné : il faut d'abord confirmer l'adresse.
     */
    public static function google(Request $request): void
    {
        $idToken = trim((string) $request->input('credential', ''));
        if ($idToken === '') {
            Response::badRequest('Le credential Google est requis.');
        }

        $intent = strtolower(trim((string) $request->input('intent', 'login')));
        if (!in_array($intent, ['login', 'register'], true)) {
            Response::badRequest('L\'intention Google doit être « login » ou « register ».');
        }

        $accountType = 'personal';
        if ($intent === 'register') {
            $requestedType = (string) $request->input('account_type', 'personal');
            if ($requestedType === '') {
                $requestedType = 'personal';
            }
            if ($requestedType !== 'personal') {
                Response::error(
                    'L\'inscription Google est réservée aux comptes personnels. Créez un compte entreprise par e-mail.',
                    400,
                    'GOOGLE_PERSONAL_ONLY'
                );
            }
            $birthDate = trim((string) $request->input('birth_date', ''));
            $countryCode = strtoupper(trim((string) $request->input('country_of_residence', '')));
            $birthError = self::validateBirthDate($birthDate);
            if ($birthError !== null) {
                Response::badRequest($birthError);
            }
            if ($countryCode === '' || strlen($countryCode) !== 2 || !preg_match('/^[A-Z]{2}$/', $countryCode)) {
                Response::badRequest('Le pays de résidence est requis (code ISO-2, ex. FR).');
            }
        }

        try {
            $google = GoogleIdTokenVerifier::verify($idToken);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
        }

        if (!$google['email_verified']) {
            throw new HttpException(
                403,
                'Google n\'a pas confirmé cette adresse e-mail. Utilisez une autre méthode de connexion.',
                'EMAIL_NOT_VERIFIED'
            );
        }

        $pdo = Database::getConnection();
        self::ensureEmailVerificationSchema($pdo);
        $created = false;

        $stmt = $pdo->prepare(
            'SELECT * FROM users WHERE auth_provider = :provider AND provider_id = :provider_id LIMIT 1'
        );
        $stmt->execute(['provider' => 'google', 'provider_id' => $google['sub']]);
        $user = $stmt->fetch();

        if ($user === false) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $google['email']]);
            $byEmail = $stmt->fetch();
            if ($byEmail !== false) {
                $existingProvider = (string) ($byEmail['auth_provider'] ?? 'local');
                if ($existingProvider === 'google') {
                    Response::error(
                        'Ce compte Google ne correspond pas à cette identité. Contactez le support.',
                        409,
                        'ACCOUNT_EXISTS'
                    );
                }
                Response::error(
                    'Ce compte a été créé par e-mail. Confirmez votre adresse si ce n\'est pas déjà fait, puis connectez-vous avec votre mot de passe.',
                    409,
                    'ACCOUNT_EXISTS'
                );
            }

            if ($intent !== 'register') {
                Response::error(
                    'Aucun compte Nexus n\'est associé à cet e-mail Google. Créez d\'abord un compte.',
                    404,
                    'GOOGLE_ACCOUNT_NOT_FOUND'
                );
            }

            $fullName = trim($google['name']);
            if ($fullName === '') {
                $fullName = explode('@', $google['email'])[0];
            }
            $fullName = mb_substr($fullName, 0, 120);

            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO users (full_name, email, email_verified_at, phone, password_hash, account_type, auth_provider, provider_id, status, kyc_level, birth_date, country_of_residence)
                     VALUES (:full_name, :email, UTC_TIMESTAMP(), NULL, :password_hash, :account_type, :auth_provider, :provider_id, :status, :kyc_level, :birth_date, :country_of_residence)'
                );
                $insert->execute([
                    'full_name' => $fullName,
                    'email' => $google['email'],
                    'password_hash' => '',
                    'account_type' => $accountType,
                    'auth_provider' => 'google',
                    'provider_id' => $google['sub'],
                    'status' => 'PENDING',
                    'kyc_level' => 'none',
                    'birth_date' => $birthDate,
                    'country_of_residence' => $countryCode,
                ]);
                $userId = (int) $pdo->lastInsertId();
                $pdo->commit();
                $created = true;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() === '23000') {
                    Response::error(
                        'Ce compte a été créé par e-mail. Confirmez votre adresse si ce n\'est pas déjà fait, puis connectez-vous avec votre mot de passe.',
                        409,
                        'ACCOUNT_EXISTS'
                    );
                }
                throw $e;
            }

            $user = self::loadUser($userId);
            if ($user === null) {
                Response::serverError();
            }

            try {
                self::audit($userId, 'auth.google_register', 'users', $userId, [
                    'account_type' => $accountType,
                ], $request);
            } catch (\Throwable $e) {
                error_log('[NEXUS API] audit google register: ' . $e->getMessage());
            }

            self::sendWelcomeEmail($google['email'], $fullName, $accountType);
        } else {
            $userId = (int) $user['id'];
            try {
                self::audit($userId, 'auth.google_login', 'users', $userId, [], $request);
            } catch (\Throwable $e) {
                error_log('[NEXUS API] audit google login: ' . $e->getMessage());
            }
        }

        $user = PlatformIdentity::resolve($pdo, $user);
        PlatformIdentity::assertLoginAllowed($user);
        self::assertEmailVerified($user);
        self::assertLoginAudience($request, $user);

        $token = Jwt::encode(['sub' => (int) $user['id']]);

        Response::success([
            'token' => $token,
            'user' => self::publicUser($user),
            'created' => $created,
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

    private static function sendWelcomeEmail(string $email, string $name, string $accountType): void
    {
        if ($email === '') {
            return;
        }
        try {
            Mailer::sendWelcome($email, $name, $accountType);
        } catch (\Throwable $e) {
            error_log('[NEXUS API] welcome email: ' . $e->getMessage());
        }
    }

    /** Retire les champs sensibles avant envoi au client. */
    private static function publicUser(array $user): array
    {
        if (!array_key_exists('has_password', $user)) {
            $user['has_password'] = \Nexus\Auth\PasswordHash::isUsable((string) ($user['password_hash'] ?? ''));
        }
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
    public static function validateBirthDate(string $birthDate): ?string
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

    /** Durée de vie d'un jeton de confirmation d'e-mail (secondes). */
    private const VERIFY_TOKEN_TTL = 86400; // 24 heures

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

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
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

            $portal = self::portalForUserId($pdo, (int) $user['id']);
            $resetUrl = Mailer::frontendBaseUrl() . '/forgot-password?token=' . urlencode($token);
            if ($portal !== '') {
                $resetUrl .= '&portal=' . urlencode($portal);
            }

            $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :id LIMIT 1');
            $nameStmt->execute(['id' => (int) $user['id']]);
            $fullName = (string) ($nameStmt->fetchColumn() ?: '');

            Mailer::sendPasswordReset(
                $email,
                $fullName,
                $resetUrl,
                (int) (self::RESET_TOKEN_TTL / 60)
            );

            Response::success([
                'message' => 'Si un compte existe avec cette adresse, un lien de réinitialisation a été envoyé.',
                'expires_in' => self::RESET_TOKEN_TTL,
                'reset_url' => Mailer::isDevelopment() ? $resetUrl : null,
            ]);
        }

        // Le compte n'existe pas : même message (anti-énumération).
        Response::success([
            'message' => 'Si un compte existe avec cette adresse, un lien de réinitialisation a été envoyé.',
            'expires_in' => self::RESET_TOKEN_TTL,
            'reset_url' => null,
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

        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
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
            } catch (\Throwable $e) {
                // Migration 0.40 absente : PDO peut lever un warning (ErrorException)
                // qui abort la transaction InnoDB. On recommence sans la colonne.
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                    $pdo->beginTransaction();
                }
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

        $account = $pdo->prepare(
            'SELECT u.platform_role, e.role AS employee_role
             FROM users u
             LEFT JOIN employees e ON e.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $account->execute(['id' => $userId]);
        $row = $account->fetch() ?: [];
        $effectiveRole = (string) (($row['employee_role'] ?? '') !== ''
            ? $row['employee_role']
            : ($row['platform_role'] ?? PlatformRole::USER));
        $loginPath = self::loginPathForRole($effectiveRole);

        Response::success([
            'message' => 'Mot de passe mis à jour. Vous pouvez vous connecter.',
            'login_path' => $loginPath,
        ]);
    }

    /**
     * POST /api/auth/verify-email — consomme le jeton de confirmation.
     */
    public static function verifyEmail(Request $request): void
    {
        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            Response::badRequest('Jeton de confirmation requis.');
        }

        $pdo = Database::getConnection();
        self::ensureEmailVerificationSchema($pdo);
        $row = self::findHashedToken($pdo, 'email_verification_tokens', $token);
        if ($row === null) {
            Response::badRequest('Lien de confirmation invalide ou expiré.');
        }

        $userId = (int) $row['user_id'];
        $already = $pdo->prepare('SELECT email_verified_at FROM users WHERE id = :id LIMIT 1');
        $already->execute(['id' => $userId]);
        $verifiedAt = $already->fetchColumn();
        if ($verifiedAt !== false && $verifiedAt !== null && $verifiedAt !== '') {
            Response::success([
                'verified' => true,
                'message' => 'Adresse e-mail confirmée. Vous pouvez vous connecter.',
            ]);
        }

        if ($row['used_at'] !== null) {
            Response::badRequest('Ce lien de confirmation a déjà été utilisé.');
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            Response::badRequest('Ce lien de confirmation a expiré. Demandez-en un nouveau.');
        }

        $pdo->beginTransaction();
        try {
            try {
                $pdo->prepare(
                    'UPDATE users
                        SET email_verified_at = UTC_TIMESTAMP(),
                            status = CASE WHEN status = \'PENDING\' THEN \'ACTIVE\' ELSE status END,
                            updated_at = UTC_TIMESTAMP()
                      WHERE id = :id'
                )->execute(['id' => $userId]);
            } catch (PDOException) {
                $pdo->prepare(
                    'UPDATE users SET email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id'
                )->execute(['id' => $userId]);
                $pdo->prepare(
                    'UPDATE users SET status = \'ACTIVE\', updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = \'PENDING\''
                )->execute(['id' => $userId]);
            }
            $pdo->prepare(
                'UPDATE email_verification_tokens SET used_at = :used WHERE token_hash = :token_hash'
            )->execute([
                'used' => gmdate('Y-m-d H:i:s'),
                'token_hash' => hash('sha256', $token),
            ]);
            $pdo->prepare(
                'UPDATE email_verification_tokens SET used_at = :used WHERE user_id = :user_id AND used_at IS NULL'
            )->execute([
                'used' => gmdate('Y-m-d H:i:s'),
                'user_id' => $userId,
            ]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        self::audit($userId, 'auth.email_verified', 'users', $userId, ['completed' => true], $request);

        $welcomeUser = self::loadUser($userId);
        if ($welcomeUser !== null) {
            self::sendWelcomeEmail(
                (string) ($welcomeUser['email'] ?? ''),
                (string) ($welcomeUser['full_name'] ?? ''),
                (string) ($welcomeUser['account_type'] ?? 'personal')
            );
        }

        Response::success([
            'verified' => true,
            'message' => 'Adresse e-mail confirmée. Vous pouvez vous connecter.',
        ]);
    }

    /**
     * POST /api/auth/resend-verification
     *
     * Anti-énumération : même réponse que le compte existe, soit déjà confirmé.
     */
    public static function resendVerification(Request $request): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::badRequest('Adresse email invalide.');
        }

        $pdo = Database::getConnection();
        self::ensureEmailVerificationSchema($pdo);
        $stmt = $pdo->prepare(
            'SELECT id, full_name, email_verified_at FROM users WHERE email = :email AND auth_provider = :provider LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'provider' => 'local']);
        $user = $stmt->fetch();

        if ($user !== false && ($user['email_verified_at'] === null || $user['email_verified_at'] === '')) {
            $token = self::issueHashedToken(
                $pdo,
                'email_verification_tokens',
                (int) $user['id'],
                self::VERIFY_TOKEN_TTL
            );
            $verifyUrl = Mailer::frontendBaseUrl() . '/verify-email?token=' . urlencode($token);
            Mailer::sendEmailVerification(
                $email,
                (string) $user['full_name'],
                $verifyUrl,
                (int) (self::VERIFY_TOKEN_TTL / 3600)
            );
            self::audit((int) $user['id'], 'auth.email_verification_resend', 'users', (int) $user['id'], ['email' => $email], $request);

            Response::success([
                'message' => 'Si un compte non confirmé existe avec cette adresse, un nouvel e-mail a été envoyé.',
                'expires_in' => self::VERIFY_TOKEN_TTL,
                'verify_url' => Mailer::isDevelopment() ? $verifyUrl : null,
            ]);
        }

        Response::success([
            'message' => 'Si un compte non confirmé existe avec cette adresse, un nouvel e-mail a été envoyé.',
            'expires_in' => self::VERIFY_TOKEN_TTL,
            'verify_url' => null,
        ]);
    }

    /**
     * Isole les portails : un employé ne doit jamais entrer par le login client,
     * et un client ne doit jamais entrer par le login employé/admin.
     *
     * @param array<string,mixed> $user
     */
    private static function assertLoginAudience(Request $request, array $user): void
    {
        $audience = strtolower(trim((string) $request->input('audience', '')));
        if ($audience === '') {
            return;
        }

        $kind = PlatformRole::identityKind($user);
        $allowed = match ($audience) {
            'client' => $kind === 'client',
            'staff' => $kind === 'employee',
            'admin' => $kind === 'superadmin',
            default => false,
        };
        if ($allowed) {
            return;
        }

        $message = match ($audience) {
            'client' => 'Ce compte est interne. Utilisez le portail employés ou Super Admin.',
            'staff' => 'Ce portail est réservé aux employés internes.',
            'admin' => 'Ce portail est réservé au Super Admin.',
            default => 'Portail d\'authentification invalide.',
        };
        Response::error($message, 403, 'WRONG_PORTAL');
    }

    private static function loginPathForRole(string $role): string
    {
        if ($role === PlatformRole::SUPERADMIN) {
            return '/admin-login';
        }
        if (PlatformRole::isInternal($role)) {
            return '/staff-login';
        }
        return '/login';
    }

    /**
     * Bloque la connexion client tant que l'e-mail n'est pas confirmé.
     * Les comptes créés avant la migration n'ont pas la clé : on les laisse passer.
     *
     * @param array<string,mixed> $user
     */
    private static function assertEmailVerified(array $user): void
    {
        if (!array_key_exists('email_verified_at', $user)) {
            return;
        }
        $kind = (string) ($user['identity_kind'] ?? '');
        if ($kind !== '' && $kind !== 'client') {
            return;
        }
        $verifiedAt = $user['email_verified_at'];
        if ($verifiedAt !== null && $verifiedAt !== '') {
            return;
        }

        throw new HttpException(
            403,
            'Confirmez votre adresse e-mail avant de vous connecter. Consultez votre boîte de réception.',
            'EMAIL_NOT_VERIFIED'
        );
    }

    private static function portalForUserId(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare(
            'SELECT u.platform_role, e.role AS employee_role
             FROM users u
             LEFT JOIN employees e ON e.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch() ?: [];
        $role = (string) (($row['employee_role'] ?? '') !== ''
            ? $row['employee_role']
            : ($row['platform_role'] ?? PlatformRole::USER));

        if ($role === PlatformRole::SUPERADMIN) {
            return 'admin';
        }
        if (PlatformRole::isInternal($role)) {
            return 'staff';
        }

        return '';
    }

    /**
     * Crée colonne + table de confirmation si absentes (prod InfinityFree :
     * MySQL distant fermé, migrations phpMyAdmin souvent pas appliquées).
     */
    private static function ensureEmailVerificationSchema(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!self::schemaHasColumn($pdo, $dbName, 'users', 'email_verified_at')) {
            try {
                $pdo->exec(
                    'ALTER TABLE `users`
                     ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP
                     AFTER `email`'
                );
            } catch (PDOException $e) {
                if (!self::isIgnorableSchemaError($e)) {
                    throw $e;
                }
            }
        }

        if (!self::schemaHasTable($pdo, $dbName, 'email_verification_tokens')) {
            try {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS `email_verification_tokens` (
                      `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      `user_id`     BIGINT UNSIGNED NOT NULL,
                      `token_hash`  CHAR(64) NOT NULL,
                      `expires_at`  DATETIME NOT NULL,
                      `used_at`     DATETIME DEFAULT NULL,
                      `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `uq_verify_token_hash` (`token_hash`),
                      KEY `idx_verify_user` (`user_id`),
                      KEY `idx_verify_expires` (`expires_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (PDOException $e) {
                if (!self::isIgnorableSchemaError($e)) {
                    throw $e;
                }
            }
        }

        $ready = true;
    }

    private static function schemaHasColumn(PDO $pdo, string $db, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :col'
            );
            $stmt->execute(['db' => $db, 'table' => $table, 'col' => $column]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    private static function schemaHasTable(PDO $pdo, string $db, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table'
            );
            $stmt->execute(['db' => $db, 'table' => $table]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    private static function isIgnorableSchemaError(PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();

        return $sqlState === '42S21'
            || $sqlState === '42S01'
            || str_contains($message, 'Duplicate column')
            || str_contains($message, 'already exists');
    }

    /** Émet un jeton brut, stocke uniquement le SHA-256, invalide les jetons précédents. */
    private static function issueHashedToken(PDO $pdo, string $table, int $userId, int $ttlSeconds): string
    {
        if (!in_array($table, ['password_reset_tokens', 'email_verification_tokens'], true)) {
            throw new \InvalidArgumentException('Table de jeton inconnue.');
        }

        $pdo->prepare("DELETE FROM {$table} WHERE user_id = :user_id")
            ->execute(['user_id' => $userId]);

        $token = bin2hex(random_bytes(32));
        $ins = $pdo->prepare(
            "INSERT INTO {$table} (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :created_at)"
        );
        $ins->execute([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findHashedToken(PDO $pdo, string $table, string $token): ?array
    {
        if (!in_array($table, ['password_reset_tokens', 'email_verification_tokens'], true)) {
            throw new \InvalidArgumentException('Table de jeton inconnue.');
        }

        $stmt = $pdo->prepare(
            "SELECT user_id, expires_at, used_at FROM {$table} WHERE token_hash = :token_hash LIMIT 1"
        );
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
