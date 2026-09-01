<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderEligibilityService;
use Nexus\Services\ProviderHealthService;
use PHPUnit\Framework\TestCase;

final class ProviderHealthEligibilityTest extends TestCase
{
    private ExecutionContext $context;

    protected function setUp(): void
    {
        Database::resetConnection();
        $this->context = ExecutionContext::explicit(1, ExecutionEnvironment::SANDBOX, 1);
    }

    public function testHealthServiceReportsNotConfiguredWithoutCredentials(): void
    {
        try {
            $pdo = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('NOT EXECUTED — MYSQL REQUIRED: ' . $e->getMessage());
        }

        $health = ProviderHealthService::healthFor($pdo, 'pawapay', 'sandbox');

        self::assertFalse($health['configured']);
        self::assertSame('NOT_CONFIGURED', $health['connection']);

        $result = ProviderEligibilityService::evaluate('pawapay', [
            'amount'          => 100.0,
            'sourceCurrency'  => 'EUR',
            'destCountry'     => 'CM',
            'destCurrency'    => 'XAF',
            'receivingMethod' => 'mobile_money',
            'operation'       => 'payout',
        ], $this->context);

        self::assertFalse($result->eligible);
    }
}
