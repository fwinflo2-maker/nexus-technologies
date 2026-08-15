<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderConfig;
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
 * Pour chaque provider éligible, retourne sa fiabilité MESURÉE (ou l'état
 * expliquant pourquoi elle ne l'est pas) et les métadonnées nécessaires au
 * Quote Engine.
 *
 * FIABILITÉ : MESURÉE OU DÉCLARÉE INCONNUE (§12, §17)
 * ───────────────────────────────────────────────────
 * Ce moteur portait une constante `PERFORMANCE_SCORES` de 20 valeurs écrites
 * à la main, présentées au client comme une mesure. Elle est supprimée : la
 * fiabilité vient désormais de `ProviderReliability`, qui l'agrège depuis les
 * exécutions réelles. Quand rien n'est mesurable, `reliability` vaut `null`
 * et `reliability_status` dit pourquoi — jamais un nombre plausible.
 */
final class CapabilityEngine
{
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
     *     reliability: float|null,
     *     reliability_status: string,
     *     reliability_obs: int,
     *     delay_min: int,
     *     delay_max: int,
     *     method_type: string,
     * }>
     *
     * @throws HttpException 400 si aucun provider ne couvre le corridor.
     */
    public static function findEligible(array $intent, ?ExecutionEnvironment $environment = null): array
    {
        // La fiabilité se mesure PAR environnement : des succès en sandbox ne
        // disent rien de la production. À défaut de contexte, on suit le
        // défaut du déploiement plutôt qu'une sandbox en dur.
        $environment ??= ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment());

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

            // ── Fiabilité : mesurée, ou explicitement inconnue ───
            $reliability = ProviderReliability::forProvider($slug, $environment);

            // ── Délai estimé ─────────────────────────────────────
            $delays = self::CATEGORY_DELAYS[$provider['category']] ?? [60, 600];

            $eligible[] = [
                'slug'               => $slug,
                'name'               => $provider['name'],
                'category'           => $provider['category'],
                'reliability'        => $reliability['score'],
                'reliability_status' => $reliability['status'],
                'reliability_obs'    => $reliability['observations'],
                'delay_min'          => $delays[0],
                'delay_max'          => $delays[1],
                'method_type'        => $methodType,
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

        // Tri : les fiabilités MESURÉES d'abord, décroissantes ; les providers
        // non mesurés ensuite, à égalité entre eux et ordonnés par nom.
        //
        // `null <=> 0.97` vaudrait -1 et reléguerait un provider non mesuré
        // derrière un provider mauvais : ce serait interpréter « inconnu »
        // comme « mauvais ». Inconnu n'est pas une note (§17), d'où un tri à
        // deux niveaux plutôt qu'une comparaison directe.
        usort($eligible, static function (array $a, array $b): int {
            $aMeasured = $a['reliability'] !== null;
            $bMeasured = $b['reliability'] !== null;

            if ($aMeasured !== $bMeasured) {
                return $aMeasured ? -1 : 1;
            }

            if ($aMeasured && $b['reliability'] !== $a['reliability']) {
                return $b['reliability'] <=> $a['reliability'];
            }

            return strcmp($a['slug'], $b['slug']);
        });

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
