<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;
use Nexus\Providers\ProviderRegistry;

/**
 * Capability Engine — détermine les providers éligibles pour un corridor donné.
 *
 * Étape 2 du pipeline. Reçoit l'intention normalisée et retourne la liste
 * des providers capables de traiter le transfert (corridor EUR→XAF,
 * Mobile Money, pays CG, etc.).
 *
 * Source de vérité : le ProviderCatalog (registre statique des providers)
 * couplé aux données de couverture d'IntentEngine (pays, devise, méthodes).
 *
 * Pour chaque provider éligible, retourne un score de fiabilité simulé
 * (performance_score) et les métadonnées nécessaires au Quote Engine.
 */
final class CapabilityEngine
{
    /**
     * Score de fiabilité par défaut (si pas de performance historique).
     * Sur une échelle de 0 à 1.
     */
    private const DEFAULT_RELIABILITY = 0.95;

    /**
     * Mapping provider slug → performance score (simulation démo).
     * En production, ces valeurs viendraient de la table providers
     * ou d'un service de métriques.
     */
    private const PERFORMANCE_SCORES = [
        'pawapay'          => 0.97,
        'thunes'           => 0.93,
        'orange_money'     => 0.95,
        'mtn_momo'         => 0.94,
        'safaricom_mpesa'  => 0.96,
        'dlocal'           => 0.88,
        'ebanx'            => 0.86,
        'onfriq'           => 0.91,
        'noah'             => 0.85,
        'yellow_card'      => 0.89,
        'cashramp'         => 0.82,
        'xendit'           => 0.87,
        'nium'             => 0.90,
        'wise'             => 0.98,
        'currencycloud'    => 0.96,
        'swan'             => 0.97,
        'modulr'           => 0.94,
        'bvnk'             => 0.93,
        'bridge'           => 0.91,
        'stripe'           => 0.99,
    ];

    /**
     * Mapping method_type → catégories de providers éligibles.
     */
    private const METHOD_TO_CATEGORIES = [
        'mobile_money' => ['mobile_money', 'payout_network'],
        'bank'         => ['banking', 'fx', 'payout_network'],
        'crypto'       => ['crypto', 'onramp'],
        'cash_pickup'  => ['payout_network'],
    ];

    /**
     * Temps de transfert moyen par catégorie (secondes, simulation démo).
     */
    private const CATEGORY_DELAYS = [
        'mobile_money'  => [60, 300],    // 1-5 min
        'banking'       => [180, 600],   // 3-10 min
        'fx'            => [120, 480],   // 2-8 min
        'payout_network'=> [60, 900],    // 1-15 min
        'crypto'        => [30, 180],    // 30s-3 min
        'onramp'        => [60, 360],    // 1-6 min
    ];

    private function __construct() {}

    /**
     * Détermine les providers éligibles pour l'intention donnée.
     *
     * @param array{amount: float, sourceCurrency: string, destCountry: string,
     *              destCurrency: string, receivingMethod: string} $intent
     *
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     category: string,
     *     reliability: float,
     *     delay_min: int,
     *     delay_max: int,
     *     method_type: string,
     * }>
     *
     * @throws HttpException 400 si aucun provider ne couvre le corridor.
     */
    public static function findEligible(array $intent): array
    {
        $countryCode  = $intent['destCountry'];
        $methodType   = $intent['receivingMethod'];
        $destCurrency = $intent['destCurrency'];

        // Catégories de providers compatibles avec le mode de réception
        $validCategories = self::METHOD_TO_CATEGORIES[$methodType] ?? [];

        $eligible = [];

        foreach (ProviderCatalog::all() as $slug => $provider) {
            // ── Filtre 1 : catégorie compatible ──────────────────
            if (!in_array($provider['category'], $validCategories, true)) {
                continue;
            }

            // ── Filtre 2 : couvre le pays de destination ─────────
            // On étend « EU » vers les pays individuels
            $providerCountries = self::expandCountries($provider['countries']);
            if (!in_array($countryCode, $providerCountries, true)) {
                continue;
            }

            // ── Filtre 3 : disponibilité réelle (§12, §13) ────────
            // En mode démo (aucun provider configuré via l'environnement),
            // tous les providers du catalogue restent éligibles (comportement
            // historique). Dès qu'au moins un provider est réellement configuré
            // (mode strict), seuls les providers CONFIGURÉS participent au
            // routing : un provider désactivé ou sans credentials est ignoré,
            // et ne casse jamais le Core.
            if (!ProviderRegistry::isAvailableForRouting($slug)) {
                continue;
            }

            // ── Score de fiabilité ───────────────────────────────
            $reliability = self::PERFORMANCE_SCORES[$slug] ?? self::DEFAULT_RELIABILITY;

            // ── Délai estimé ─────────────────────────────────────
            $delays = self::CATEGORY_DELAYS[$provider['category']] ?? [60, 600];

            $eligible[] = [
                'slug'        => $slug,
                'name'        => $provider['name'],
                'category'    => $provider['category'],
                'reliability' => $reliability,
                'delay_min'   => $delays[0],
                'delay_max'   => $delays[1],
                'method_type' => $methodType,
            ];
        }

        if (empty($eligible)) {
            throw new HttpException(
                400,
                "Aucun provider ne couvre le corridor {$intent['sourceCurrency']}→{$destCurrency} ({$countryCode}) " .
                "via {$methodType}. Essayez un autre mode de réception.",
                'NO_PROVIDER'
            );
        }

        // Tri par fiabilité décroissante
        usort($eligible, static fn (array $a, array $b): int =>
            $b['reliability'] <=> $a['reliability']
        );

        return $eligible;
    }

    /**
     * Déplie les codes « EU » en pays individuels (EU-27).
     */
    private static function expandCountries(array $codes): array
    {
        $eu = [
            'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI',
            'FR','GR','HR','HU','IE','IT','LT','LU','LV','MT',
            'NL','PL','PT','RO','SE','SI','SK',
        ];

        $expanded = [];
        foreach ($codes as $code) {
            if ($code === 'EU') {
                foreach ($eu as $euCode) {
                    $expanded[$euCode] = true;
                }
            } else {
                $expanded[$code] = true;
            }
        }
        return array_keys($expanded);
    }
}
