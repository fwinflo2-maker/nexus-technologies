<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Execution\ExecutionEnvironment;
use Nexus\Models\FXRate;
use Throwable;

/**
 * QuotePricing — taux de change d'une quote : réel et traçable, ou refusé.
 *
 * POURQUOI CE COMPOSANT EXISTE
 * ────────────────────────────
 * `QuoteEngine` calculait le montant reçu ainsi :
 *
 *     $effectiveRate = self::FIXED_RATE_EUR_TO_XAF * (1 - $spreadPct);
 *     $received      = round($amountAfterFee * $effectiveRate, 0);
 *
 * Deux défauts distincts, tous deux graves.
 *
 * 1. LE TAUX IGNORAIT LA SOURCE FX RÉELLE. Le dépôt possède pourtant une
 *    infrastructure complète — `FXService` → `FXRateCache` →
 *    `ManualRateProvider`, adossée à la table `fx_rates_cache` — et
 *    `WalletService::transferMultiCurrency()` (Convert) l'utilise déjà.
 *    Vérifié en HTTP pendant l'audit : en injectant `EUR→XAF = 100` dans
 *    `fx_rates_cache`, Convert appliquait bien 100 et traçait
 *    `fx_source: audit_test`, tandis que Send annonçait toujours ~650. Deux
 *    chemins du même produit donnaient deux taux différents pour la même
 *    paire, au même instant.
 *
 * 2. LE TAUX XAF ÉTAIT APPLIQUÉ À TOUTES LES DEVISES. `FIXED_RATE_EUR_TO_XAF`
 *    servait quelle que soit `destCurrency`. Mesuré en HTTP pour 100 EUR :
 *    63 027 GHS annoncés là où le montant réel avoisine 1 435 GHS — un
 *    facteur 44. Ce n'est pas une imprécision de taux, c'est un montant sans
 *    rapport avec la devise demandée.
 *
 * 655.957 est la parité FIXE et officielle de l'euro avec le franc CFA : la
 * constante n'était pas fausse en soi, elle était appliquée hors de son
 * domaine de validité et sans jamais consulter la source configurée.
 *
 * DEUX ÉTATS, PAS DE TROISIÈME
 * ────────────────────────────
 *   RESOLVED     le taux vient d'une source identifiable (cache FX ou
 *                provider), avec sa provenance et son horodatage.
 *   UNAVAILABLE  aucune source ne connaît la paire. Aucun taux n'est
 *                inventé, et aucune quote ne peut être produite.
 *
 * Contrairement à la fiabilité ou à la latence — dont l'absence est une
 * information affichable —, un taux manquant rend la quote IMPOSSIBLE : le
 * montant reçu est le cœur de la promesse financière. On refuse donc de
 * coter plutôt que d'annoncer un chiffre non fondé (§12, §13).
 *
 * ISOLATION PAR ENVIRONNEMENT
 * ───────────────────────────
 * La résolution est scopée : un taux sandbox ne peut pas coter en production,
 * et inversement. Le cache par requête est lui aussi clé par environnement —
 * sans quoi la première quote d'une requête fixerait le taux des suivantes,
 * quel que soit leur environnement.
 *
 * TRAÇABILITÉ
 * ───────────
 * Chaque quote transporte désormais l'origine de son taux (`rate_source`),
 * l'instant de son obtention (`rate_fetched_at`) et sa péremption
 * (`rate_expires_at`). Un chiffre financier dont on ne peut pas dire d'où il
 * vient n'est pas auditable.
 */
final class QuotePricing
{
    /** Le taux provient d'une source identifiable. */
    public const RESOLVED = 'RESOLVED';

    /** Aucune source ne connaît la paire : la quote est impossible. */
    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * Cache par requête : le Quote Engine cote chaque provider éligible d'un
     * même corridor. La paire étant identique, une résolution suffit.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $cache = [];

    private function __construct()
    {
    }

    /**
     * Résout le taux d'une paire pour une quote.
     *
     * @return array{status: string, rate: float|null, spread_pct: float,
     *               source: string|null, fetched_at: string|null,
     *               expires_at: string|null, reason: string|null}
     *         `rate` vaut null dès que `status` n'est pas RESOLVED : aucun
     *         appelant ne peut publier un taux non résolu par inadvertance.
     *         `spread_pct` est celui DÉCLARÉ par la source (0 si aucune
     *         marge n'est déclarée) — jamais une valeur tirée au sort.
     */
    public static function resolveRate(
        string $sourceCurrency,
        string $destCurrency,
        ExecutionEnvironment $environment
    ): array {
        $source = strtoupper(trim($sourceCurrency));
        $dest   = strtoupper(trim($destCurrency));

        if ($source === '' || $dest === '') {
            return self::unavailable('Devises source et destination requises.');
        }

        // Une devise vers elle-même : le taux est 1, sans consultation.
        if ($source === $dest) {
            return [
                'status'     => self::RESOLVED,
                'rate'       => 1.0,
                'spread_pct' => 0.0,
                'source'     => 'identity',
                'fetched_at' => null,
                'expires_at' => null,
                'reason'     => null,
            ];
        }

        $key = $environment->value . '|' . $source . '>' . $dest;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $fxRate = FXService::resolve($source, $dest, $environment);
            $result = self::fromFxRate($fxRate);
        } catch (Throwable $e) {
            // FXService lève quand aucune source ne connaît la paire. C'est
            // le comportement voulu : on le traduit en état explicite plutôt
            // que de retomber sur une constante.
            $result = self::unavailable(sprintf(
                'Aucun taux de change disponible pour %s → %s en %s.',
                $source,
                $dest,
                $environment->value
            ));
        }

        self::$cache[$key] = $result;

        return $result;
    }

    /** Réinitialise le cache par requête (tests, ou traitement long). */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Convertit un FXRate en descripteur de taux de quote.
     *
     * @return array{status: string, rate: float|null, spread_pct: float,
     *               source: string|null, fetched_at: string|null,
     *               expires_at: string|null, reason: string|null}
     */
    private static function fromFxRate(FXRate $rate): array
    {
        $value = (float) $rate->getRate();

        // Un taux nul ou négatif n'est pas exploitable : le traiter comme
        // résolu produirait un montant reçu nul ou absurde.
        if ($value <= 0.0) {
            return self::unavailable('Le taux de change obtenu est invalide.');
        }

        // Le spread du cache est exprimé en pourcentage (ex. 0.5000 = 0,5 %).
        $spread = max(0.0, (float) $rate->getSpreadPct()) / 100.0;

        return [
            'status'     => self::RESOLVED,
            'rate'       => $value,
            'spread_pct' => $spread,
            'source'     => $rate->getSource(),
            'fetched_at' => $rate->getFetchedAt()->format(DATE_ATOM),
            'expires_at' => $rate->getExpiresAt()->format(DATE_ATOM),
            'reason'     => null,
        ];
    }

    /**
     * @return array{status: string, rate: float|null, spread_pct: float,
     *               source: string|null, fetched_at: string|null,
     *               expires_at: string|null, reason: string|null}
     */
    private static function unavailable(string $reason): array
    {
        return [
            'status'     => self::UNAVAILABLE,
            'rate'       => null,
            'spread_pct' => 0.0,
            'source'     => null,
            'fetched_at' => null,
            'expires_at' => null,
            'reason'     => $reason,
        ];
    }
}
