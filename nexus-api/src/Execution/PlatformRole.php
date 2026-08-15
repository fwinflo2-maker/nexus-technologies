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
 *     platform_role  → QUI EXPLOITE NEXUS  (user | … | superadmin)
 *
 * Les confondre a produit une faille CRITICAL : l'administration des
 * credentials providers était gardée par `account_type === 'business'`, or ce
 * champ est choisi librement par l'utilisateur à l'inscription. N'importe qui
 * pouvait donc écrire une credential de production.
 *
 * Un client business reste un client. Le privilège d'exploitant ne s'hérite
 * pas d'un type de compte.
 *
 * PRINCIPES
 * ─────────
 * 1. Deny by default : tout rôle inconnu ou absent vaut `user`.
 * 2. Aucun chemin applicatif ne permet de s'auto-promouvoir : la promotion se
 *    fait en base, par un administrateur. C'est délibéré — un endpoint de
 *    promotion serait la première cible d'une escalade de privilèges.
 * 3. Le privilège n'affaiblit JAMAIS les invariants financiers. Un superadmin
 *    a la permission maximale, pas un système incohérent : l'ExecutionContext,
 *    la séparation sandbox/production, l'idempotence, le ledger et l'audit
 *    s'appliquent à lui exactement comme aux autres.
 */
final class PlatformRole
{
    public const USER                = 'user';
    public const SUPPORT_OPERATOR    = 'support_operator';
    public const COMPLIANCE_OPERATOR = 'compliance_operator';
    public const FINANCE_OPERATOR    = 'finance_operator';
    public const SECURITY_ENGINEER   = 'security_engineer';
    public const PROVIDER_ENGINEER   = 'provider_engineer';
    public const BACKEND_ENGINEER    = 'backend_engineer';
    public const QA_ENGINEER         = 'qa_engineer';
    public const SRE_OPERATOR        = 'sre_operator';
    public const AI_AGENT            = 'ai_agent';
    public const SUPERADMIN          = 'superadmin';

    /** Code d'erreur unique pour un refus de privilège plateforme. */
    public const ERROR_CODE = 'FORBIDDEN_PLATFORM_ROLE';

    /**
     * Rôles autorisés à administrer les credentials providers.
     *
     * Volontairement restreint. `provider_engineer` y figure parce que
     * l'intégration d'un provider est précisément son métier ; il reste
     * soumis au même audit et à la même séparation d'environnements.
     */
    private const CREDENTIAL_ADMINS = [
        self::SUPERADMIN,
        self::PROVIDER_ENGINEER,
    ];

    /**
     * Rôles autorisés à consulter l'exploitation (Control Center).
     *
     * La lecture est plus large que l'écriture : diagnostiquer n'est pas
     * modifier. Aucun de ces rôles ne voit pour autant la VALEUR d'un secret.
     */
    private const OPERATIONS_VIEWERS = [
        self::SUPERADMIN,
        self::PROVIDER_ENGINEER,
        self::SECURITY_ENGINEER,
        self::BACKEND_ENGINEER,
        self::SRE_OPERATOR,
        self::COMPLIANCE_OPERATOR,
        self::FINANCE_OPERATOR,
        self::SUPPORT_OPERATOR,
        self::QA_ENGINEER,
    ];

    /**
     * Rôles autorisés à consulter l'INVENTAIRE des credentials providers.
     *
     * Distinct de `operations` (correctif boucle 16). Savoir QUELS providers
     * sont configurés, dans quel environnement et depuis quand est un plan
     * de l'infrastructure de paiement : cela révèle les corridors actifs et
     * les dépendances externes de Nexus. Utile pour intégrer ou diagnostiquer
     * un provider — sans rapport avec le métier d'un opérateur de support ou
     * d'un testeur.
     *
     * La VALEUR d'un secret n'est de toute façon jamais exposée, à personne.
     */
    private const CREDENTIAL_INVENTORY_VIEWERS = [
        self::SUPERADMIN,
        self::PROVIDER_ENGINEER,
        self::SECURITY_ENGINEER,
        self::SRE_OPERATOR,
    ];

    /**
     * Rôles autorisés à lire le JOURNAL D'AUDIT GLOBAL.
     *
     * Le journal contient les actions de tous les comptes et de tout le
     * personnel : c'est une surface de surveillance. La lire n'est le métier
     * ni du support, ni de la QA, ni d'un ingénieur backend — mais c'est
     * exactement celui de la sécurité et de la conformité.
     *
     * Un opérateur qui peut lire le journal peut aussi y observer le travail
     * de ses collègues : restreindre cette lecture protège le personnel
     * autant que les clients.
     */
    private const AUDIT_VIEWERS = [
        self::SUPERADMIN,
        self::SECURITY_ENGINEER,
        self::COMPLIANCE_OPERATOR,
    ];

    /**
     * Rôles autorisés aux opérations de maintenance qui ÉCRIVENT
     * (déblocage d'un état, rejeu contrôlé).
     */
    private const MAINTENANCE_OPERATORS = [
        self::SUPERADMIN,
        self::SRE_OPERATOR,
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
            self::SUPPORT_OPERATOR,
            self::COMPLIANCE_OPERATOR,
            self::FINANCE_OPERATOR,
            self::SECURITY_ENGINEER,
            self::PROVIDER_ENGINEER,
            self::BACKEND_ENGINEER,
            self::QA_ENGINEER,
            self::SRE_OPERATOR,
            self::AI_AGENT,
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
