<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Devises de référence et constantes d'affichage.
 *
 * AUCUN TAUX DE CHANGE N'EST DÉFINI ICI (§7) : les taux de démonstration
 * (`RATE_TO_EUR` / `RATE_TO_XAF`) ont été supprimés. La seule source de taux
 * est la source FX réelle, scopée par environnement (`FXService` →
 * `fx_rates_cache`). Tout agrégat qui convertit une devise doit passer par
 * `FXService::rateToRef()` — qui rend null quand aucun taux réel n'existe,
 * jamais une valeur inventée.
 */
final class Currency
{
    /** Devise de référence du portefeuille (conversion des soldes). */
    public const REF = 'EUR';

    /** Devise de référence du volume agrégé (KPI Volume total). */
    public const VOLUME_REF = 'XAF';

    /** Devises affichées par la grille multi-devises du dashboard. */
    public const WALLET_CURRENCIES = ['EUR', 'USD', 'GBP', 'XAF', 'USDT', 'USDC'];

    private function __construct()
    {
        // Classe utilitaire : pas d'instanciation directe.
    }
}
