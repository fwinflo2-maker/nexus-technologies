<?php

declare(strict_types=1);

namespace Nexus\Services;

use DateTimeImmutable;
use Nexus\Models\FXRate;

/**
 * OfficialPegFXProvider — parité officielle fixe EUR ↔ XAF (Cycle 5).
 *
 * PROVENANCE (vérifiée le 2026-08-20, sources officielles) :
 *   Le franc CFA d'Afrique centrale (XAF, émis par la BEAC pour la CEMAC)
 *   est ancré à l'euro par une PARITÉ FIXE DE DROIT :
 *
 *       1 EUR = 655,957 XAF (exactement)
 *
 *   - accord de coopération monétaire franco-africaine ; convertibilité
 *     illimitée garantie par le Trésor français ;
 *   - parité en vigueur depuis le 1999-01-01 (bascule FRF→EUR du même
 *     accord ; 1 EUR = 6,55957 FRF × 100 FCFA/FRF) ;
 *   - documentée par la Banque de France :
 *     https://www.banque-france.fr/en/banque-de-france/africa-france-partnerships
 *     (« The fixed exchange rate with the euro, pegged at
 *     1 euro = 655.957 CFA francs ») et par les publications zone franc de
 *     la Banque de France.
 *
 *   La BCE ne publie PAS de taux de référence EUR/XAF (liste de 29 devises,
 *   XAF absent) : pour cette paire, la parité de droit EST la source
 *   autoritaire — ce n'est pas un taux de marché ni une valeur inventée.
 *
 * PORTÉE : uniquement EUR→XAF et XAF→EUR. Toute autre paire → null
 * (fail-closed en aval). Le taux inverse est dérivé de la parité
 * (1/655,957, arrondi HALF_UP à 8 décimales — précision de
 * fx_rates_cache.rate DECIMAL(20,8)).
 *
 * Le TTL force le passage périodique par cette dérivation attribuée : la
 * parité est constante, mais aucune entrée de cache n'est éternelle.
 */
final class OfficialPegFXProvider implements FXProviderInterface
{
    /** Parité de droit : 1 EUR = 655,957 XAF (exactement). */
    public const EUR_XAF_RATE = '655.95700000';

    /** Inverse dérivé : 1/655,957 arrondi HALF_UP à 8 décimales. */
    public const XAF_EUR_RATE = '0.00152449';

    /** Identifiant de provenance (fx_rates_cache.source). */
    public const SOURCE = 'official_peg_bdf_cfa';

    /** TTL du cache : 24 h. */
    public const TTL_SECONDS = 86400;

    private ?DateTimeImmutable $lastDerivedAt = null;

    public function getSource(): string
    {
        return self::SOURCE;
    }

    public function getPair(string $baseCurrency, string $quoteCurrency): ?array
    {
        $base = strtoupper(trim($baseCurrency));
        $quote = strtoupper(trim($quoteCurrency));
        if (($base === 'EUR' && $quote === 'XAF') || ($base === 'XAF' && $quote === 'EUR')) {
            return [
                'base' => $base,
                'quote' => $quote,
                'kind' => 'fixed_peg',
                'ttl_seconds' => self::TTL_SECONDS,
            ];
        }
        return null;
    }

    public function getRate(string $baseCurrency, string $quoteCurrency): ?FXRate
    {
        $pair = $this->getPair($baseCurrency, $quoteCurrency);
        if ($pair === null) {
            return null;
        }
        $now = new DateTimeImmutable('now');
        $this->lastDerivedAt = $now;
        return new FXRate(
            $pair['base'],
            $pair['quote'],
            $pair['base'] === 'EUR' ? self::EUR_XAF_RATE : self::XAF_EUR_RATE,
            '0.0000',
            self::SOURCE,
            $now,
            $now->modify('+' . self::TTL_SECONDS . ' seconds')
        );
    }

    public function getTimestamp(): ?DateTimeImmutable
    {
        return $this->lastDerivedAt;
    }

    public function health(): array
    {
        return [
            'source'     => self::SOURCE,
            'configured' => true,
            'kind'       => 'fixed_peg',
            'reachable'  => true, // parité de droit : aucune dépendance réseau
            'pairs'      => ['EUR/XAF', 'XAF/EUR'],
            'provenance' => 'Parité fixe 1 EUR = 655,957 XAF — coopération monétaire '
                . 'franco-africaine (BEAC/CEMAC), convertibilité garantie par le Trésor '
                . 'français, documentée par la Banque de France (banque-france.fr). '
                . 'La BCE ne publie pas EUR/XAF.',
            'ladder'     => 'CONFIGURATION_READY',
        ];
    }
}
