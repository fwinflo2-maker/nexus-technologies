<?php

declare(strict_types=1);

namespace Nexus\Execution;

use Nexus\Core\HttpException;

/**
 * PlatformRole — l'autorité d'exploitation, en un seul endroit.
 *
 * DISTINCTION FONDAMENTALE
 * ────────────────────────
 *     account_type   → QUI EST LE CLIENT   (personal | business)
 *     platform_role  → QUI EXPLOITE NEXUS  (user | superadmin)
 *
 * PRINCIPES
 * ─────────
 * 1. Deny by default : tout rôle inconnu ou absent vaut `user`.
 * 2. Aucun chemin applicatif ne permet de s'auto-promouvoir : la promotion se
 *    fait en base, par un administrateur.
 * 3. Le privilège n'affaiblit JAMAIS les invariants financiers. Un superadmin
 *    a la permission maximale, pas un système incohérent.
 */
final class PlatformRole
{
    public const USER       = 'user';
    public const SUPERADMIN = 'superadmin';

    /** Code d'erreur unique pour un refus de privilège plateforme. */
    public const ERROR_CODE = 'FORBIDDEN_PLATFORM_ROLE';

    /**
     * Rôles autorisés à administrer les credentials providers.
     *
     * Volontairement restreint au superadmin uniquement.
     */
    private const CREDENTIAL_ADMINS = [
        self::SUPERADMIN,
    ];

    /**
     * Rôles autorisés à consulter l'exploitation (Control Center).
     *
     * Réservé au superadmin uniquement.
     */
    private const OPERATIONS_VIEWERS = [
        self::SUPERADMIN,
    ];

    /**
     * Rôles autorisés à consulter l'INVENTAIRE des credentials providers.
     *
     * Réservé au superadmin uniquement.
     */
    private const CREDENTIAL_INVENTORY_VIEWERS = [
        self::SUPERADMIN,
    ];

    /**
     * Rôles autorisés à lire le JOURNAL D'AUDIT GLOBAL.
     *
     * Réservé au superadmin uniquement.
     */
    private const AUDIT_VIEWERS = [
        self::SUPERADMIN,
    ];

    /**
     * Rôles autorisés à consulter les DOSSIERS KYC/KYB NOMINATIFS.
     *
     * Réservé au superadmin uniquement.
     */
    private const KYC_VIEWERS = [
        self::SUPERADMIN,
    ];

    /**
     * Rôles autorisés aux opérations de maintenance qui ÉCRIVENT
     * (déblocage d'un état, rejeu contrôlé).
     */
    private const MAINTENANCE_OPERATORS = [
        self::SUPERADMIN,
    ];

    private function __construct()
    {
    }

    /**
     * Rôle effectif d'un utilisateur, normalisé.
     *
     * @param array<string,mixed>|null $user Ligne utilisateur (ou null).
     */
    public static function of(?array $user): string
    {
        $role = is_array($user) ? ($user['platform_role'] ?? null) : null;

        if (!is_string($role) || $role === '') {
            return self::USER;
        }

        // Deny by default : une valeur non reconnue ne confère aucun droit.
        return in_array($role, self::all(), true) ? $role : self::USER;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::USER,
            self::SUPERADMIN,
        ];
    }

    /** @param array<string,mixed>|null $user */
    public static function isSuperadmin(?array $user): bool
    {
        return self::of($user) === self::SUPERADMIN;
    }

    /** @param array<string,mixed>|null $user */
    public static function canAdministerCredentials(?array $user): bool
    {
        return in_array(self::of($user), self::CREDENTIAL_ADMINS, true);
    }

    /** @param array<string,mixed>|null $user */
    public static function canViewOperations(?array $user): bool
    {
        return in_array(self::of($user), self::OPERATIONS_VIEWERS, true);
    }

    /** @param array<string,mixed>|null $user */
    public static function canRunMaintenance(?array $user): bool
    {
        return in_array(self::of($user), self::MAINTENANCE_OPERATORS, true);
    }

    /** Peut consulter l'inventaire des credentials providers. */
    public static function canViewCredentialInventory(?array $user): bool
    {
        return in_array(self::of($user), self::CREDENTIAL_INVENTORY_VIEWERS, true);
    }

    /** Peut lire le journal d'audit global. */
    public static function canViewAudit(?array $user): bool
    {
        return in_array(self::of($user), self::AUDIT_VIEWERS, true);
    }

    /** Peut consulter les dossiers KYC/KYB nominatifs. */
    public static function canViewKyc(?array $user): bool
    {
        return in_array(self::of($user), self::KYC_VIEWERS, true);
    }

    /**
     * Dashboard interne associé à un rôle.
     *
     * Seul le superadmin a un dashboard interne ('executive').
     * Les utilisateurs normaux (personal/business) n'ont pas de dashboard interne.
     */
    public static function dashboardOf(?array $user): ?string
    {
        return match (self::of($user)) {
            self::SUPERADMIN => 'executive',
            default          => null,
        };
    }

    /** Dashboard interne par défaut d'un rôle (null si aucun). */
    public static function dashboardForRole(string $role): ?string
    {
        return match ($role) {
            self::SUPERADMIN => 'executive',
            default          => null,
        };
    }

    /**
     * Exige un privilège, sinon 403.
     *
     * 403 et non 404 : contrairement à une ressource appartenant à autrui,
     * l'existence de l'administration des providers n'est pas un secret. La
     * masquer n'apporterait rien et rendrait le diagnostic impossible.
     *
     * @param array<string,mixed>|null $user
     *
     * @throws HttpException 403 si le privilège est absent.
     */
    public static function require(?array $user, string $capability): void
    {
        $granted = match ($capability) {
            'credentials'  => self::canAdministerCredentials($user),
            'operations'   => self::canViewOperations($user),
            'credential_inventory' => self::canViewCredentialInventory($user),
            'audit_read'   => self::canViewAudit($user),
            'kyc_read'     => self::canViewKyc($user),
            'maintenance'  => self::canRunMaintenance($user),
            'superadmin'   => self::isSuperadmin($user),
            default        => false,   // capacité inconnue : refus.
        };

        if (!$granted) {
            throw new HttpException(
                403,
                'Cette opération est réservée au personnel autorisé de la plateforme.',
                self::ERROR_CODE
            );
        }
    }
}
