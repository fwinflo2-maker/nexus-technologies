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
 *   FXService → FXRateCache
 *
 *   1. `resolve()` tente une entrée valide (non expirée) de
 *      `fx_rates_cache`, POUR CET ENVIRONNEMENT ;
 *   2. si aucune source ne connaît la paire, une RuntimeException est levée.
 *
 * AUCUN TAUX CODÉ EN DUR — DANS AUCUN ENVIRONNEMENT
 * ────────────────────────────────────────────────
 * Le repli historique `ManualRateProvider` (jeu de taux codés en dur) est
 * supprimé : un taux écrit dans le code ne peut pas coter de l'argent, pas
 * même en sandbox. La sandbox d'un provider réel n'existe que lorsque ses
 * credentials sandbox sont configurées ; tant qu'aucune source FX réelle
 * n'est branchée, l'absence de taux produit un REFUS explicite
 * (`FX_RATE_NOT_AVAILABLE`), visible et corrigeable — jamais une valeur
 * silencieuse (§7).
 *
 * Le `spread_pct` est conservé dans le cache mais NE modifie PAS le taux :
 * le taux fourni par la source est le taux final à appliquer.
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
     *   1. `fx_rates_cache` (entrée non expirée, environnement scopé) ;
     *   2. exception si la paire est inconnue dans cet environnement.
     *
     * @param string $baseCurrency  Devise source (ex. 'EUR')
     * @param string $quoteCurrency Devise destination (ex. 'USD')
     * @param ExecutionEnvironment $environment Environnement d'exécution :
     *        un taux sandbox ne doit jamais servir en production.
     *
     * @return FXRate Taux résolu (jamais null).
     *
     * @throws RuntimeException Si la paire n'existe pas dans cet environnement.
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

        throw new RuntimeException(sprintf(
            'Aucun taux de change configuré pour %s → %s en %s. '
            . "Aucune source FX réelle n'est disponible : le système refuse de coter.",
            strtoupper($baseCurrency),
            strtoupper($quoteCurrency),
            $environment->value
        ));
    }

    /**
     * Taux brut d'une paire (1 base = X quote), ou null si indisponible.
     *
     * Ne lève jamais : l'indisponibilité est une valeur (null), pas une
     * panne — les agrégats affichent « indisponible » au lieu d'inventer
     * un taux (§7, §9).
     */
    public static function rate(string $baseCurrency, string $quoteCurrency, ExecutionEnvironment $environment): ?float
    {
        try {
            $rate = self::resolve($baseCurrency, $quoteCurrency, $environment);
            $value = (float) $rate->getRate();
            return $value > 0.0 ? $value : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Taux EUR de référence d'une devise (projections, agrégats dashboard).
     *
     * Convention : « 1 EUR = X <devise> » (XAF → 655.957). Identité pour
     * l'EUR ; sinon résolution depuis la source FX réelle dans
     * l'environnement donné. Retourne null quand aucun taux n'est disponible :
     * les appels affichent alors « indisponible » au lieu d'une valeur
     * inventée (§7, §9).
     *
     * Pour convertir un montant DEVISE → EUR : montant / rateToRef().
     * Pour convertir un montant EUR → DEVISE : montant × rateToRef().
     *
     * @return float|null 1.0 pour EUR, le taux EUR→devise, ou null si inconnu.
     */
    public static function rateToRef(string $currency, ExecutionEnvironment $environment): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return null;
        }
        if ($currency === 'EUR') {
            return 1.0;
        }
        return self::rate('EUR', $currency, $environment);
    }

    /**
     * Taux XAF de référence d'une devise : « 1 <devise> = X XAF ».
     *
     * Dérivé des taux réels EUR→devise et EUR→XAF. Null dès qu'une des deux
     * paires est indisponible (jamais de valeur inventée).
     */
    public static function rateToXaf(string $currency, ExecutionEnvironment $environment): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return null;
        }
        $eurToXaf = self::rate('EUR', 'XAF', $environment);
        if ($eurToXaf === null) {
            return null;
        }
        if ($currency === 'XAF') {
            return 1.0;
        }
        $eurToCurrency = self::rate('EUR', $currency, $environment);
        return $eurToCurrency !== null && $eurToCurrency > 0.0
            ? $eurToXaf / $eurToCurrency
            : null;
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
