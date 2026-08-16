<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Devises de référence et taux de conversion (données de démonstration).
 *
 * Deux devises de référence sont utilisées par le dashboard :
 *  - EUR  : devise de référence du portefeuille (Solde total, activité).
 *  - XAF  : devise de référence du volume (KPI « Volume total »).
 *
 * Les taux ci-dessous sont des taux de démonstration (illustratifs) destinés
 * à l'environnement de développement. Ils seront remplacés par un service de
 * taux réel (Provider FX) dans une itération ultérieure.
 */
final class Currency
{
    /** Devise de référence du portefeuille (conversion des soldes). */
    public const REF = 'EUR';

    /** Devise de référence du volume agrégé (KPI Volume total). */
    public const VOLUME_REF = 'XAF';

    /** Devises affichées par la grille multi-devises du dashboard. */
    public const WALLET_CURRENCIES = ['EUR', 'USD', 'GBP', 'XAF', 'USDT', 'USDC'];

    /**
     * Valeur d'1 unité de devise exprimée en EUR (taux de démo).
     *
     * @var array<string, float>
     */
    private const RATE_TO_EUR = [
        'EUR'  => 1.0,
        'USD'  => 0.92,
        'GBP'  => 1.17,
        'XAF'  => 1.0 / 655.957,
        'USDT' => 0.92,
        'USDC' => 0.92,
    ];

    /**
     * Valeur d'1 unité de devise exprimée en XAF (taux de démo).
     *
     * @var array<string, float>
     */
    private const RATE_TO_XAF = [
        'EUR'  => 655.957,
        'USD'  => 603.0,
        'GBP'  => 767.0,
        'XAF'  => 1.0,
        'USDT' => 603.0,
        'USDC' => 603.0,
    ];

    private function __construct()
    {
        // Classe utilitaire : pas d'instanciation directe.
    }

    /** Taux de conversion d'une devise vers l'EUR (0 si devise inconnue). */
    public static function rateToRef(string $currency): float
    {
        return self::RATE_TO_EUR[strtoupper($currency)] ?? 0.0;
    }

    /** Taux de conversion d'une devise vers le XAF (0 si devise inconnue). */
    public static function rateToXaf(string $currency): float
    {
        return self::RATE_TO_XAF[strtoupper($currency)] ?? 0.0;
    }
}
