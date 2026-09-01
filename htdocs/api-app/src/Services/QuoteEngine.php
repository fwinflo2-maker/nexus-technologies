<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderConfig;

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
 * TAUX : RÉEL ET TRAÇABLE, OU PAS DE QUOTE (§12)
 * ──────────────────────────────────────────────
 * Le taux venait de `FIXED_RATE_EUR_TO_XAF`, appliqué à TOUTES les devises de
 * destination. Mesuré en HTTP : 100 EUR annonçaient 63 027 GHS là où le
 * montant réel avoisine 1 435 GHS — un facteur 44. Et le taux ignorait
 * `fx_rates_cache`, que Convert utilisait pourtant déjà : les deux chemins
 * donnaient deux taux différents pour la même paire au même instant.
 *
 * Le taux est désormais résolu par `QuotePricing`, adossé à `FXService`. Si
 * aucune source ne connaît la paire, AUCUNE quote n'est produite : le montant
 * reçu est le cœur de la promesse financière, il ne peut pas être estimé.
 *
 * SPREAD : CELUI DE LA SOURCE, JAMAIS TIRÉ AU SORT
 * ────────────────────────────────────────────────
 * Le spread était généré par `mt_rand()` dans une fourchette de 0,1 à 1,0 %
 * « pour simuler la concurrence entre providers ». Un spread est une marge
 *
 * Milestone 2 — contrat futur provider-native :
 *   eligible providers → adapter.getQuote($intent) → Nexus fees → quote finale.
 *   Non branché tant que Cashramp ne fournit pas de quotes réelles.
 * appliquée à de l'argent réel : il vient maintenant de `fx_rates_cache`
 * (colonne `spread_pct`), et vaut 0 quand la source n'en déclare aucun.
 *
 * Le frais de base dépend de la méthode de réception :
 *   - mobile_money : 2.90 EUR
 *   - bank         : 4.50 EUR
 *   - crypto       : 3.50 EUR
 *   - cash_pickup  : 5.50 EUR
 *
 * Ces frais restent un barème Nexus (fixe, non aléatoire) et non un frais
 * provider réel : les intégrations providers ne sont pas branchées. La quote
 * expose `fee_source` pour que cette nature soit explicite côté client.
 */
final class QuoteEngine
{
    /** Frais de base par méthode de réception (EUR). */
    private const BASE_FEES = [
        'mobile_money' => '2.90',
        'bank'         => '4.50',
        'crypto'       => '3.50',
        'cash_pickup'  => '5.50',
    ];

    /** Origine du barème de frais, exposée au client (§12). */
    private const FEE_SOURCE = 'nexus_schedule';

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
    public static function quote(
        array $intent,
        array $provider,
        ?string $seed = null,
        ?ExecutionEnvironment $environment = null
    ): array {
        // À défaut de contexte, on suit le défaut du DÉPLOIEMENT, jamais
        // « sandbox » en dur : un appelant oublieux ne doit pas contourner
        // l'isolation.
        $environment ??= ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment());

        $methodType   = $intent['receivingMethod'];
        $destCurrency = $intent['destCurrency'];
        $sourceAmount = bcadd((string) $intent['amount'], '0', 8);

        // ── Taux : résolu depuis la source FX, ou pas de quote ──
        // La paire réellement cotée est source → destination. Utiliser le
        // taux EUR→XAF pour une destination en GHS ou KES produisait un
        // montant sans rapport avec la devise demandée.
        $pricing = QuotePricing::resolveRate((string) $intent['sourceCurrency'], (string) $destCurrency, $environment);

        if ($pricing['status'] !== QuotePricing::RESOLVED || $pricing['rate'] === null) {
            throw new QuoteRateUnavailable(
                (string) $intent['sourceCurrency'],
                (string) $destCurrency,
                (string) ($pricing['reason'] ?? 'Taux de change indisponible.')
            );
        }

        $baseRate = (string) ($pricing['rate_decimal'] ?? $pricing['rate']);

        // ── Spread : celui déclaré par la source, jamais tiré au sort ──
        $spreadPct = (string) ($pricing['spread_decimal'] ?? $pricing['spread_pct']);

        // ── Frais : barème Nexus, fixe et reproductible ────────
        // La variation aléatoire de ±10 % « pour simuler la concurrence »
        // faisait varier un frais facturé à un client : elle est supprimée.
        $fees = self::BASE_FEES[$methodType] ?? '3.50';

        // ── Montant reçu ───────────────────────────────────────
        // Les frais sont exprimés en EUR : ils se déduisent du montant source
        // converti en EUR, avant application du taux vers la destination.
        $sourceToEur = self::rateToEur((string) $intent['sourceCurrency'], $environment);
        $feesInSource = bcmul($fees, $sourceToEur, 8);
        $amountAfterFee = bcsub($sourceAmount, $feesInSource, 8);
        if (bccomp($amountAfterFee, '0', 8) < 0) {
            $amountAfterFee = '0.00000000';
        }
        $effectiveRate = bcmul($baseRate, bcsub('1', $spreadPct, 8), 8);
        $received = self::roundHalfUp(bcmul($amountAfterFee, $effectiveRate, 8), 0);

        // ── Délai moyen (référence, arrondi en minutes) ─────────
        // Délai : mesuré (médiane, en secondes) ou inconnu. La moyenne d'une
        // fourchette inventée n'a plus lieu d'être — il n'y a plus de
        // fourchette inventée. `null` traverse la chaîne pour que le
        // Routing Engine ne puisse pas confondre « non mesuré » et « rapide ».
        $delaySeconds = $provider['delay_seconds'] ?? null;
        $delayAvg     = $delaySeconds === null
            ? null
            : max(1, (int) round($delaySeconds / 60));

        return [
            'provider_slug'    => $provider['slug'],
            'provider_name'    => $provider['name'],
            'received'         => (float) $received,
            'received_currency' => $destCurrency,
            'fees'             => (float) $fees,
            'fee_currency'     => 'EUR',
            // Barème Nexus, pas un frais provider réel : la nature du chiffre
            // doit être lisible par le client.
            'fee_source'       => self::FEE_SOURCE,
            'rate'             => (float) $baseRate,
            'spread_pct'       => (float) self::roundHalfUp(bcmul($spreadPct, '100', 8), 3),
            // Provenance du taux : sans elle, aucun chiffre de la quote n'est
            // auditable a posteriori.
            'rate_source'      => $pricing['source'],
            'rate_fetched_at'  => $pricing['fetched_at'],
            'rate_expires_at'  => $pricing['expires_at'],
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
            'effective_rate'   => (float) self::roundHalfUp($effectiveRate, 4),
        ];
    }

    /**
     * Taux EUR vers une devise (1 EUR = X unités de devise), RÉEL.
     *
     * Les frais du barème sont libellés en EUR : convertir le montant source
     * en EUR exige un taux. Il vient de la source FX réelle (comme le taux
     * principal) ; l'identité EUR→EUR vaut 1. Sans taux, la quote est
     * impossible — refus explicite (§7) : aucun tableau de taux statique ne
     * subsiste.
     */
    private static function rateToEur(string $currency, ExecutionEnvironment $environment): string
    {
        $pricing = QuotePricing::resolveRate('EUR', $currency, $environment);
        if ($pricing['status'] === QuotePricing::RESOLVED && $pricing['rate'] !== null) {
            return (string) ($pricing['rate_decimal'] ?? $pricing['rate']);
        }

        throw new QuoteRateUnavailable(
            'EUR',
            $currency,
            sprintf('Aucun taux de change disponible pour EUR → %s : frais non calculables.', $currency)
        );
    }

    private static function roundHalfUp(string $value, int $scale): string
    {
        $increment = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';
        return bcadd($value, $increment, $scale);
    }
}