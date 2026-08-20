<?php

declare(strict_types=1);

namespace Nexus\Auth;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;

/**
 * Middleware d'authentification par JWT.
 *
 * Lit l'en-tête `Authorization: Bearer <token>`, valide le jeton, vérifie
 * qu'il n'est pas révoqué puis charge l'utilisateur correspondant.
 * L'utilisateur (sans données sensibles) et le payload du jeton sont
 * attachés à la requête via les attributs `user` et `jwt_payload`.
 */
final class AuthMiddleware
{
    public static function handle(Request $request): Request
    {
        try {
            $token = $request->bearerToken();

            if ($token === null || $token === '') {
                throw new HttpException(401, 'Authentification requise', 'UNAUTHORIZED');
            }

            $payload = Jwt::verify($token);
            if ($payload === null || !isset($payload['sub'])) {
                throw new HttpException(401, 'Session invalide ou expirée', 'TOKEN_INVALID');
            }

            $userId = (int) $payload['sub'];
            $pdo    = Database::getConnection();

            // Jeton révoqué (logout serveur) ?
            $stmt = $pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = :jti LIMIT 1');
            $stmt->execute(['jti' => (string) ($payload['jti'] ?? '')]);
            if ($stmt->fetchColumn() !== false) {
                throw new HttpException(401, 'Session invalide ou expirée', 'TOKEN_INVALID');
            }

            // Utilisateur toujours présent ?
            // password_changed_at est optionnel (migration 0.40) : SELECT *
            // évite de casser les suites avant application de la migration.
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, phone, account_type, platform_role, auth_provider, status,
                        kyc_level, kyb_status, avatar, country_of_residence, created_at
                 FROM users
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if ($user === false) {
                throw new HttpException(401, 'Session invalide ou expirée', 'TOKEN_INVALID');
            }

            // Reset / changement de mot de passe : invalide tous les JWT émis
            // avant password_changed_at (sans nécessiter leurs jti).
            try {
                $pwdStmt = $pdo->prepare('SELECT password_changed_at FROM users WHERE id = :id LIMIT 1');
                $pwdStmt->execute(['id' => $userId]);
                $pwdChanged = $pwdStmt->fetchColumn();
                if (is_string($pwdChanged) && $pwdChanged !== '') {
                    $changedTs = strtotime($pwdChanged . ' UTC');
                    $iat = (int) ($payload['iat'] ?? 0);
                    if ($changedTs !== false && $iat > 0 && $iat < $changedTs) {
                        throw new HttpException(401, 'Session invalide ou expirée', 'TOKEN_INVALID');
                    }
                }
            } catch (HttpException $e) {
                throw $e;
            } catch (\Throwable) {
                // Colonne absente (migration non appliquée) : ne pas bloquer l'auth.
            }

            // Une suspension ou une clôture doit prendre effet immédiatement,
            // y compris pour un JWT émis avant le changement de statut. Sans ce
            // contrôle côté serveur, un client suspendu pouvait continuer à
            // appeler les API jusqu'à l'expiration naturelle de son jeton.
            if (!in_array((string) $user['status'], ['ACTIVE', 'PENDING'], true)) {
                throw new HttpException(403, 'Compte indisponible', 'ACCOUNT_RESTRICTED');
            }

            $request->setAttribute('user', $user);
            $request->setAttribute('jwt_payload', $payload);

            return $request;
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $t) {
            // On lève une HttpException pour que le front controller (index.php) 
            // puisse la capturer et renvoyer une réponse JSON propre.
            throw new HttpException(500, 'Erreur technique : ' . $t->getMessage(), 'INTERNAL_SERVER_ERROR');
        }
    }
}
