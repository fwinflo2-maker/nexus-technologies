<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Currency;
use Nexus\Execution\ExecutionEnvironment;

/**
 * ReferenceConverter — conversion vers les devises de référence (EUR, XAF).
 *
 * POURQUOI CE COMPOSANT EXISTE
 * ────────────────────────────
 * `Currency::RATE_TO_EUR` et `Currency::RATE_TO_XAF` sont deux tables de taux
 * écrites en dur, documentées dans le code comme « taux de démo » :
 *
 *     'USD' => 0.92        // RATE_TO_EUR
 *     'USD' => 603.0       // RATE_TO_XAF
 *
 * Elles alimentaient pourtant des chemins financiers réels. Vérifié pendant
 * l'audit :
 *
 *   - `ExecutionEngine` s'en sert pour `amount_ref` et `amount_xaf`, PERSISTÉS
 *     au ledger, et pour convertir les frais réellement débités au client ;
 *   - `QuoteService` et `QuoteController` s'en servent (sous une forme
 *     équivalente) pour le montant comparé aux PLAFONDS KYC du PolicyEngine.
 *
 * Ces valeurs ignoraient totalement `FXService`. Preuve obtenue en injectant
 * `1 EUR = 5 USD` dans le cache FX : `FXService` renvoyait bien 5,00, tandis
 * que `Currency::rateToRef('USD')` restait à 0,92 — un écart de 4,6× sur un
 * montant porté au ledger.
 *
 * Preuve HTTP côté policy : avec un taux FX à 1,10 puis à 5,00 (×4,5), le
 * PolicyEngine rendait un verdict IDENTIQUE (« il vous reste 750 EUR »). Un
 * contrôle de sécurité insensible au taux qu'il prétend appliquer ne protège
 * rien.
 *
 * CE COMPOSANT N'EST PAS UNE SECONDE SOURCE FX
 * ────────────────────────────────────────────
 * Il ne connaît aucun taux. Il délègue intégralement à `QuotePricing`, donc à
 * `FXService` → `FXRateCache` → `ManualRateProvider`. Son seul rôle est
 * d'exprimer un besoin récurrent — « combien vaut ce montant en EUR / en
 * XAF ? » — sans que chaque appelant réimplémente la résolution.
 *
 * REPLI : SANDBOX UNIQUEMENT, ET EXPLICITE
 * ────────────────────────────────────────
 * Quand aucun taux réel n'est disponible :
 *
 *   - en SANDBOX, on retombe sur les constantes `Currency` (elles restent
 *     utiles pour un environnement de démonstration qui doit fonctionner sans
 *     configuration) et l'état le signale (`FALLBACK`) ;
 *   - en PRODUCTION, on ne retombe sur rien. L'appelant reçoit `UNAVAILABLE`
 *     et doit décider — refuser, ou marquer la valeur comme non calculable.
 *     Un montant porté au ledger ou comparé à un plafond ne peut pas reposer
 *     sur une constante de démonstration.
 *
 * Le repli n'est jamais silencieux : `status` distingue toujours une valeur
 * mesurée d'une valeur de secours.
 */
final class ReferenceConverter
{
    /** Le taux vient d'une source réelle (cache FX ou provider). */
    public const RESOLVED = 'RESOLVED';

    /** Aucun taux réel : constantes de démonstration (SANDBOX uniquement). */
    public const FALLBACK = 'FALLBACK';

    /** Aucun taux réel et aucun repli autorisé (PRODUCTION). */
    public const UNAVAILABLE = 'UNAVAILABLE';

    private function __construct()
    {
    }

    /**
     * Combien vaut 1 unité de `$currency` en EUR ?
     *
     * @return array{status: string, rate: float|null, source: string|null}
     */
    public static function toEur(string $currency, ExecutionEnvironment $environment): array
    {
        return self::resolve($currency, Currency::REF, $environment, static fn (): float => Currency::rateToRef($currency));
    }

    /**
     * Combien vaut 1 unité de `$currency` en XAF ?
     *
     * @return array{status: string, rate: float|null, source: string|null}
     */
    public static function toXaf(string $currency, ExecutionEnvironment $environment): array
    {
        return self::resolve($currency, Currency::VOLUME_REF, $environment, static fn (): float => Currency::rateToXaf($currency));
    }

    /**
     * Convertit un montant vers l'EUR, ou rend null si non calculable.
     *
     * Raccourci pour les appelants qui veulent un montant plutôt qu'un taux.
     */
    public static function amountToEur(
        float $amount,
        string $currency,
        ExecutionEnvironment $environment
    ): ?float {
        $rate = self::toEur($currency, $environment);

        return $rate['rate'] === null ? null : round($amount * $rate['rate'], 2);
    }

    /** Convertit un montant vers le XAF, ou rend null si non calculable. */
    public static function amountToXaf(
        float $amount,
        string $currency,
        ExecutionEnvironment $environment
    ): ?float {
        $rate = self::toXaf($currency, $environment);

        return $rate['rate'] === null ? null : round($amount * $rate['rate'], 2);
    }

    /**
     * Résout « 1 unité de $currency = ? unités de $target ».
     *
     * @param callable():float $legacyFallback Constante historique, utilisée
     *        en sandbox seulement.
     *
     * @return array{status: string, rate: float|null, source: string|null}
     */
    private static function resolve(
        string $currency,
        string $target,
        ExecutionEnvironment $environment,
        callable $legacyFallback
    ): array {
        $currency = strtoupper(trim($currency));
        $target   = strtoupper(trim($target));

        if ($currency === '' ) {
            return self::unavailable($environment, $legacyFallback);
        }

        if ($currency === $target) {
            return ['status' => self::RESOLVED, 'rate' => 1.0, 'source' => 'identity'];
        }

        // `QuotePricing` cote « 1 base = N quote ». Pour obtenir la valeur
        // d'une unité de $currency exprimée en $target, la paire naturelle est
        // $currency → $target.
        $direct = QuotePricing::resolveRate($currency, $target, $environment);
        if ($direct['status'] === QuotePricing::RESOLVED && $direct['rate'] !== null && $direct['rate'] > 0.0) {
            return [
                'status' => self::RESOLVED,
                'rate'   => (float) $direct['rate'],
                'source' => $direct['source'],
            ];
        }

        // Beaucoup de déploiements ne stockent que le sens EUR → X. L'inverse
        // est alors déductible sans inventer quoi que ce soit : c'est le même
        // taux, lu dans l'autre sens.
        $inverse = QuotePricing::resolveRate($target, $currency, $environment);
        if ($inverse['status'] === QuotePricing::RESOLVED && $inverse['rate'] !== null && $inverse['rate'] > 0.0) {
            return [
                'status' => self::RESOLVED,
                'rate'   => 1.0 / (float) $inverse['rate'],
                'source' => $inverse['source'],
            ];
        }

        return self::unavailable($environment, $legacyFallback);
    }

    /**
     * @param callable():float $legacyFallback
     * @return array{status: string, rate: float|null, source: string|null}
     */
    private static function unavailable(ExecutionEnvironment $environment, callable $legacyFallback): array
    {
        // Production : aucun repli. Une constante de démonstration ne peut ni
        // être portée au ledger ni servir de base à un contrôle de plafond.
        if ($environment === ExecutionEnvironment::PRODUCTION) {
            return ['status' => self::UNAVAILABLE, 'rate' => null, 'source' => null];
        }

        $rate = (float) $legacyFallback();
        if ($rate <= 0.0) {
            return ['status' => self::UNAVAILABLE, 'rate' => null, 'source' => null];
        }

        return ['status' => self::FALLBACK, 'rate' => $rate, 'source' => 'currency_constants'];
    }
}
