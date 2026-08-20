<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * FXProviderRegistry — sources FX autoritaires branchées (Cycle 5).
 *
 * Seules les sources à provenance VÉRIFIABLE y entrent. Aujourd'hui :
 *   - OfficialPegFXProvider : parité de droit EUR ↔ XAF (655,957).
 *
 * Aucun vendor de taux de MARCHÉ n'est sélectionné : toute paire non
 * couverte par un provider reste servie par le cache seul, puis REFUSÉE
 * (`FX_RATE_UNAVAILABLE`) — comportement fail-closed inchangé.
 */
final class FXProviderRegistry
{
    /** @var list<FXProviderInterface>|null */
    private static ?array $providers = null;

    private function __construct()
    {
    }

    /** @return list<FXProviderInterface> */
    public static function providers(): array
    {
        return self::$providers ??= [new OfficialPegFXProvider()];
    }

    /** Premier provider couvrant la paire, ou null (fail-closed en aval). */
    public static function providerFor(string $baseCurrency, string $quoteCurrency): ?FXProviderInterface
    {
        foreach (self::providers() as $provider) {
            if ($provider->getPair($baseCurrency, $quoteCurrency) !== null) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * États de santé de toutes les sources branchées (statut/audit).
     *
     * @return list<array<string,mixed>>
     */
    public static function health(): array
    {
        return array_map(
            static fn (FXProviderInterface $p): array => $p->health(),
            self::providers()
        );
    }
}
