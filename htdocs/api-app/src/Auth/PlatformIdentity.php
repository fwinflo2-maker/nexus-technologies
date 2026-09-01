<?php

declare(strict_types=1);

namespace Nexus\Auth;

use Nexus\Core\HttpException;
use Nexus\Execution\PlatformRole;
use PDO;

/**
 * Résout l'identité plateforme à partir du compte et, lorsqu'il existe, du
 * dossier employé. Un dossier employé actif et son rôle métier priment sur le
 * type de compte client, qui ne porte aucun privilège interne.
 */
final class PlatformIdentity
{
    public const ERROR_CODE = 'INVALID_PLATFORM_IDENTITY';

    private function __construct()
    {
    }

    /** @param array<string,mixed> $user
     *  @return array<string,mixed>
     */
    public static function resolve(PDO $pdo, array $user): array
    {
        $rawRole = $user['platform_role'] ?? null;
        $employee = $pdo->prepare(
            'SELECT role, status FROM employees WHERE user_id = :user_id LIMIT 1'
        );
        $employee->execute(['user_id' => (int) $user['id']]);
        $row = $employee->fetch();

        if ($row !== false) {
            $userStatus = strtoupper((string) ($user['status'] ?? ''));
            $employeeStatus = (string) $row['status'];

            if ($employeeStatus !== 'active') {
                $message = match ($employeeStatus) {
                    'disabled' => 'Votre accès employé a été désactivé. Contactez l\'administration Nexus.',
                    'invited' => 'Votre compte employé est en attente d\'activation. Consultez votre invitation.',
                    default => 'Compte employé indisponible',
                };
                throw new HttpException(403, $message, 'ACCOUNT_RESTRICTED');
            }

            if ($userStatus !== 'ACTIVE') {
                $message = match ($userStatus) {
                    'SUSPENDED' => 'Votre compte employé est suspendu. Contactez l\'administration Nexus.',
                    'CLOSED' => 'Votre compte employé est clôturé.',
                    'PENDING' => 'Votre compte employé est en attente d\'activation.',
                    default => 'Compte employé indisponible',
                };
                $code = match ($userStatus) {
                    'SUSPENDED' => 'ACCOUNT_SUSPENDED',
                    'CLOSED' => 'ACCOUNT_CLOSED',
                    default => 'ACCOUNT_RESTRICTED',
                };
                throw new HttpException(403, $message, $code);
            }

            $employeeRole = (string) ($row['role'] ?? '');
            if (!PlatformRole::isInternal($employeeRole)) {
                throw new HttpException(403, 'Identité employé invalide', self::ERROR_CODE);
            }

            // La relation employé active répare la projection d'authentification
            // même si une ancienne ligne users contient encore `user`.
            // `account_type` reste une colonne client et ne doit jamais
            // piloter le routage d'un exploitant interne.
            $user['platform_role'] = $employeeRole;
            $user['identity_kind'] = PlatformRole::identityKind($user);
            return $user;
        }

        if ($rawRole === null || $rawRole === '') {
            $user['platform_role'] = PlatformRole::USER;
            $user['identity_kind'] = 'client';
            return $user;
        }

        if (!is_string($rawRole) || !PlatformRole::isKnown($rawRole)) {
            throw new HttpException(403, 'Identité plateforme invalide', self::ERROR_CODE);
        }

        $user['identity_kind'] = PlatformRole::identityKind($user);
        return $user;
    }

    /**
     * Refuse la connexion pour les comptes restreints (suspendu, clôturé).
     * Doit être appelé après {@see resolve()} pour disposer de `identity_kind`.
     *
     * @param array<string,mixed> $user
     */
    public static function assertLoginAllowed(array $user): void
    {
        $status = strtoupper((string) ($user['status'] ?? ''));
        $kind = (string) ($user['identity_kind'] ?? PlatformRole::identityKind($user));

        if ($kind === 'client') {
            if ($status === 'SUSPENDED') {
                throw new HttpException(
                    403,
                    'Votre compte est temporairement suspendu. Contactez le support NEXUS.',
                    'ACCOUNT_SUSPENDED'
                );
            }
            if ($status === 'CLOSED') {
                throw new HttpException(
                    403,
                    'Votre compte a été fermé. Contactez le support pour toute question.',
                    'ACCOUNT_CLOSED'
                );
            }

            return;
        }

        if ($status !== 'ACTIVE') {
            $message = match ($status) {
                'SUSPENDED' => 'Compte interne suspendu. Contactez l\'administration.',
                'CLOSED' => 'Compte interne clôturé.',
                'PENDING' => 'Compte en attente d\'activation.',
                default => 'Compte indisponible',
            };
            throw new HttpException(403, $message, 'ACCOUNT_RESTRICTED');
        }
    }
}
