<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Auth\PasswordHash;
use Nexus\Auth\PlatformIdentity;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Kyc\KycStatus;
use Nexus\Kyc\KycSubjectType;
use Nexus\Kyc\SumsubAdapter;
use Nexus\Services\KycService;
use PDO;

/**
 * Gestion du profil utilisateur : mise à jour, sécurité, sessions.
 *
 * Toutes les méthodes sont statiques et reçoivent la requête normalisée.
 */
final class UserController
{
    /** GET /api/users/me — retourne le profil complet (alias de AuthController::me). */
    public static function me(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        Response::success(['user' => $request->attribute('user')]);
    }

    /** PUT /api/users/me — met à jour le profil utilisateur. */
    public static function updateProfile(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $userId = (int) $user['id'];

        $fullName = trim((string) $request->input('full_name', ''));
        $phone = trim((string) $request->input('phone', ''));
        $countryOfResidence = trim((string) $request->input('country_of_residence', ''));
        // Avatar : URL http(s), data URI image, ou '' pour effacer.
        // Les data URI sont persistées en fichier sous /uploads/avatars/ (la
        // colonne users.avatar est TEXT ≤ 65 Ko — trop petite pour une photo
        // base64) ; on stocke uniquement le chemin public court.
        $avatar = $request->input('avatar', null);

        // Validation
        if ($fullName !== '' && mb_strlen($fullName) > 120) {
            Response::badRequest('Le nom complet ne peut pas dépasser 120 caractères.');
        }

        if ($phone !== '' && strlen($phone) > 20) {
            Response::badRequest('Le numéro de téléphone ne peut pas dépasser 20 caractères.');
        }

        $storedAvatar = null;
        if ($avatar !== null && $avatar !== '') {
            if (!is_string($avatar)) {
                Response::badRequest('Avatar invalide : attendu une URL http(s) ou une image data URI.');
            }
            try {
                $storedAvatar = self::normalizeAvatarForStorage(
                    $userId,
                    $avatar,
                    isset($user['avatar']) && is_string($user['avatar']) ? $user['avatar'] : null
                );
            } catch (\InvalidArgumentException $e) {
                Response::badRequest($e->getMessage());
            } catch (\RuntimeException $e) {
                Response::serverError($e->getMessage());
            }
        }

        $pdo = Database::getConnection();

        try {
            $updates = [];
            $params = [];
            // Noms des champs réellement modifiés, pour l'audit. `$updates`
            // contient des fragments SQL : en faire `array_keys()` produisait
            // `[0,1,2]`, c'est-à-dire une trace inexploitable.
            $changedFields = [];

            if ($fullName !== '') {
                $updates[] = 'full_name = :full_name';
                $params[':full_name'] = $fullName;
                $changedFields[] = 'full_name';
            }

            if ($phone !== '') {
                $updates[] = 'phone = :phone';
                $params[':phone'] = $phone;
                $changedFields[] = 'phone';
            }

            if ($countryOfResidence !== '') {
                $cc = strtoupper(trim($countryOfResidence));
                if (strlen($cc) !== 2 || !preg_match('/^[A-Z]{2}$/', $cc)) {
                    Response::badRequest('Le pays de résidence doit être un code ISO-2 (ex. FR, CG).');
                }
                $updates[] = 'country_of_residence = :country_of_residence';
                $params[':country_of_residence'] = $cc;
                $changedFields[] = 'country_of_residence';
            }

            $birthDate = trim((string) $request->input('birth_date', ''));
            if ($birthDate !== '') {
                $birthError = AuthController::validateBirthDate($birthDate);
                if ($birthError !== null) {
                    Response::badRequest($birthError);
                }
                $updates[] = 'birth_date = :birth_date';
                $params[':birth_date'] = $birthDate;
                $changedFields[] = 'birth_date';
            }

            // Avatar : présent dans le payload (même '' pour effacer) → mise à jour.
            if ($avatar !== null) {
                $previous = isset($user['avatar']) && is_string($user['avatar']) ? $user['avatar'] : null;
                if ($avatar === '') {
                    self::deleteLocalAvatarFile($previous);
                    $params[':avatar'] = null;
                    $storedAvatar = null;
                } else {
                    $params[':avatar'] = $storedAvatar;
                }
                $updates[] = 'avatar = :avatar';
                $changedFields[] = 'avatar';
            }

            // Validation AVANT l'ouverture de la transaction : Response::badRequest()
            // interrompt le flux (exit en prod, exception en test) et laissait
            // sinon une transaction ouverte sur la connexion PDO partagée.
            if (empty($updates)) {
                Response::badRequest('Aucune donnée à mettre à jour.');
            }

            $params[':id'] = $userId;

            $pdo->beginTransaction();

            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pdo->commit();

            // Pays de résidence → Sumsub fixedInfo (hors transaction : HTTP externe).
            $sumsubSync = null;
            if (in_array('country_of_residence', $changedFields, true)) {
                $sumsubSync = self::syncResidenceCountryToSumsub(
                    $pdo,
                    $user,
                    (string) $params[':country_of_residence']
                );
            }

            // Audit log
            self::audit($userId, 'profile_updated', null, null, [
                'fields' => $changedFields,
                'sumsub' => $sumsubSync,
            ], $request);

            $payload = ['updated' => true];
            if (in_array('avatar', $changedFields, true)) {
                $payload['avatar'] = $storedAvatar;
            }
            if (is_array($sumsubSync)) {
                $payload['sumsub'] = $sumsubSync;
            }

            $freshStmt = $pdo->prepare(
                'SELECT id, full_name, email, phone, account_type, platform_role, auth_provider, status,
                        kyc_level, kyb_status, avatar, country_of_residence, birth_date, created_at
                 FROM users WHERE id = :id LIMIT 1'
            );
            $freshStmt->execute([':id' => $userId]);
            $fresh = $freshStmt->fetch();
            if (is_array($fresh)) {
                $payload['user'] = PlatformIdentity::resolve($pdo, $fresh);
            }

            Response::success($payload);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('Erreur lors de la mise à jour du profil.');
        }
    }

    /**
     * Convertit une data URI image en fichier public, ou valide une URL http(s).
     *
     * @throws \InvalidArgumentException message prêt pour Response::badRequest
     */
    private static function normalizeAvatarForStorage(int $userId, string $avatar, ?string $previous): string
    {
        $isDataUri = str_starts_with($avatar, 'data:image/') && str_contains($avatar, ';base64,');
        $isUrl = str_starts_with($avatar, 'https://') || str_starts_with($avatar, 'http://');

        if ($isUrl) {
            if (strlen($avatar) > 2048) {
                throw new \InvalidArgumentException('URL d\'avatar trop longue.');
            }
            if ($previous !== null && $previous !== $avatar) {
                self::deleteLocalAvatarFile($previous);
            }
            return $avatar;
        }

        if (!$isDataUri) {
            throw new \InvalidArgumentException('Avatar invalide : attendu une URL http(s) ou une image data URI.');
        }

        // Plafond transit (~500 Ko binaires → ~670 Ko base64 + en-tête).
        if (strlen($avatar) > 720000) {
            throw new \InvalidArgumentException("L'avatar ne peut pas dépasser 500 Ko.");
        }

        if (!preg_match('#^data:image/(jpeg|jpg|png|gif|webp);base64,#i', $avatar, $m)) {
            throw new \InvalidArgumentException('Format d\'image non supporté (JPEG, PNG, GIF ou WebP).');
        }

        $extMap = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
        $ext = $extMap[strtolower($m[1])] ?? 'png';
        $b64 = substr($avatar, (int) strpos($avatar, ',') + 1);
        $binary = base64_decode($b64, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('Avatar invalide : décodage base64 impossible.');
        }
        if (strlen($binary) > 500000) {
            throw new \InvalidArgumentException("L'avatar ne peut pas dépasser 500 Ko.");
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible d\'enregistrer l\'avatar.');
        }

        $name = 'u' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (@file_put_contents($dest, $binary) === false) {
            throw new \RuntimeException('Impossible d\'enregistrer l\'avatar.');
        }

        self::deleteLocalAvatarFile($previous);

        return '/uploads/avatars/' . $name;
    }

    /** Supprime un fichier avatar local Nexus (ignore URLs externes / data URI). */
    private static function deleteLocalAvatarFile(?string $avatar): void
    {
        if ($avatar === null || $avatar === '') {
            return;
        }
        if (!preg_match('#^/uploads/avatars/([A-Za-z0-9._-]+)$#', $avatar, $m)) {
            return;
        }
        $path = dirname(__DIR__, 2) . '/public/uploads/avatars/' . $m[1];
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Pousse le nouveau pays vers Sumsub et marque le dossier KYC à revoir.
     *
     * @param array<string,mixed> $user
     * @return array{synced:bool,reverification_required:bool,reason?:string}
     */
    private static function syncResidenceCountryToSumsub(PDO $pdo, array $user, string $countryAlpha2): array
    {
        $provider = new SumsubAdapter();
        if (!$provider->isConfigured()) {
            return [
                'synced' => false,
                'reverification_required' => true,
                'reason' => 'SUMSUB_NOT_CONFIGURED',
            ];
        }

        $type = (($user['account_type'] ?? 'personal') === 'business')
            ? KycSubjectType::COMPANY
            : KycSubjectType::INDIVIDUAL;

        $existing = KycService::findVerification($pdo, $provider, (int) $user['id'], $type);
        $applicantId = is_array($existing) ? (string) ($existing['applicant_id'] ?? '') : '';

        if ($applicantId === '') {
            // Pas encore d'applicant : le prochain POST /kyc/session enverra le pays.
            return [
                'synced' => false,
                'reverification_required' => true,
                'reason' => 'NO_APPLICANT_YET',
            ];
        }

        try {
            $provider->updateResidenceCountry($applicantId, $countryAlpha2, $type->isCompany());
        } catch (\Throwable $e) {
            return [
                'synced' => false,
                'reverification_required' => true,
                'reason' => 'SUMSUB_UPDATE_FAILED',
            ];
        }

        // Le pays a changé : l'identité doit être revalidée côté Sumsub.
        $pdo->prepare(
            'UPDATE kyc_verifications
                SET status = :st, reason = :reason, reviewed_at = NULL, updated_at = NOW()
              WHERE user_id = :uid AND provider = :p AND environment = :e AND subject_type = :t'
        )->execute([
            'st'     => KycStatus::RESUBMISSION_REQUESTED->value,
            'reason' => 'country_of_residence_changed',
            'uid'    => (int) $user['id'],
            'p'      => $provider->slug(),
            'e'      => $provider->environment(),
            't'      => $type->value,
        ]);

        // Niveau KYC local : on ne prétend plus que le dossier est validé.
        $pdo->prepare(
            'UPDATE users SET kyc_level = CASE
                WHEN kyc_level IN (\'standard\', \'advanced\', \'basic\') THEN \'none\'
                ELSE kyc_level
             END, updated_at = NOW()
             WHERE id = :id'
        )->execute(['id' => (int) $user['id']]);

        if ($type->isCompany()) {
            $pdo->prepare(
                'UPDATE users SET kyb_status = \'none\', kyb_verified_at = NULL, updated_at = NOW() WHERE id = :id'
            )->execute(['id' => (int) $user['id']]);
        }

        return [
            'synced' => true,
            'reverification_required' => true,
            'reason' => 'SUMSUB_FIXEDINFO_UPDATED',
        ];
    }

    /** PUT /api/users/me/password — change le mot de passe. */
    public static function updatePassword(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $userId = (int) $user['id'];

        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $confirmPassword = (string) $request->input('confirm_password', '');

        if (strlen($newPassword) < 8) {
            Response::badRequest('Le nouveau mot de passe doit contenir au moins 8 caractères.');
        }

        if ($newPassword !== $confirmPassword) {
            Response::badRequest('Les mots de passe ne correspondent pas.');
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            Response::notFound('Compte introuvable.');
        }

        $existingHash = (string) ($row['password_hash'] ?? '');
        $hadPassword = PasswordHash::isUsable($existingHash);

        // Compte Google sans mot de passe Nexus : on en définit un, sans
        // exiger un « mot de passe actuel » qui n'existe pas.
        if ($hadPassword) {
            if ($currentPassword === '') {
                Response::badRequest('Le mot de passe actuel est requis.');
            }
            if (!password_verify($currentPassword, $existingHash)) {
                Response::badRequest('Le mot de passe actuel est incorrect.');
            }
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $setChangedAt = $hadPassword;

        $sql = $setChangedAt
            ? 'UPDATE users SET password_hash = :password_hash, password_changed_at = UTC_TIMESTAMP(), updated_at = NOW() WHERE id = :id'
            : 'UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([
                ':password_hash' => $newPasswordHash,
                ':id' => $userId,
            ]);
        } catch (\PDOException $e) {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                ':password_hash' => $newPasswordHash,
                ':id' => $userId,
            ]);
        }

        self::audit($userId, $hadPassword ? 'password_changed' : 'password_set', null, null, [], $request);

        Response::success(['updated' => true, 'has_password' => true]);
    }

    /** GET /api/users/me/sessions — liste les sessions actives. */
    public static function sessions(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $userId = (int) $user['id'];

        $pdo = Database::getConnection();

        // Récupérer les tokens révoqués pour identifier les sessions invalides
        // NB : `revoked_tokens` ne possède pas de colonne `created_at`
        // (schéma : id, jti, user_id, revoked_at, expires_at). Le tri se fait
        // donc sur `revoked_at`, sans quoi MySQL renvoie une erreur 1054 et
        // l'endpoint répond 500.
        $stmt = $pdo->prepare(
            'SELECT jti, expires_at, revoked_at
             FROM revoked_tokens
             WHERE user_id = :id
             ORDER BY revoked_at DESC'
        );
        $stmt->execute([':id' => $userId]);
        $revokedTokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Pour une vraie gestion des sessions, il faudrait tracker les JWT émis
        // Ici on retourne une structure simplifiée
        $sessions = [];

        Response::success(['sessions' => $sessions, 'revoked_count' => count($revokedTokens)]);
    }

    /** DELETE /api/users/me/sessions/{id} — révoque une session. */
    public static function revokeSession(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $userId = (int) $user['id'];
        // Les paramètres d'URL sont exposés par param() ; route() n'existe pas
        // sur Request et provoquait une Error fatale (HTTP 500) à chaque appel.
        $jti = $request->param('id');

        if ($jti === null || $jti === '') {
            Response::badRequest('Identifiant de session invalide.');
        }

        $pdo = Database::getConnection();

        // `expires_at` est NOT NULL sans valeur par défaut : l'omettre faisait
        // échouer l'INSERT (SQLSTATE 1364) et rendait la révocation impossible.
        // On aligne l'expiration de l'entrée sur la durée de vie du JWT : au-delà,
        // le token est de toute façon invalide et la ligne peut être purgée.
        $stmt = $pdo->prepare(
            'INSERT INTO revoked_tokens (user_id, jti, revoked_at, expires_at)
             VALUES (:user_id, :jti, NOW(), :expires_at)
             ON DUPLICATE KEY UPDATE revoked_at = NOW()'
        );
        $stmt->execute([
            ':user_id'    => $userId,
            ':jti'        => $jti,
            ':expires_at' => date('Y-m-d H:i:s', time() + (int) JWT_TTL),
        ]);

        // Audit log
        self::audit($userId, 'session_revoked', null, null, ['jti' => $jti], $request);

        Response::success(['revoked' => true]);
    }

    /**
     * POST /api/users/me/close — clôture le compte client.
     *
     * Pas de suppression physique : le grand livre reste la source de vérité.
     * Les JWT en cours sont invalidés (`password_changed_at` + révocation du jti).
     * Fail-closed si un portefeuille a encore un solde disponible ou en réserve.
     */
    public static function closeAccount(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $jwtPayload = $request->attribute('jwt_payload');
        $userId = (int) $user['id'];

        $kind = (string) ($user['identity_kind'] ?? 'client');
        if ($kind !== 'client') {
            Response::forbidden('Les comptes internes ne peuvent pas être clôturés depuis cette interface.');
        }

        $confirmation = strtoupper(trim((string) $request->input('confirmation', '')));
        $emailConfirm = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');

        // FR « SUPPRIMER » ou EN « DELETE » — même confirmation forte.
        if (!in_array($confirmation, ['SUPPRIMER', 'DELETE'], true)) {
            Response::badRequest('Saisissez SUPPRIMER (ou DELETE) pour confirmer la clôture.');
        }

        $accountEmail = strtolower(trim((string) ($user['email'] ?? '')));
        if ($emailConfirm === '' || $emailConfirm !== $accountEmail) {
            Response::badRequest('L’adresse e-mail de confirmation ne correspond pas.');
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT password_hash, auth_provider, status FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            Response::notFound('Compte introuvable.');
        }

        $status = strtoupper((string) ($row['status'] ?? ''));
        if ($status === 'CLOSED') {
            Response::error('Ce compte est déjà clôturé.', 409, 'ACCOUNT_CLOSED');
        }

        $isGoogle = ((string) ($user['auth_provider'] ?? $row['auth_provider'] ?? 'local')) === 'google';
        $hash = (string) ($row['password_hash'] ?? '');
        // Compte local, ou Google ayant défini un mot de passe : vérifier le secret.
        if (!$isGoogle || $hash !== '') {
            if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
                Response::badRequest('Le mot de passe est incorrect.');
            }
        }

        $balStmt = $pdo->prepare(
            'SELECT currency, available_balance, hold_balance,
                    COALESCE(pending_balance, 0) AS pending_balance,
                    COALESCE(in_transit_balance, 0) AS in_transit_balance
               FROM wallets
              WHERE user_id = :id'
        );
        $balStmt->execute([':id' => $userId]);
        $leftover = [];
        foreach ($balStmt->fetchAll(\PDO::FETCH_ASSOC) as $wallet) {
            $available = (float) ($wallet['available_balance'] ?? 0);
            $hold = (float) ($wallet['hold_balance'] ?? 0);
            $pending = (float) ($wallet['pending_balance'] ?? 0);
            $inTransit = (float) ($wallet['in_transit_balance'] ?? 0);
            if (($available + $hold + $pending + $inTransit) > 0.009) {
                $leftover[] = (string) ($wallet['currency'] ?? '');
            }
        }
        if ($leftover !== []) {
            $currencies = implode(', ', array_values(array_unique(array_filter($leftover))));
            Response::error(
                'Videz vos portefeuilles (soldes disponibles, en réserve, en attente ou en transit) avant de clôturer le compte'
                . ($currencies !== '' ? " ({$currencies})" : '')
                . '.',
                409,
                'ACCOUNT_HAS_BALANCE'
            );
        }

        $pdo->beginTransaction();
        try {
            try {
                $pdo->prepare(
                    'UPDATE users SET status = :status, password_changed_at = UTC_TIMESTAMP(), updated_at = NOW() WHERE id = :id'
                )->execute(['status' => 'CLOSED', 'id' => $userId]);
            } catch (\PDOException) {
                $pdo->prepare(
                    'UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id'
                )->execute(['status' => 'CLOSED', 'id' => $userId]);
            }

            $jti = is_array($jwtPayload) ? (string) ($jwtPayload['jti'] ?? '') : '';
            if ($jti !== '') {
                $expTs = is_array($jwtPayload) && isset($jwtPayload['exp'])
                    ? (int) $jwtPayload['exp']
                    : time() + (int) JWT_TTL;
                $pdo->prepare(
                    'INSERT IGNORE INTO revoked_tokens (jti, user_id, expires_at)
                     VALUES (:jti, :user_id, :expires_at)'
                )->execute([
                    'jti'        => $jti,
                    'user_id'    => $userId,
                    'expires_at' => gmdate('Y-m-d H:i:s', $expTs),
                ]);
            }

            self::audit($userId, 'account.closed_by_user', 'users', $userId, [
                'auth_provider' => (string) ($row['auth_provider'] ?? 'local'),
            ], $request);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Response::success(['closed' => true]);
    }

    /** Helper : trace une action dans audit_logs. */
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
            ':user_id'     => $userId,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':metadata'    => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip_address'  => $request->ipAddress(),
        ]);
    }
}
