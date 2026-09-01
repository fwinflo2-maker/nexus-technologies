<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderEligibilityResult;
use Nexus\Providers\ProviderRouteCandidate;
use Nexus\Providers\ProviderRouteScoring;
use Nexus\Providers\ProviderStatus;
use PHPUnit\Framework\TestCase;

final class ProviderRouteScoringTest extends TestCase
{
    public function testEligibleCandidateScoresHigherThanIneligible(): void
    {
        $eligible = new ProviderRouteCandidate(
            'moneygram',
            'payout',
            'EUR',
            'USD',
            'FR',
            'US',
            'bank',
            true,
            null,
            null,
            null,
            'CONFIGURED',
            ProviderRouteScoring::scoreFor(ProviderEligibilityResult::eligible(), 'CONFIGURED'),
            [],
        );

        $ineligible = new ProviderRouteCandidate(
            'pawapay',
            'payout',
            'EUR',
            'XAF',
            'FR',
            'CM',
            'mobile_money',
            false,
            null,
            null,
            null,
            'NOT_CONFIGURED',
            ProviderRouteScoring::scoreFor(
                ProviderEligibilityResult::ineligible(['routing disabled'], ProviderStatus::DISABLED),
                'NOT_CONFIGURED'
            ),
            ['routing disabled'],
        );

        $ranked = ProviderRouteScoring::rank([$ineligible, $eligible]);

        self::assertTrue($ranked[0]->eligible);
        self::assertSame('moneygram', $ranked[0]->provider);
        self::assertGreaterThan($ranked[1]->score, $ranked[0]->score);
    }

    public function testIneligibleCandidateAlwaysScoresZero(): void
    {
        $score = ProviderRouteScoring::scoreFor(
            ProviderEligibilityResult::ineligible(['credentials not configured']),
            'NOT_CONFIGURED'
        );

        self::assertSame(0, $score);
    }
}
