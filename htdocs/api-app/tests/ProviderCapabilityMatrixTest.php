<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderCapabilityMatrix;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilityMatrixTest extends TestCase
{
    public function testCashrampCapabilitiesReflectImplementedClient(): void
    {
        $caps = ProviderCapabilityMatrix::for('cashramp');

        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['test_connection']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['quote']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['payout']);
    }

    public function testRouteStatusDefaultsToUnknownWithoutInventedData(): void
    {
        $status = ProviderCapabilityMatrix::routeStatus('cashramp', [
            'operation'       => 'payout',
            'sourceCurrency'  => 'EUR',
            'destCurrency'    => 'XAF',
            'sourceCountry'   => 'FR',
            'destCountry'     => 'CM',
            'receivingMethod' => 'mobile_money',
        ]);

        self::assertSame(ProviderCapabilityMatrix::STATE_UNKNOWN, $status);
    }

    public function testRouteKeyIsDeterministic(): void
    {
        $key = ProviderCapabilityMatrix::routeKey(
            'cashramp',
            'payout',
            'EUR',
            'XAF',
            'FR',
            'CM',
            'mobile_money',
        );

        self::assertSame('cashramp|payout|EUR|XAF|FR|CM|mobile_money', $key);
    }
}
