<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * Résultat d'évaluation d'éligibilité d'un provider pour une opération.
 */
final class ProviderEligibilityResult
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $reasons,
        public readonly ProviderStatus $status,
    ) {
    }

    public static function eligible(ProviderStatus $status = ProviderStatus::CONFIGURED): self
    {
        return new self(true, [], $status);
    }

    /**
     * @param list<string> $reasons
     */
    public static function ineligible(array $reasons, ProviderStatus $status = ProviderStatus::NOT_CONFIGURED): self
    {
        return new self(false, $reasons, $status);
    }
}
