<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;
use Throwable;

/**
 * ProviderRegistry — point d'entrée unique vers les adaptateurs providers.
 *
 *   Routing Engine → ProviderRegistry → Adapter → (credentials env) → Provider API
 *
 * Le Core ne connaît jamais un provider en particulier : il interroge le
 * registre, qui résout l'adaptateur et son statut. Aucune clé n'est exposée.
 *
 * RÈGLE DE DISPONIBILITÉ (§10 du prompt) :
 * ───────────────────────────────────────
 * Un provider du catalogue n'est PAS disponible pour le routing par principe.
 * Catalogue ≠ opérationnel : un provider ne participe au routing QUE s'il est
 * réellement CONFIGURÉ — par variables d'environnement scopées
 * (`PROVIDER_{SLUG}_{ENV}_{FIELD}`) OU par credentials chiffrées de la
 * plateforme en base (`provider_credentials`, user_id NULL), dans
 * l'environnement actif. Le « mode démo » historique — qui rendait tout le
 * catalogue éligible tant qu'aucun provider n'était configuré — est supprimé :
 * sans credentials, aucune route n'existe et l'exécution refuse
 * (NO_AVAILABLE_PROVIDER), quel que soit l'environnement.
 *
 * « Configured » ne signifie pas « Healthy » : la connectivité réelle n'est
 * vérifiée que par le health check / test de connexion.
 */
final class ProviderRegistry
{
    /** @var array<string, ProviderAdapter> */
    private static array $cache = [];

    /** Résout l'adaptateur d'un provider (instancié une seule fois). */
    public static function adapter(string $slug): ProviderAdapter
    {
        if (isset(self::$cache[$slug])) {
            return self::$cache[$slug];
        }
        return self::$cache[$slug] = self::build($slug);
    }

    /**
     * Surride l'adaptateur résolu pour un slug.
     *
     * Usage : tests (adaptateur scripté injecté pour exercer la saga
     * complète) et résolution dynamique. Ne modifie pas le catalogue.
     */
    public static function registerAdapter(string $slug, ProviderAdapter $adapter): void
    {
        self::$cache[$slug] = $adapter;
    }

    /** Vide le cache d'adaptateurs (tests). */
    public static function resetAdapters(): void
    {
        self::$cache = [];
    }

    /** Environnement actif d'un provider. */
    public static function environment(string $slug): string
    {
        return ProviderConfig::activeEnvironment($slug);
    }

    /** Statut de configuration d'un provider (sans jamais exposer de secret). */
    public static function status(string $slug): ProviderStatus
    {
        $adapter = self::adapter($slug);
        $config  = $adapter->validateConfiguration();

        // Variables d'environnement complètes → configuré.
        if ($config['status'] === ProviderStatus::CONFIGURED) {
            return ProviderStatus::CONFIGURED;
        }

        // Credentials chiffrées de la plateforme en base → configuré, pourvu
        // que leur statut ne soit pas « error » (validation échouée).
        // La configuration via le dashboard SuperAdmin est ainsi effective
        // au runtime, sans variable d'environnement.
        if (self::hasPlatformCredentials($slug, ProviderConfig::activeEnvironment($slug))) {
            return ProviderStatus::CONFIGURED;
        }

        return $config['status'];
    }

    /** Le provider est-il configuré et utilisable pour le routing ? */
    public static function isConfigured(string $slug): bool
    {
        return self::status($slug) === ProviderStatus::CONFIGURED;
    }

    /** Le mode strict est-il actif ? (information de diagnostic uniquement.) */
    public static function isStrictMode(): bool
    {
        return ProviderConfig::strictMode();
    }

    /**
     * Un provider est-il disponible pour le routing ?
     *
     * Disponibilité = CONFIGURÉ (env OU credentials plateforme en base).
     * Le catalogue seul ne rend jamais un provider disponible (§10) :
     * aucun mode démo ne subsiste, dans aucun environnement.
     */
    public static function isAvailableForRouting(string $slug): bool
    {
        return self::isConfigured($slug);
    }

    /**
     * Filtre une liste de slugs par disponibilité réelle.
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    public static function availableSlugs(array $slugs): array
    {
        return array_values(array_filter($slugs, [self::class, 'isAvailableForRouting']));
    }

    /**
     * Résumé public (statut + santé) pour tous les providers du catalogue.
     * NE CONTIENT JAMAIS de valeur de credential — uniquement des statuts.
     *
     * @return list<array<string,mixed>>
     */
    public static function summary(): array
    {
        $rows = [];
        foreach (ProviderCatalog::all() as $slug => $provider) {
            $adapter  = self::adapter($slug);
            $config   = $adapter->validateConfiguration();
            $status   = self::status($slug);
            $rows[]   = [
                'slug'              => $slug,
                'name'              => $provider['name'] ?? $slug,
                'environment'       => self::environment($slug),
                'status'            => $status->value,
                'enabled'           => ProviderConfig::isEnabled($slug),
                'missing_required'  => $config['missing'],
                'capabilities'      => $adapter->getCapabilities()['supported_methods'],
                'base_url'          => ProviderConfig::baseUrl($slug, self::environment($slug)),
                // Non persisté à ce stade : le health check est à la demande.
                'last_health_check' => null,
            ];
        }
        return $rows;
    }

    /**
     * Des credentials chiffrées de la PLATEFORME existent-elles pour ce
     * provider dans cet environnement, avec un statut exploitable ?
     */
    public static function hasPlatformCredentials(string $slug, string $environment): bool
    {
        try {
            $row = ProviderCredentialService::findPlatformRow(Database::getConnection(), $slug, $environment);
            if ($row === null || ($row['credentials_enc'] ?? null) === null) {
                return false;
            }
            // « error » = validation réelle échouée : le provider n'est pas
            // exploitable tant que les credentials n'ont pas été corrigées.
            return !in_array($row['status'], ['error', 'disabled'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** Construit l'adaptateur adéquat pour un slug (dédié ou générique). */
    private static function build(string $slug): ProviderAdapter
    {
        return match ($slug) {
            'stripe'  => new StripeAdapter(),
            'pawapay' => new PawaPayAdapter(),
            'western_union' => new WesternUnionAdapter(),
            default   => new ConfigDrivenProviderAdapter($slug),
        };
    }
}
