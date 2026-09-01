<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * ProviderRouteScoring — classement déterministe et explicable des candidats.
 */
final class ProviderRouteScoring
{
    private function __construct()
    {
    }

    /**
     * @param list<ProviderRouteCandidate> $candidates
     * @return list<ProviderRouteCandidate>
     */
    public static function rank(array $candidates): array
    {
        usort($candidates, static function (ProviderRouteCandidate $a, ProviderRouteCandidate $b): int {
            if ($a->eligible !== $b->eligible) {
                return $a->eligible ? -1 : 1;
            }
            if ($a->score !== $b->score) {
                return $b->score <=> $a->score;
            }
            return strcmp($a->provider, $b->provider);
        });

        return $candidates;
    }

    public static function scoreFor(ProviderEligibilityResult $result, string $healthConnection): int
    {
        if (!$result->eligible) {
            return 0;
        }

        $score = 100;
        $health = strtoupper($healthConnection);
        if ($health === 'CONFIGURED' || $health === 'NOT_CONFIGURED') {
            $score -= 10;
        }
        if ($health === 'DEGRADED') {
            $score -= 40;
        }

        return max(0, $score);
    }
}
