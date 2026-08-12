<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Models\FXRate;
use RuntimeException;

/**
 * Service FX — résolution et conversion de taux (Phase D).
 *
 * Flux de résolution :
 *   FXService → FXRateCache → ManualRateProvider
 *
 *   1. `resolve()` tente d'abord une entrée valide (non expirée) de
 *      `fx_rates_cache`.
 *   2. En cas d'absence ou d'expiration, le taux est obtenu auprès du
 *      `ManualRateProvider` (aucun provider externe en Phase D).
 *   3. Si aucune source ne connaît la paire, une RuntimeException est levée.
 *
 * Le `spread_pct` est conservé dans le cache mais NE modifie PAS le taux :
 * le taux fourni par le provider est le taux final à appliquer.
 *
 * Ce service n'exécute JAMAIS de transfert ni d'écriture comptable :
 * l'orchestration des mouvements appartient à
 * `WalletService::transferMultiCurrency()`.
 */
final class FXService
{
    private function __construct()
    {
        // Classe utilitaire : méthodes statiques uniquement.
    }

    /**
     * Résout le taux d'une paire de devises.
     *
     * Priorité :
     *   1. `fx_rates_cache` (entrée non expirée) ;
     *   2. `ManualRateProvider` (fallback déterministe) ;
     *   3. exception si la paire est inconnue.
     *
     * @param string $baseCurrency  Devise source (ex. 'EUR')
     * @param string $quoteCurrency Devise destination (ex. 'USD')
     *
     * @return FXRate Taux résolu (jamais null).
     *
     * @throws RuntimeException Si la paire n'existe ni en cache ni dans le provider manuel.
     */
    public static function resolve(string $baseCurrency, string $quoteCurrency): FXRate
    {
        $cached = FXRateCache::lookup($baseCurrency, $quoteCurrency);
        if ($cached !== null) {
            return $cached;
        }

        return ManualRateProvider::getRate($baseCurrency, $quoteCurrency);
    }

    /**
     * Convertit un montant source en montant destination.
     *
     * - Montants manipulés en chaînes décimales (BCMath, jamais de float).
     * - Calcul : destination = source × taux.
     * - Précision : 8 décimales, arrondi HALF_UP.
     *
     * Implémentation de l'arrondi HALF_UP :
     *   $raw     = bcmul($source, $rate, 10)   // 10 décimales intermédiaires
     *   $rounded = bcadd($raw, '0.000000005', 8) // + 0.5e-8 puis troncature à 8 dp
     *
     * @param string $sourceAmount Montant source positif (décimal).
     * @param FXRate $rate         Taux à appliquer.
     *
     * @return string Montant destination normalisé à 8 décimales.
     */
    public static function convert(string $sourceAmount, FXRate $rate): string
    {
        // destination = source * rate
        $raw = bcmul($sourceAmount, $rate->getRate(), 10);
        // Arrondi half‑up à 8 décimales.
        return bcadd($raw, '0.000000005', 8);
    }
}
