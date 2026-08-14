<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Services\ProviderCatalog;

/**
 * ProviderRegistry — point d'entrée unique vers les adaptateurs providers.
 *
 *   Routing Engine → ProviderRegistry → Adapter → (credentials env) → Provider API
 *
 * Le Core ne connaît jamais un provider en particulier : il interroge le
 * registre, qui résout l'adaptateur et son statut. Aucune clé n'est exposée.
 *
 * Règles de disponibilité :
 *  - mode démo (aucun provider configuré)  → tous les providers du catalogue ;
 *  - mode strict (≥ 1 provider configuré)  → uniquement les providers CONFIGURÉS.
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

    /** Environnement actif d'un provider. */
    public static function environment(string $slug): string
    {
        return ProviderConfig::activeEnvironment($slug);
    }

    /** Statut de configuration d'un provider (sans jamais exposer de secret). */
    public static function status(string $slug): ProviderStatus
    {
        return self::adapter($slug)->validateConfiguration()['status'];
    }

    /** Le provider est-il configuré et utilisable pour le routing ? */
    public static function isConfigured(string $slug): bool
    {
        return self::status($slug)->routable();
    }

    /** Le mode strict est-il actif ? */
    public static function isStrictMode(): bool
    {
        return ProviderConfig::strictMode();
    }

    /**
     * Un provider est-il disponible pour le routing ?
     *
     * - PRODUCTION  : le mode démo est interdit — seuls les providers
     *                 CONFIGURÉS participent (jamais le catalogue entier).
     * - Développement : mode démo (catalogue) tant qu'aucun provider n'est
     *                 configuré ; mode strict dès qu'au moins un l'est.
     */
    public static function isAvailableForRouting(string $slug): bool
    {
        if (!self::isStrictMode()) {
            return true;
        }
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
            $rows[]   = [
                'slug'              => $slug,
                'name'              => $provider['name'] ?? $slug,
                'environment'       => self::environment($slug),
                'status'            => $config['status']->value,
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

    /** Construit l'adaptateur adéquat pour un slug (dédié ou générique). */
    private static function build(string $slug): ProviderAdapter
    {
        return match ($slug) {
            'stripe'  => new StripeAdapter(),
            'pawapay' => new PawaPayAdapter(),
            default   => new ConfigDrivenProviderAdapter($slug),
        };
    }
}
