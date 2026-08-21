<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderCapabilityMatrix;
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
 * DÉLAIS : MESURÉS OU DÉCLARÉS INCONNUS (§12, §17)
 * ────────────────────────────────────────────────
 * `CATEGORY_DELAYS` fixait le délai par CATÉGORIE : les trois providers
 * Mobile Money d'un corridor annonçaient donc le même « ~3 min », quels que
 * soient leurs temps réels. Elle est supprimée au profit de
 * `ProviderLatency`, qui mesure depuis `transactions.execution_time_seconds`.
 * Sans mesure, `delay_seconds` vaut `null` et `delay_status` dit pourquoi.
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
        // cash_pickup : retrait en espèces — Western Union (payout_network) et
        // les réseaux de paiement / FX cash-out historiques.
        'cash_pickup'  => ['payout_network', 'fx'],
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
     *     delay_seconds: int|null,
     *     delay_status: string,
     *     delay_obs: int,
     *     delay_p90_seconds: int|null,
     *     method_type: string,
     * }>
     *
     * @throws HttpException 400 si aucun provider ne couvre le corridor.
     */
    public static function findEligible(array $intent, ?ExecutionEnvironment $environment = null, bool $allRoutes = false): array
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

        $eligible   = [];
        $candidates = 0;

        foreach (ProviderCatalog::all() as $slug => $provider) {
            // ── Filtre 1 : catégorie compatible ──────────────────
            if (!in_array($provider['category'], $validCategories, true)) {
                continue;
            }

            // ── Filtre 2 : couvre le pays de destination ─────────
            // On étend « EU » vers les pays individuels. Le Super Admin
            // ($allRoutes) accède à TOUTES les routes, y compris hors des
            // pays couverts par le provider.
            // Un actif crypto de réception est un rail global : Bridge ou un
            // on-ramp ne « couvre » pas un pays au sens d'un payout fiat.
            // Les devises fiat restent, elles, strictement filtrées par pays.
            $isGlobalCryptoDestination = $methodType === 'crypto'
                && IntentEngine::isCryptoDestination((string) $destCurrency);
            if (!$allRoutes && !$isGlobalCryptoDestination) {
                $providerCountries = self::expandCountries($provider['countries']);
                if (!in_array($countryCode, $providerCountries, true)) {
                    continue;
                }
            }

            // Un provider couvre ce corridor par le catalogue : c'est un
            // candidat. S'il n'est pas réellement configuré, il est exclu —
            // mais le distinguer du « corridor non couvert » permet au client
            // de comprendre la vraie raison du refus (§10).
            $candidates++;

            // ── Filtre 3 : disponibilité réelle (§10, §12, §13) ──
            // Catalogue ≠ opérationnel : seuls les providers CONFIGURÉS
            // (env scopé ou credentials plateforme en base) participent au
            // routing. Le mode démo — tout le catalogue éligible tant qu'aucun
            // provider n'était configuré — est supprimé : un provider
            // désactivé ou sans credentials est ignoré, et ne casse jamais le
            // Core.
            if (!ProviderRegistry::isAvailableForRouting($slug)) {
                continue;
            }

            // ── Filtre 4 : capacité payout RÉELLEMENT implémentée (§21) ──
            // Configuré ≠ exécutable. Un shell (pawaPay) ou un ConfigDriven
            // ne doit jamais apparaître dans le routing tant que la matrice
            // ne déclare pas payout=IMPLEMENTED.
            if (ProviderCapabilityMatrix::for($slug)['payout'] !== ProviderCapabilityMatrix::IMPLEMENTED) {
                continue;
            }

            // ── Fiabilité : mesurée, ou explicitement inconnue ───
            $reliability = ProviderReliability::forProvider($slug, $environment);

            // ── Délai : mesuré, ou explicitement inconnu ────────
            $latency = ProviderLatency::forProvider($slug, $environment);

            $eligible[] = [
                'slug'               => $slug,
                'name'               => $provider['name'],
                'category'           => $provider['category'],
                'reliability'        => $reliability['score'],
                'reliability_status' => $reliability['status'],
                'reliability_obs'    => $reliability['observations'],
                'delay_seconds'      => $latency['seconds'],
                'delay_status'       => $latency['status'],
                'delay_obs'          => $latency['observations'],
                'delay_p90_seconds'  => $latency['p90_seconds'],
                'method_type'        => $methodType,
            ];
        }

        if (empty($eligible)) {
            if ($candidates > 0) {
                // Des providers couvrent le corridor mais aucun n'est
                // configuré : refus opérationnel, distinct d'un manque de
                // couverture (§6, §10).
                throw new HttpException(
                    409,
                    "Aucun provider configuré n'est disponible pour le corridor " .
                    "{$intent['sourceCurrency']}→{$destCurrency} ({$countryCode}) via {$methodType}. " .
                    'Configurez d\'abord les credentials du provider dans la console d\'administration.',
                    'NO_AVAILABLE_PROVIDER'
                );
            }

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
