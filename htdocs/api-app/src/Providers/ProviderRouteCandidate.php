<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * ProviderRouteCandidate — route expliquée produite par le resolver.
 */
final class ProviderRouteCandidate
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $operation,
        public readonly string $sourceCurrency,
        public readonly string $destinationCurrency,
        public readonly string $sourceCountry,
        public readonly string $destinationCountry,
        public readonly string $channel,
        public readonly bool $eligible,
        public readonly ?float $estimatedFee,
        public readonly ?float $estimatedFx,
        public readonly ?int $estimatedDeliverySeconds,
        public readonly string $providerHealth,
        public readonly int $score,
        public readonly array $reasons,
    ) {
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $provider
     */
    public static function fromEvaluation(
        string $slug,
        array $provider,
        array $intent,
        ProviderEligibilityResult $result,
        int $score,
        string $healthConnection,
    ): self {
        return new self(
            provider: $slug,
            operation: (string) ($intent['operation'] ?? 'payout'),
            sourceCurrency: strtoupper((string) ($intent['sourceCurrency'] ?? '')),
            destinationCurrency: strtoupper((string) ($intent['destCurrency'] ?? '')),
            sourceCountry: strtoupper((string) ($intent['sourceCountry'] ?? '')),
            destinationCountry: strtoupper((string) ($intent['destCountry'] ?? '')),
            channel: (string) ($intent['receivingMethod'] ?? ''),
            eligible: $result->eligible,
            estimatedFee: null,
            estimatedFx: null,
            estimatedDeliverySeconds: null,
            providerHealth: $healthConnection,
            score: $score,
            reasons: $result->reasons,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider'                 => $this->provider,
            'operation'                => $this->operation,
            'source_currency'          => $this->sourceCurrency,
            'destination_currency'     => $this->destinationCurrency,
            'source_country'           => $this->sourceCountry,
            'destination_country'      => $this->destinationCountry,
            'channel'                  => $this->channel,
            'eligible'                 => $this->eligible,
            'estimated_fee'            => $this->estimatedFee,
            'estimated_fx'             => $this->estimatedFx,
            'estimated_delivery'       => $this->estimatedDeliverySeconds,
            'provider_health'          => $this->providerHealth,
            'score'                    => $this->score,
            'reasons'                  => $this->reasons,
        ];
    }
}
