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
            if ((string) $row['status'] !== 'active' || (string) ($user['status'] ?? '') !== 'ACTIVE') {
                throw new HttpException(403, 'Compte employé indisponible', 'ACCOUNT_RESTRICTED');
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
}
