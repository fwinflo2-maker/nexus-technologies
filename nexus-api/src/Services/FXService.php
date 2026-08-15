<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Execution\ExecutionEnvironment;
use Nexus\Models\FXRate;
use RuntimeException;

/**
 * Service FX — résolution et conversion de taux (Phase D).
 *
 * Flux de résolution, TOUJOURS dans un environnement donné :
 *   FXService → FXRateCache → ManualRateProvider (sandbox uniquement)
 *
 *   1. `resolve()` tente d'abord une entrée valide (non expirée) de
 *      `fx_rates_cache`, POUR CET ENVIRONNEMENT ;
 *   2. en cas d'absence ou d'expiration, le `ManualRateProvider` prend le
 *      relais — mais en SANDBOX SEULEMENT ;
 *   3. si aucune source ne connaît la paire, une RuntimeException est levée.
 *
 * POURQUOI LE REPLI MANUEL EST INTERDIT EN PRODUCTION
 * ───────────────────────────────────────────────────
 * `ManualRateProvider` porte un jeu de taux CODÉS EN DUR, sans horodatage
 * réel ni provenance externe. Vérifié en HTTP avant correctif : avec un cache
 * vide, une quote demandée en production obtenait `655.957` / source
 * « manual ». Un taux écrit dans le code ne peut pas coter de l'argent réel :
 * en production, l'absence de taux doit produire un REFUS explicite —
 * visible et corrigeable — plutôt qu'une valeur silencieuse (§12, §13).
 *
 * La sandbox, elle, conserve ce repli : elle ne déplace aucun argent réel et
 * doit rester utilisable sans configuration préalable.
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
     * @param ExecutionEnvironment $environment Environnement d'exécution :
     *        un taux sandbox ne doit jamais servir en production.
     *
     * @return FXRate Taux résolu (jamais null).
     *
     * @throws RuntimeException Si la paire n'existe pas dans cet environnement,
     *         ou si la production ne dispose d'aucun taux réel.
     */
    public static function resolve(
        string $baseCurrency,
        string $quoteCurrency,
        ExecutionEnvironment $environment
    ): FXRate {
        $cached = FXRateCache::lookup($baseCurrency, $quoteCurrency, $environment);
        if ($cached !== null) {
            return $cached;
        }

        // Production : pas de repli sur des taux codés en dur. L'absence de
        // taux réel doit se voir, pas se combler.
        if ($environment === ExecutionEnvironment::PRODUCTION) {
            throw new RuntimeException(sprintf(
                'Aucun taux de production disponible pour %s/%s. '
                . 'Un taux de secours ne peut pas coter de l\'argent réel.',
                strtoupper($baseCurrency),
                strtoupper($quoteCurrency)
            ));
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
