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

    // ── Rôles internes Nexus (8 dashboards spécialisés) ──────────────
    public const OPERATIONS_MANAGER = 'operations_manager';
    public const FINANCE_TREASURY   = 'finance_treasury';
    public const TREASURY_MANAGER   = 'treasury_manager';
    public const COMPLIANCE_OFFICER = 'compliance_officer';
    public const RISK_FRAUD         = 'risk_fraud';
    public const RISK_ANALYST       = 'risk_analyst';
    public const PROVIDER_MANAGER   = 'provider_manager';
    public const CUSTOMER_SUPPORT   = 'customer_support';
    public const SECURITY_TECHNICAL = 'security_technical';
    public const SECURITY_ADMIN     = 'security_admin';
    public const TECHNICAL_ADMIN    = 'technical_admin';
    public const BUSINESS_MANAGER   = 'business_manager';

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
        self::PROVIDER_MANAGER,
        self::TECHNICAL_ADMIN,
    ];

    /**
     * Rôles autorisés à consulter l'exploitation (Control Center).
     *
     * La lecture est plus large que l'écriture : diagnostiquer n'est pas
     * modifier. Aucun de ces rôles ne voit pour autant la VALEUR d'un secret.
     */
    private const OPERATIONS_VIEWERS = [
        self::SUPERADMIN,
        self::OPERATIONS_MANAGER,
        self::FINANCE_TREASURY,
        self::TREASURY_MANAGER,
        self::COMPLIANCE_OFFICER,
        self::RISK_FRAUD,
        self::RISK_ANALYST,
        self::PROVIDER_MANAGER,
        self::CUSTOMER_SUPPORT,
        self::SECURITY_TECHNICAL,
        self::SECURITY_ADMIN,
        self::TECHNICAL_ADMIN,
        self::BUSINESS_MANAGER,
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
        self::PROVIDER_MANAGER,
        self::TECHNICAL_ADMIN,
        self::SECURITY_ENGINEER,
        self::SECURITY_TECHNICAL,
        self::SECURITY_ADMIN,
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
        self::SECURITY_TECHNICAL,
        self::SECURITY_ADMIN,
        self::COMPLIANCE_OPERATOR,
        self::COMPLIANCE_OFFICER,
    ];

    /**
     * Rôles autorisés à consulter les DOSSIERS KYC/KYB NOMINATIFS.
     *
     * Correctif boucle 18 (CRITICAL). `/api/control/kyc` était gardé par la
     * capacité `operations`, donc lisible par les 9 rôles d'exploitation —
     * support, QA, backend, SRE inclus. Prouvé en HTTP : un `qa_engineer`
     * a obtenu 200 avec le nom, l'e-mail, l'`applicant_id` et le MOTIF DE
     * REJET d'un dossier (« suspicion de fraude documentaire »).
     *
     * Un motif de rejet KYC est une donnée d'identité doublée d'un jugement
     * sur la personne. Y accéder n'est pas un droit d'exploitation général :
     * c'est le métier de la conformité, et d'elle seule. Le principe est
     * celui du besoin d'en connaître, pas du confort de diagnostic — un
     * testeur n'a jamais besoin de savoir qui a été soupçonné de fraude.
     *
     * `security_engineer` en est volontairement exclu : il dispose déjà de
     * `audit_read` pour instruire un incident, ce qui ne nécessite pas de
     * lire les dossiers d'identité de l'ensemble des clients.
     */
    private const KYC_VIEWERS = [
        self::SUPERADMIN,
        self::COMPLIANCE_OPERATOR,
        self::COMPLIANCE_OFFICER,
    ];

    /**
     * Rôles autorisés aux opérations de maintenance qui ÉCRIVENT
     * (déblocage d'un état, rejeu contrôlé).
     */
    private const MAINTENANCE_OPERATORS = [
        self::SUPERADMIN,
        self::SRE_OPERATOR,
        self::TECHNICAL_ADMIN,
        self::OPERATIONS_MANAGER,
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
            // Rôles internes Nexus (dashboards spécialisés)
            self::SUPERADMIN,
            self::OPERATIONS_MANAGER,
            self::FINANCE_TREASURY,
            self::TREASURY_MANAGER,
            self::COMPLIANCE_OFFICER,
            self::RISK_FRAUD,
            self::RISK_ANALYST,
            self::PROVIDER_MANAGER,
            self::CUSTOMER_SUPPORT,
            self::SECURITY_TECHNICAL,
            self::SECURITY_ADMIN,
            self::TECHNICAL_ADMIN,
            self::BUSINESS_MANAGER,
            // Rôles historiques (rétrocompatibilité)
            self::SUPPORT_OPERATOR,
            self::COMPLIANCE_OPERATOR,
            self::FINANCE_OPERATOR,
            self::SECURITY_ENGINEER,
            self::PROVIDER_ENGINEER,
            self::BACKEND_ENGINEER,
            self::QA_ENGINEER,
            self::SRE_OPERATOR,
            self::AI_AGENT,
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
     * Chaque rôle interne possède un dashboard spécialisé. Les rôles
     * historiques sont mappés vers le dashboard le plus proche. `user` et les
     * rôles non reconnus → null (pas de dashboard interne).
     */
    public static function dashboardOf(?array $user): ?string
    {
        return match (self::of($user)) {
            self::SUPERADMIN          => 'executive',
            self::OPERATIONS_MANAGER  => 'operations',
            self::FINANCE_TREASURY, self::TREASURY_MANAGER, self::FINANCE_OPERATOR => 'finance',
            self::COMPLIANCE_OFFICER, self::COMPLIANCE_OPERATOR => 'compliance',
            self::RISK_FRAUD, self::RISK_ANALYST => 'risk',
            self::PROVIDER_MANAGER, self::PROVIDER_ENGINEER => 'providers',
            self::CUSTOMER_SUPPORT, self::SUPPORT_OPERATOR => 'support',
            self::SECURITY_TECHNICAL, self::SECURITY_ADMIN, self::SECURITY_ENGINEER, self::SRE_OPERATOR,
            self::TECHNICAL_ADMIN, self::BACKEND_ENGINEER, self::QA_ENGINEER, self::AI_AGENT => 'technical',
            self::BUSINESS_MANAGER    => 'business',
            default                   => null,
        };
    }

    /** Dashboard interne par défaut d'un rôle (null si aucun). */
    public static function dashboardForRole(string $role): ?string
    {
        return match ($role) {
            self::SUPERADMIN          => 'executive',
            self::OPERATIONS_MANAGER  => 'operations',
            self::FINANCE_TREASURY, self::TREASURY_MANAGER, self::FINANCE_OPERATOR => 'finance',
            self::COMPLIANCE_OFFICER, self::COMPLIANCE_OPERATOR => 'compliance',
            self::RISK_FRAUD, self::RISK_ANALYST => 'risk',
            self::PROVIDER_MANAGER, self::PROVIDER_ENGINEER => 'providers',
            self::CUSTOMER_SUPPORT, self::SUPPORT_OPERATOR => 'support',
            self::SECURITY_TECHNICAL, self::SECURITY_ADMIN, self::SECURITY_ENGINEER, self::SRE_OPERATOR,
            self::TECHNICAL_ADMIN, self::BACKEND_ENGINEER, self::QA_ENGINEER, self::AI_AGENT => 'technical',
            self::BUSINESS_MANAGER    => 'business',
            default                   => null,
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
