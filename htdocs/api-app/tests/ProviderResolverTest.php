<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Execution\ProviderResolver;
use Nexus\Providers\ProviderEligibilityService;
use Nexus\Providers\ProviderRegistry;
use Nexus\Tests\Support\FakeFutureProviderAdapter;
use PHPUnit\Framework\TestCase;

final class ProviderResolverTest extends TestCase
{
    private ExecutionContext $context;

    protected function setUp(): void
    {
        $this->context = ExecutionContext::explicit(1, ExecutionEnvironment::SANDBOX, 1);
    }

    protected function tearDown(): void
    {
        ProviderRegistry::resetAdapters();
        ProviderEligibilityService::clearTestCapabilityOverrides();
        parent::tearDown();
    }

    public function testResolveTransferRouteReturnsNoEligibleProviderByDefault(): void
    {
        $intent = $this->sampleIntent();

        $route = ProviderResolver::resolveTransferRoute($intent, $this->context);

        self::assertSame('NO_ELIGIBLE_PROVIDER', $route['status']);
        self::assertNull($route['selected']);
        self::assertSame([], $route['eligible_providers']);
    }

    public function testResolveProvidersExplainsCashrampIneligible(): void
    {
        $resolution = ProviderResolver::resolveProviders($this->sampleIntent(), $this->context);
        $cashramp   = $this->candidateFor($resolution['candidates'], 'cashramp');

        self::assertNotNull($cashramp);
        self::assertFalse($cashramp['eligible']);
        self::assertContains('routing disabled', $cashramp['reasons']);
    }

    public function testResolveProvidersExplainsPawapayIneligibleWithoutCredentials(): void
    {
        $resolution = ProviderResolver::resolveProviders($this->sampleIntent(), $this->context);
        $pawapay    = $this->candidateFor($resolution['candidates'], 'pawapay');

        self::assertNotNull($pawapay);
        self::assertFalse($pawapay['eligible']);
        self::assertContains('routing disabled', $pawapay['reasons']);
    }

    public function testFutureProviderCanBeSelectedWithFakeAdapter(): void
    {
        ProviderRegistry::registerAdapter('moneygram', new FakeFutureProviderAdapter('moneygram'));
        ProviderEligibilityService::setTestCapabilityOverride('moneygram', [
            'payout' => \Nexus\Providers\ProviderCapabilityMatrix::IMPLEMENTED,
        ]);

        putenv('PROVIDER_MONEYGRAM_ENABLED=true');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_ID=test-client');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_SECRET=test-secret');

        $intent = [
            'amount'           => 500.0,
            'sourceCurrency'   => 'EUR',
            'sourceCountry'    => 'FR',
            'destCountry'      => 'US',
            'destCurrency'     => 'USD',
            'receivingMethod'  => 'bank',
            'operation'        => 'payout',
        ];

        $route = ProviderResolver::resolveTransferRoute($intent, $this->context);

        self::assertSame('OK', $route['status']);
        self::assertNotNull($route['selected']);
        self::assertSame('moneygram', $route['selected']['provider']);

        putenv('PROVIDER_MONEYGRAM_ENABLED');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_ID');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_SECRET');
    }

    /** @return array<string, mixed> */
    private function sampleIntent(): array
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

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    private function candidateFor(array $candidates, string $slug): ?array
    {
        foreach ($candidates as $candidate) {
            if (($candidate['provider'] ?? '') === $slug) {
                return $candidate;
            }
        }

        return null;
    }
}
