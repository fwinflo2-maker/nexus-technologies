<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * État honnête de la source FX (Cycle 5).
 *
 * Une SEULE source autoritaire est branchée : la parité de droit EUR↔XAF
 * (655,957 — garantie Trésor français, documentée Banque de France ; voir
 * OfficialPegFXProvider). Aucun vendor de taux de MARCHÉ n'est sélectionné :
 * pour toute autre paire, le cache `fx_rates_cache` est la seule entrée ;
 * vide ou expiré → fail-closed (`FX_RATE_UNAVAILABLE`). Cette classe
 * n'invente pas de fournisseur (la BCE ne publie pas EUR/XAF).
 */
final class FXSourceStatus
{
    public const SOURCE_NONE = 'none';

    private function __construct()
    {
    }

    /**
     * @return array{
     *   configured: bool,
     *   vendor: null,
     *   source: string,
     *   providers: list<array<string,mixed>>,
     *   market_vendor_configured: bool,
     *   cache: string,
     *   fail_closed: bool,
     *   ladder: string,
     *   note: string
     * }
     */
    public static function describe(): array
    {
        return [
            // `configured` = au moins une source autoritaire couvre au moins
            // une paire. Cela ne vaut PAS pour toutes les paires : voir
            // `providers` et `market_vendor_configured`.
            'configured'  => true,
            'vendor'      => null,
            'source'      => OfficialPegFXProvider::SOURCE,
            'providers'   => FXProviderRegistry::health(),
            'market_vendor_configured' => false,
            'cache'       => 'fx_rates_cache',
            'fail_closed' => true,
            'ladder'      => 'CONFIGURATION_READY',
            'note'        => 'Parité de droit EUR↔XAF (655,957) branchée avec provenance '
                . 'officielle (OfficialPegFXProvider). Aucun vendor de taux de marché : '
                . 'toute autre paire refuse sans taux cache non expiré, par environnement. '
                . 'Ne pas semer de taux fictifs.',
        ];
    }
}
