<?php

declare(strict_types=1);

namespace Nexus\Execution;

use Nexus\Providers\ProviderConfig;

/**
 * ProductionAuthorizationPolicy — qui a le droit d'exécuter en production.
 *
 * SÉPARATION DES DEUX QUESTIONS
 * ─────────────────────────────
 * Deux propriétés totalement indépendantes ne doivent jamais être confondues :
 *
 *     production_allowed              ← AUTORISATION (cette policy)
 *     production_credential_available ← CAPACITÉ TECHNIQUE (ProviderResolver)
 *
 * Posséder une clé `sk_live_…` ne confère aucun droit : c'est un moyen, pas
 * une permission. Inversement, être autorisé ne crée pas la credential.
 *
 * L'ordre d'évaluation est imposé : l'autorisation est tranchée AVANT toute
 * consultation de credential. Ainsi la simple existence d'une clé de
 * production ne peut jamais, à aucun moment, influencer la décision.
 *
 * DENY BY DEFAULT (fail closed)
 * ─────────────────────────────
 * En l'absence d'information, la réponse est TOUJOURS « refusé ». Aucune
 * inconnue n'est interprétée en faveur de la production :
 *
 *     information absente → production → REFUS
 *
 * Un système qui, dans le doute, autorise l'argent réel, est un système qui
 * finira par déplacer de l'argent réel par accident.
 *
 * RÈGLES ACTUELLES (explicitement minimales)
 * ──────────────────────────────────────────
 * Aucune règle produit ne définit encore quels comptes ont accès à la
 * production. Plutôt que d'inventer une règle métier arbitraire, la policy
 * n'accorde la production que sur autorisation SERVEUR explicite :
 *
 *   1. déploiement de production (`APP_ENV=production`) : la production est
 *      le mode nominal ;
 *   2. autorisation explicite de la plateforme
 *      (`NEXUS_PRODUCTION_ALLOWED_ACCOUNTS`, liste d'identifiants de comptes,
 *      ou `NEXUS_PRODUCTION_ALLOWED=true` pour un déploiement entier).
 *
 * Aucun compte n'obtient la production « parce qu'il est business » : ce
 * serait une règle métier inventée. Le mécanisme est prêt à recevoir la vraie
 * règle (colonne dédiée, plan tarifaire, statut KYB…) sans être réécrit.
 */
final class ProductionAuthorizationPolicy
{
    /** Autorise un déploiement entier (hors production). */
    public const ENV_ALLOW_ALL = 'NEXUS_PRODUCTION_ALLOWED';

    /** Liste explicite d'identifiants de comptes autorisés (séparés par des virgules). */
    public const ENV_ALLOW_LIST = 'NEXUS_PRODUCTION_ALLOWED_ACCOUNTS';

    private function __construct()
    {
    }

    /**
     * Le compte est-il autorisé à exécuter dans cet environnement ?
     *
     * La sandbox est ouverte par défaut (elle ne déplace pas d'argent réel).
     * La production est fermée par défaut.
     */
    public static function isAllowed(AccountContext $account, ExecutionEnvironment $environment): bool
    {
        if ($environment === ExecutionEnvironment::SANDBOX) {
            return self::isSandboxAllowed($account);
        }

        return self::isProductionAllowed($account);
    }

    /**
     * Sandbox : autorisée sauf interdiction explicite du serveur.
     *
     * Sur un déploiement de production, la sandbox n'a pas de sens : les
     * opérations y sont réelles. Refuser ici évite qu'un client obtienne un
     * mode dégradé sur une plateforme réelle.
     */
    public static function isSandboxAllowed(AccountContext $account): bool
    {
        unset($account); // Aucune règle par compte à ce stade — assumé.

        return !ProviderConfig::isProduction();
    }

    /**
     * Production : refusée tant qu'elle n'est pas explicitement accordée.
     *
     * Ne consulte AUCUNE credential : l'autorisation ne dépend jamais de la
     * disponibilité technique.
     */
    public static function isProductionAllowed(AccountContext $account): bool
    {
        // 1. Déploiement de production : la production est le mode nominal.
        if (ProviderConfig::isProduction()) {
            return true;
        }

        // 2. Autorisation explicite de toute la plateforme.
        if (self::flagEnabled(self::ENV_ALLOW_ALL)) {
            return true;
        }

        // 3. Liste explicite de comptes autorisés.
        $allowList = self::allowedAccountIds();
        if ($allowList !== [] && in_array($account->accountId, $allowList, true)) {
            return true;
        }

        // 4. Aucune information ⇒ REFUS (fail closed).
        return false;
    }

    /**
     * Motif du refus, destiné au message d'erreur et à l'audit.
     *
     * Ne divulgue ni secret, ni liste d'autorisation.
     */
    public static function denialReason(AccountContext $account, ExecutionEnvironment $environment): string
    {
        if ($environment === ExecutionEnvironment::SANDBOX) {
            return 'Ce déploiement exécute exclusivement en production : la sandbox n\'y est pas disponible.';
        }

        unset($account);

        return 'Exécution en production non autorisée pour ce compte. '
            . 'L\'autorisation est accordée explicitement par la plateforme : '
            . 'la présence d\'identifiants de production ne l\'accorde pas.';
    }

    /** @return list<int> */
    private static function allowedAccountIds(): array
    {
        $raw = (string) (getenv(self::ENV_ALLOW_LIST) ?: '');
        if (trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            // Un identifiant non numérique est ignoré : une entrée malformée
            // ne doit jamais élargir l'autorisation.
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }

    private static function flagEnabled(string $name): bool
    {
        $raw = strtolower(trim((string) (getenv($name) ?: '')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
