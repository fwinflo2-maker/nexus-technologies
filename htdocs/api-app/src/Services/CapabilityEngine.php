<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Execution\ProviderResolver;
use Nexus\Providers\ProviderConfig;

/**
 * Capability Engine — détermine les providers éligibles pour un corridor donné.
 *
 * Délègue la résolution multi-provider au ProviderResolver (Milestone 2).
 */
final class CapabilityEngine
{
    private function __construct()
    {
    }

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
    public static function findEligible(
        array $intent,
        ?ExecutionEnvironment $environment = null,
        bool $allRoutes = false,
        ?ExecutionContext $context = null,
    ): array {
        $environment ??= ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment());

        if ($context === null) {
            $context = ExecutionContext::explicit(
                actorUserId: 0,
                environment: $environment,
            );
        }

        $intent['operation'] = $intent['operation'] ?? 'payout';
        $resolution = ProviderResolver::resolveTransferRoute($intent, $context, $allRoutes);
        $eligible   = $resolution['eligible_providers'];

        if ($eligible === []) {
            $corridorCandidates = self::countCorridorCandidates($intent, $allRoutes);
            $destCurrency = (string) ($intent['destCurrency'] ?? '');
            $countryCode  = (string) ($intent['destCountry'] ?? '');
            $methodType   = (string) ($intent['receivingMethod'] ?? '');

            if ($corridorCandidates > 0) {
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

        usort($eligible, static function (array $a, array $b): int {
            $aMeasured = $a['reliability'] !== null;
            $bMeasured = $b['reliability'] !== null;

            if ($aMeasured !== $bMeasured) {
                return $aMeasured ? -1 : 1;
            }

            if ($aMeasured && $b['reliability'] !== $a['reliability']) {
                return $b['reliability'] <=> $a['reliability'];
            }

            $scoreCmp = ((int) ($b['route_score'] ?? 0)) <=> ((int) ($a['route_score'] ?? 0));
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return strcmp((string) $a['slug'], (string) $b['slug']);
        });

        return array_map(static function (array $row): array {
            unset($row['route_score'], $row['route_reasons']);
            return $row;
        }, $eligible);
    }

    private static function countCorridorCandidates(array $intent, bool $allRoutes): int
    {
        $methodType   = (string) ($intent['receivingMethod'] ?? '');
        $countryCode  = (string) ($intent['destCountry'] ?? '');
        $destCurrency = (string) ($intent['destCurrency'] ?? '');

        $methodToCategories = [
            'mobile_money' => ['mobile_money', 'payout_network'],
            'bank'         => ['banking', 'fx', 'payout_network'],
            'crypto'       => ['crypto', 'onramp'],
            'cash_pickup'  => ['payout_network', 'fx'],
        ];
        $validCategories = $methodToCategories[$methodType] ?? [];
        $count = 0;

        foreach (ProviderCatalog::all() as $provider) {
            if (!in_array($provider['category'], $validCategories, true)) {
                continue;
            }
            $isGlobalCryptoDestination = $methodType === 'crypto'
                && IntentEngine::isCryptoDestination($destCurrency);
            if (!$allRoutes && !$isGlobalCryptoDestination) {
                $countries = $provider['countries'] ?? [];
                if (!in_array($countryCode, $countries, true) && !in_array('EU', $countries, true)) {
                    continue;
                }
            }
            $count++;
        }

        return $count;
    }
}
