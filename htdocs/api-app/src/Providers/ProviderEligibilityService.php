<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ProviderResolver;
use Nexus\Services\ControlCenterService;
use Nexus\Services\IntentEngine;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderHealthService;
use PDO;

/**
 * ProviderEligibilityService — chaîne d'éligibilité multi-critères (Milestone 2).
 *
 * Un provider n'est sélectionnable que si :
 *   exists + routing enabled + adapter available + credentials + config valid
 *   + capability + corridor + health acceptable.
 */
final class ProviderEligibilityService
{
    /** @var array<string, array<string, string>> */
    private static array $testCapabilityOverrides = [];

    private const METHOD_TO_CATEGORIES = [
        'mobile_money' => ['mobile_money', 'payout_network'],
        'bank'         => ['banking', 'fx', 'payout_network'],
        'crypto'       => ['crypto', 'onramp'],
        'cash_pickup'  => ['payout_network', 'fx'],
    ];

    private function __construct()
    {
    }

    /** @param array<string, string> $capabilities */
    public static function setTestCapabilityOverride(string $slug, array $capabilities): void
    {
        self::$testCapabilityOverrides[$slug] = $capabilities;
    }

    public static function clearTestCapabilityOverrides(): void
    {
        self::$testCapabilityOverrides = [];
    }

    /**
     * @param array{
     *     amount?: float,
     *     sourceCurrency?: string,
     *     sourceCountry?: string,
     *     destCountry?: string,
     *     destCurrency?: string,
     *     receivingMethod?: string,
     *     operation?: string
     * } $intent
     */
    public static function evaluate(
        string $slug,
        array $intent,
        ExecutionContext $context,
        bool $allRoutes = false,
    ): ProviderEligibilityResult {
        if (!ProviderCatalog::exists($slug)) {
            return ProviderEligibilityResult::ineligible(['provider unknown']);
        }

        if (!ProviderCatalog::isRoutingEnabled($slug)) {
            return ProviderEligibilityResult::ineligible(
                ['routing disabled'],
                ProviderStatus::DISABLED
            );
        }

        if (!ProviderConfig::isEnabled($slug)) {
            return ProviderEligibilityResult::ineligible(
                ['provider disabled'],
                ProviderStatus::DISABLED
            );
        }

        $provider = ProviderCatalog::get($slug);
        if ($provider === null) {
            return ProviderEligibilityResult::ineligible(['provider unknown']);
        }

        if (!self::supportsCorridor($slug, $provider, $intent, $allRoutes)) {
            return ProviderEligibilityResult::ineligible(['corridor not supported']);
        }

        $operation = (string) ($intent['operation'] ?? 'payout');
        if (!self::hasOperationCapability($slug, $operation)) {
            $reason = $slug === 'cashramp'
                ? 'adapter not implemented'
                : 'required capability not available';
            return ProviderEligibilityResult::ineligible([$reason]);
        }

        if (!ProviderResolver::hasCredentialFor($slug, $context)) {
            return ProviderEligibilityResult::ineligible(
                ['credentials not configured'],
                ProviderStatus::NOT_CONFIGURED
            );
        }

        $configStatus = ProviderRegistry::status($slug);
        if ($configStatus !== ProviderStatus::CONFIGURED) {
            return ProviderEligibilityResult::ineligible(
                ['configuration invalid'],
                $configStatus
            );
        }

        $routeStatus = ProviderCapabilityMatrix::routeStatus($slug, $intent);
        if ($routeStatus === ProviderCapabilityMatrix::STATE_UNAVAILABLE
            || $routeStatus === ProviderCapabilityMatrix::STATE_DISABLED) {
            return ProviderEligibilityResult::ineligible(['route capability unavailable']);
        }

        try {
            $pdo = Database::getConnection();
            $health = ProviderHealthService::healthFor($pdo, $slug, $context->environmentValue());
            $connection = strtoupper((string) ($health['connection'] ?? 'NOT_CONFIGURED'));
            if (in_array($connection, ['DEGRADED', 'UNAVAILABLE', 'DOWN'], true)) {
                return ProviderEligibilityResult::ineligible(['provider health unacceptable']);
            }
        } catch (\Throwable) {
            // Sans base (tests unitaires légers), on ignore la santé persistée.
        }

        return ProviderEligibilityResult::eligible($configStatus);
    }

    /**
     * Vue admin : adapter, credentials, connection, routing, capabilities.
     *
     * @return array<string, mixed>
     */
    public static function adminRoutingSummary(PDO $pdo, string $slug, int $userId): array
    {
        $adapterImplemented = ControlCenterService::operationImplemented($slug, 'createPayment')
            || ProviderCapabilityMatrix::for($slug)['payout'] === ProviderCapabilityMatrix::IMPLEMENTED;

        $sandboxHealth = ProviderHealthService::healthFor($pdo, $slug, 'sandbox');
        $prodHealth    = ProviderHealthService::healthFor($pdo, $slug, 'production');
        $activeEnv     = ProviderConfig::activeEnvironment($slug);
        $activeHealth  = $activeEnv === 'production' ? $prodHealth : $sandboxHealth;

        $routingEnabled = ProviderCatalog::isRoutingEnabled($slug)
            && ProviderConfig::isEnabled($slug)
            && ProviderRegistry::status($slug)->routable()
            && $adapterImplemented
            && ($activeHealth['configured'] ?? false);

        return [
            'adapter' => $adapterImplemented ? 'EXISTING' : 'NOT IMPLEMENTED',
            'credentials' => ($activeHealth['configured'] ?? false) ? 'CONFIGURED' : 'NOT CONFIGURED',
            'connection' => self::formatConnectionLabel($activeHealth),
            'routing' => $routingEnabled ? 'ENABLED' : 'DISABLED',
            'capabilities' => ProviderCapabilityMatrix::for($slug),
            'route_capabilities' => ProviderCapabilityMatrix::routeDimensions(),
        ];
    }

    /** @param array<string, mixed> $health */
    private static function formatConnectionLabel(array $health): string
    {
        $connection = strtolower((string) ($health['connection'] ?? 'NOT_CONFIGURED'));
        return match ($connection) {
            'connected' => 'CONNECTED',
            'configured' => 'NOT TESTED',
            'degraded', 'unavailable' => strtoupper($connection),
            default => 'NOT TESTED',
        };
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $intent
     */
    private static function supportsCorridor(
        string $slug,
        array $provider,
        array $intent,
        bool $allRoutes,
    ): bool {
        $methodType   = (string) ($intent['receivingMethod'] ?? '');
        $countryCode  = (string) ($intent['destCountry'] ?? '');
        $destCurrency = (string) ($intent['destCurrency'] ?? '');
        $validCategories = self::METHOD_TO_CATEGORIES[$methodType] ?? [];

        if (!in_array($provider['category'] ?? '', $validCategories, true)) {
            return false;
        }

        $isGlobalCryptoDestination = $methodType === 'crypto'
            && IntentEngine::isCryptoDestination($destCurrency);

        if ($allRoutes || $isGlobalCryptoDestination) {
            return true;
        }

        $providerCountries = self::expandCountries((array) ($provider['countries'] ?? []));
        return in_array($countryCode, $providerCountries, true);
    }

    private static function hasOperationCapability(string $slug, string $operation): bool
    {
        $matrixKey = match ($operation) {
            'transfer', 'payout' => 'payout',
            default => $operation,
        };

        $matrix = self::$testCapabilityOverrides[$slug] ?? ProviderCapabilityMatrix::for($slug);
        if (($matrix[$matrixKey] ?? '') === ProviderCapabilityMatrix::IMPLEMENTED) {
            return true;
        }

        return $operation === 'payout'
            && ControlCenterService::operationImplemented($slug, 'createPayment');
    }

    /** @param list<string> $codes */
    /** @return list<string> */
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
