<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * Quote Engine — calcule les quotes pour chaque provider éligible.
 *
 * Étape 3 du pipeline. Pour chaque provider retourné par le Capability
 * Engine, calcule :
 *   - received_amount (taux fixe 655.957 − frais/spread)
 *   - fees (EUR)
 *   - delay_avg (minutes, mesuré par ProviderLatency ; null si non mesuré)
 *   - reliability (mesurée par ProviderReliability, ou null si non mesurée)
 *
 * Source du taux : fixe (655.957 XAF pour 1 EUR) — sera remplacé
 * par un service FX temps réel dans une itération ultérieure.
 *
 * Petite variation aléatoire bornée pour simuler la concurrence entre
 * providers (spread +0.1 à +1.0 %, frais ±10 % autour de la base).
 *
 * Le frais de base dépend de la méthode de réception :
 *   - mobile_money : 2.90 EUR
 *   - bank         : 4.50 EUR
 *   - crypto       : 3.50 EUR
 *   - cash_pickup  : 5.50 EUR
 */
final class QuoteEngine
{
    /** Taux fixe EUR → XAF (référence, cohérent avec Currency / IntentEngine). */
    public const FIXED_RATE_EUR_TO_XAF = 655.957;

    /** Frais de base par méthode de réception (EUR). */
    private const BASE_FEES = [
        'mobile_money' => 2.90,
        'bank'         => 4.50,
        'crypto'       => 3.50,
        'cash_pickup'  => 5.50,
    ];

    /** Fourchette de variation aléatoire du spread (0.1 % à 1.0 %). */
    private const SPREAD_MIN_PCT = 0.001;
    private const SPREAD_MAX_PCT = 0.010;

    /** Variation des frais autour de la base (±10 %). */
    private const FEE_VARIATION = 0.10;

    /** Graine (idempotente par quote_id). Permet des variations stables. */
    private const RANDOM_SEED_PREFIX = 'nexus_quote_';

    private function __construct() {}

    /**
     * Calcule une quote pour un provider donné.
     *
     * @param array{amount: float, sourceCurrency: string, destCountry: string,
     *              destCurrency: string, receivingMethod: string} $intent
     * @param array{slug: string, name: string, category: string, reliability: float|null,
     *              delay_seconds: int|null, delay_status: string, method_type: string} $provider
     * @param string|null $seed Graine pour reproductibilité (optionnel).
     *
     * @return array{
     *     provider_slug: string,
     *     provider_name: string,
     *     received: float,
     *     received_currency: string,
     *     fees: float,
     *     fee_currency: string,
     *     rate: float,
     *     spread_pct: float,
     *     delay_seconds: int|null,
     *     delay_avg: int|null,
     *     delay_status: string,
     *     reliability: float|null,
     *     reliability_status: string,
     *     effective_rate: float,
     * }
     */
    public static function quote(array $intent, array $provider, ?string $seed = null): array
    {
        $methodType   = $intent['receivingMethod'];
        $destCurrency = $intent['destCurrency'];
        $sourceAmount = (float) $intent['amount'];

        // ── Graine pour reproductibilité (par quote_id) ─────────
        if ($seed === null) {
            $seed = self::RANDOM_SEED_PREFIX . $provider['slug'];
        }
        mt_srand(crc32($seed));

        // ── Spread aléatoire borné [0.1%, 1.0%] ─────────────────
        $spreadPct = self::SPREAD_MIN_PCT +
            (mt_rand(0, 1000) / 1000) * (self::SPREAD_MAX_PCT - self::SPREAD_MIN_PCT);

        // ── Frais de base + variation ±10% ─────────────────────
        $baseFee = self::BASE_FEES[$methodType] ?? 3.50;
        $feeVar  = (mt_rand(-100, 100) / 100) * self::FEE_VARIATION; // [-0.10, +0.10]
        $fees    = round($baseFee * (1 + $feeVar), 2);

        // ── Conversion source → destination ────────────────────
        // Taux : « 1 EUR = X unités de devise »
        // Pour convertir DE source VERS EUR : on DIVISE par le taux
        // Ex. : 500 USD / 1.0870 = 459.98 EUR
        $sourceToEur = self::rateToEur($intent['sourceCurrency']);

        // Montant en EUR (après frais en EUR)
        $amountInEur    = $sourceAmount / $sourceToEur;
        $amountAfterFee = max(0.0, $amountInEur - $fees);

        // Montant reçu dans la devise de destination
        // Taux effectif après spread
        $effectiveRate = self::FIXED_RATE_EUR_TO_XAF * (1 - $spreadPct);
        $received      = round($amountAfterFee * $effectiveRate, 0);

        // ── Délai moyen (référence, arrondi en minutes) ─────────
        // Délai : mesuré (médiane, en secondes) ou inconnu. La moyenne d'une
        // fourchette inventée n'a plus lieu d'être — il n'y a plus de
        // fourchette inventée. `null` traverse la chaîne pour que le
        // Routing Engine ne puisse pas confondre « non mesuré » et « rapide ».
        $delaySeconds = $provider['delay_seconds'] ?? null;
        $delayAvg     = $delaySeconds === null
            ? null
            : max(1, (int) round($delaySeconds / 60));

        mt_srand(); // reset seed

        return [
            'provider_slug'    => $provider['slug'],
            'provider_name'    => $provider['name'],
            'received'         => $received,
            'received_currency' => $destCurrency,
            'fees'             => $fees,
            'fee_currency'     => 'EUR',
            'rate'             => self::FIXED_RATE_EUR_TO_XAF,
            'spread_pct'       => round($spreadPct * 100, 3),
            'delay_seconds'     => $delaySeconds,
            'delay_avg'         => $delayAvg,
            'delay_status'      => $provider['delay_status'] ?? ProviderLatency::UNAVAILABLE,
            'delay_obs'         => $provider['delay_obs'] ?? 0,
            'delay_p90_seconds' => $provider['delay_p90_seconds'] ?? null,
            // La fiabilité peut être inconnue (null) : on transporte l'état
            // avec la valeur, pour que le Routing Engine ne puisse pas
            // confondre « non mesuré » avec « mauvais score ».
            'reliability'        => $provider['reliability'] ?? null,
            'reliability_status' => $provider['reliability_status']
                ?? ProviderReliability::UNAVAILABLE,
            'reliability_obs'    => $provider['reliability_obs'] ?? 0,
            'effective_rate'   => round($effectiveRate, 4),
        ];
    }

    /**
     * Taux EUR vers une devise (1 EUR = X unités de devise).
     * Pour XAF/XOF : taux fixe.
     * Pour les autres devises : taux du Currency module.
     */
    private static function rateToEur(string $currency): float
    {
        $rates = [
            'EUR'  => 1.0,
            'USD'  => 1.0870,
            'GBP'  => 0.8550,
            'XAF'  => 655.957,
            'XOF'  => 655.957,
            'USDT' => 1.0870,
            'USDC' => 1.0870,
            'NGN'  => 1650.0,
            'GHS'  => 14.80,
            'KES'  => 141.0,
        ];
        return $rates[$currency] ?? 1.0;
    }
}