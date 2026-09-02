<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderEligibilityService;
use Nexus\Providers\ProviderRegistry;
use Nexus\Providers\ProviderStatus;
use Nexus\Tests\Support\FakeFutureProviderAdapter;
use PHPUnit\Framework\TestCase;

final class ProviderEligibilityTest extends TestCase
{
    private ExecutionContext $context;

    protected function setUp(): void
    {
        $this->context = ExecutionContext::explicit(1, ExecutionEnvironment::SANDBOX, 1);
        putenv('PROVIDER_CASHRAMP_ENABLED=true');
    }

    protected function tearDown(): void
    {
        ProviderRegistry::resetAdapters();
        ProviderEligibilityService::clearTestCapabilityOverrides();
        putenv('PROVIDER_CASHRAMP_ENABLED');
        parent::tearDown();
    }

    public function testCashrampWithoutCredentialsIsNotEligible(): void
    {
        $result = ProviderEligibilityService::evaluate('cashramp', $this->intent(), $this->context);

        self::assertFalse($result->eligible);
        self::assertTrue(
            in_array('credentials not configured', $result->reasons, true)
            || in_array('transfers feature disabled', $result->reasons, true)
        );
    }

    public function testUnknownProviderIsNotEligible(): void
    {
        $result = ProviderEligibilityService::evaluate('does_not_exist', $this->intent(), $this->context);

        self::assertFalse($result->eligible);
        self::assertContains('provider unknown', $result->reasons);
    }

    public function testConfiguredFutureProviderCanBecomeEligible(): void
    {
        ProviderRegistry::registerAdapter('moneygram', new FakeFutureProviderAdapter('moneygram'));
        ProviderEligibilityService::setTestCapabilityOverride('moneygram', [
            'payout' => \Nexus\Providers\ProviderCapabilityMatrix::IMPLEMENTED,
        ]);

        putenv('PROVIDER_MONEYGRAM_ENABLED=true');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_ID=test-client');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_SECRET=test-secret');

        $intent = [
            'amount'          => 100.0,
            'sourceCurrency'  => 'EUR',
            'sourceCountry'   => 'FR',
            'destCountry'     => 'US',
            'destCurrency'    => 'USD',
            'receivingMethod' => 'bank',
            'operation'       => 'payout',
        ];

        $result = ProviderEligibilityService::evaluate('moneygram', $intent, $this->context);

        self::assertTrue($result->eligible);

        putenv('PROVIDER_MONEYGRAM_ENABLED');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_ID');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_SECRET');
    }

    /** @return array<string, mixed> */
    private function intent(): array
    {
        return [
            'amount'          => 500.0,
            'sourceCurrency'  => 'EUR',
            'sourceCountry'   => 'FR',
            'destCountry'     => 'CM',
            'destCurrency'    => 'XAF',
            'receivingMethod' => 'mobile_money',
            'operation'       => 'payout',
        ];
    }
}
