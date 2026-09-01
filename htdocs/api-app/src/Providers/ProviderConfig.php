<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Services\ProviderCatalog;

/**
 * ProviderConfig — résolution centralisée de la configuration des providers.
 *
 * Source de vérité : les variables d'environnement (`.env` non versionné),
 * JAMAIS de secret dans le code, dans Git, ni en clair dans MySQL (§4, §6).
 *
 * Convention de nommage (le slug et les champs viennent du ProviderCatalog) :
 *
 *   PROVIDER_{SLUG}_ENABLED             = true|false          (activation)
 *   PROVIDER_{SLUG}_ENV                 = sandbox|production  (environnement actif)
 *   PROVIDER_{SLUG}_SANDBOX_{FIELD}     = valeur scope sandbox
 *   PROVIDER_{SLUG}_PRODUCTION_{FIELD}  = valeur scope production
 *
 * Exemples :
 *   PROVIDER_STRIPE_ENABLED=true
 *   PROVIDER_STRIPE_ENV=sandbox
 *   PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_...
 *   PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_...
 *
 * ISOLATION STRICTE (§4, §10) :
 * ─────────────────────────────
 * Une credential est identifiée par le triplet `provider + environment +
 * field`. Il n'existe AUCUN repli d'un environnement vers l'autre, ni vers une
 * variable générique : si la variable scopée est absente, la résolution rend
 * `null`. La forme générique `PROVIDER_{SLUG}_{FIELD}` — qui permettait
 * autrefois à une clé de production d'être servie en sandbox — n'est plus
 * lue ; sa présence est signalée comme erreur de configuration.
 */
final class ProviderConfig
{
    public const ENV_PREFIX = 'PROVIDER_';

    private function __construct()
    {
    }

    /** Environnement global par défaut (surchargé par PROVIDER_{SLUG}_ENV). */
    public static function defaultEnvironment(): string
    {
        $env = strtolower(trim((string) (getenv('PROVIDERS_ENV') ?: '')));
        return $env === 'production' ? 'production' : 'sandbox';
    }

    /** Environnement actif d'un provider. */
    public static function activeEnvironment(string $slug): string
    {
        $raw = strtolower(trim((string) (getenv(self::key($slug, 'ENV')) ?: '')));
        if ($raw === 'sandbox' || $raw === 'production') {
            return $raw;
        }
        return self::defaultEnvironment();
    }

    /** Le provider est-il explicitement activé ? */
    public static function isEnabled(string $slug): bool
    {
        $raw = strtolower(trim((string) (getenv(self::key($slug, 'ENABLED')) ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Le mode strict est-il actif ?
     *
     * - `PROVIDERS_STRICT_MODE=true`, OU
     * - au moins un provider est activé / possède des credentials.
     *
     * Tant qu'aucun provider n'est configuré, le Core reste en « mode démo /
     * catalogue » (tous les providers du catalogue sont éligibles, comportement
     * historique). Dès qu'au moins un provider est réellement configuré, le
     * routing ne considère PLUS QUE les providers CONFIGURÉS (§12, §13).
     */
    public static function strictMode(): bool
    {
        // ── RÈGLE CRITIQUE (§5) : le mode démo est INTERDIT en production.
        // Même sans provider configuré, le routing doit REFUSER, jamais
        // retomber sur le catalogue en mode démo. ──
        if (self::isProduction()) {
            return true;
        }

        $flag = strtolower(trim((string) (getenv('PROVIDERS_STRICT_MODE') ?: '')));
        if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        foreach (ProviderCatalog::all() as $slug => $provider) {
            if (self::isEnabled($slug)) {
                return true;
            }
            $env = self::activeEnvironment($slug);
            foreach (($provider['credentials'] ?? []) as $field) {
                if (self::credential($slug, (string) $field['key'], $env) !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    /** L'environnement applicatif est-il « production » ? */
    public static function isProduction(): bool
    {
        if (defined('APP_ENV') && APP_ENV === 'production') {
            return true;
        }
        return strtolower(trim((string) (getenv('APP_ENV') ?: ''))) === 'production';
    }

    /**
     * Lit la valeur d'un champ de credential (jamais exposée aux clients).
     *
     * ISOLATION STRICTE DES ENVIRONNEMENTS (§4, §10)
     * ──────────────────────────────────────────────
     * SEULE la variable scopée est lue :
     *
     *     PROVIDER_{SLUG}_{ENVIRONMENT}_{FIELD}
     *
     * Le triplet `provider + environment + field` identifie une credential et
     * une seule. Si elle n'existe pas dans l'environnement demandé, la méthode
     * renvoie `null` — elle ne va JAMAIS chercher la valeur dans l'autre
     * environnement, ni dans une variable générique.
     *
     * Historique : une variante générique `PROVIDER_{SLUG}_{FIELD}` servait
     * auparavant de repli. Elle constituait une fuite inter-environnement
     * (une clé `sk_live_…` renseignée génériquement était retournée pour
     * l'environnement `sandbox`, et inversement) et a été supprimée. Une telle
     * variable encore présente dans l'environnement est désormais signalée
     * comme une erreur de configuration par `validate()` — jamais utilisée
     * silencieusement.
     *
     * @param string $environment `sandbox` ou `production` exclusivement.
     * @throws \InvalidArgumentException si l'environnement n'est pas reconnu
     *         (protection contre les typos « test », « staging », « prod »…
     *         qui produiraient une résolution silencieusement vide).
     */
    public static function credential(string $slug, string $field, string $environment): ?string
    {
        $env = self::normalizeEnvironment($environment);

        $value = getenv(self::key($slug, $env . '_' . $field));
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        return null;
    }

    /**
     * Valide un nom d'environnement.
     *
     * Aucune tolérance : « test », « staging », « prod », « PRODUCTION » avec
     * espaces… sont refusés. Accepter un alias reviendrait à créer un troisième
     * environnement fantôme dont les credentials seraient toujours absentes.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeEnvironment(string $environment): string
    {
        $normalized = strtolower(trim($environment));
        if ($normalized !== 'sandbox' && $normalized !== 'production') {
            // Le message ne contient que le nom fourni, jamais une valeur secrète.
            throw new \InvalidArgumentException(
                'Environnement provider invalide : « ' . $environment .' ». Attendu : sandbox ou production.'
            );
        }

        return strtoupper($normalized);
    }

    /**
     * Détecte les variables génériques héritées, non scopées par environnement.
     *
     * Ces variables ne sont plus lues (elles mélangeaient les environnements).
     * Les signaler explicitement évite l'échec silencieux : un opérateur qui a
     * renseigné `PROVIDER_STRIPE_SECRET_KEY` doit apprendre que la valeur est
     * ignorée, plutôt que de croire son provider configuré.
     *
     * @return list<string> noms de variables (JAMAIS leurs valeurs)
     */
    public static function legacyGenericVariables(string $slug): array
    {
        $provider = ProviderCatalog::get($slug);
        if ($provider === null) {
            return [];
        }

        $found = [];
        foreach (($provider['credentials'] ?? []) as $field) {
            $name  = self::key($slug, (string) $field['key']);
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $found[] = $name;
            }
        }

        return $found;
    }

    /** URL de base du provider pour l'environnement (override possible via env). */
    public static function baseUrl(string $slug, string $environment): string
    {
        $override = self::credential($slug, 'BASE_URL', $environment);
        if ($override !== null) {
            return $override;
        }

        $provider = ProviderCatalog::get($slug);
        if ($provider === null) {
            return '';
        }
        if ($environment === 'production') {
            return (string) ($provider['base_url'] ?? '');
        }
        return (string) ($provider['sandbox_url'] ?? $provider['base_url'] ?? '');
    }

    /** Le health check doit-il tenter une sonde de connectivité réelle ? */
    public static function connectivityCheckEnabled(): bool
    {
        $raw = strtolower(trim((string) (getenv('PROVIDERS_CONNECTIVITY_CHECK') ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Valide la configuration d'un provider pour un environnement.
     *
     * @return array{status: ProviderStatus, missing: list<string>, reason: ?string}
     */
    public static function validate(string $slug, string $environment): array
    {
        if (!ProviderCatalog::exists($slug)) {
            return [
                'status'  => ProviderStatus::INVALID_CONFIGURATION,
                'missing' => [],
                'reason'  => 'Provider inconnu du catalogue.',
            ];
        }

        if (!self::isEnabled($slug)) {
            return [
                'status'  => ProviderStatus::DISABLED,
                'missing' => [],
                'reason'  => 'Provider désactivé (variable *_ENABLED absente ou false).',
            ];
        }

        // Une variable générique héritée signale une configuration ambiguë :
        // l'opérateur croit le provider configuré alors que la valeur est
        // ignorée. On refuse explicitement plutôt que de laisser croire.
        $legacy = self::legacyGenericVariables($slug);
        if ($legacy !== []) {
            return [
                'status'  => ProviderStatus::INVALID_CONFIGURATION,
                'missing' => [],
                'reason'  => 'Variables non scopées par environnement, ignorées pour éviter toute fuite '
                    . 'sandbox/production : ' . implode(', ', $legacy)
                    . '. Utilisez PROVIDER_{SLUG}_SANDBOX_{FIELD} ou PROVIDER_{SLUG}_PRODUCTION_{FIELD}.',
            ];
        }

        $provider = ProviderCatalog::get($slug);
        $missing  = [];
        foreach (($provider['credentials'] ?? []) as $field) {
            if (($field['required'] ?? false) && self::credential($slug, (string) $field['key'], $environment) === null) {
                $missing[] = (string) $field['key'];
            }
        }
        if ($missing !== []) {
            return [
                'status'  => ProviderStatus::MISSING_CREDENTIALS,
                'missing' => $missing,
                'reason'  => 'Identifiants requis manquants.',
            ];
        }

        $url = self::baseUrl($slug, $environment);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [
                'status'  => ProviderStatus::INVALID_CONFIGURATION,
                'missing' => [],
                'reason'  => 'URL de base invalide.',
            ];
        }

        return [
            'status'  => ProviderStatus::CONFIGURED,
            'missing' => [],
            'reason'  => null,
        ];
    }

    /** Construit le nom de variable d'environnement : PROVIDER_{SLUG}_{SUFFIX}. */
    private static function key(string $slug, string $suffix): string
    {
        return self::ENV_PREFIX . strtoupper($slug) . '_' . strtoupper($suffix);
    }
}
