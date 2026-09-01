<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\CashrampAdapter;
use Nexus\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class CashrampAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::resetAdapters();
        parent::tearDown();
    }

    public function testTestConnectionWithoutCredentialsIsHonest(): void
    {
        $adapter = new CashrampAdapter();
        $result  = $adapter->testConnection('sandbox');

        self::assertSame('PROVIDER_NOT_CONFIGURED', $result['status']);
    }

    public function testVerifyWebhookUsesConstantTimeComparison(): void
    {
        putenv('PROVIDER_CASHRAMP_ENABLED=true');
        putenv('PROVIDER_CASHRAMP_SANDBOX_SECRET_KEY=test-secret');
        putenv('PROVIDER_CASHRAMP_SANDBOX_WEBHOOK_TOKEN=wh_test');

        $adapter = new CashrampAdapter();
        self::assertTrue($adapter->verifyWebhook('{}', 'wh_test'));
        self::assertFalse($adapter->verifyWebhook('{}', 'wrong'));

        putenv('PROVIDER_CASHRAMP_ENABLED');
        putenv('PROVIDER_CASHRAMP_SANDBOX_SECRET_KEY');
        putenv('PROVIDER_CASHRAMP_SANDBOX_WEBHOOK_TOKEN');
    }

    public function testSuccessfulConnectionUsesGraphqlAccountQuery(): void
    {
        $transport = static function (): array {
            return [
                'status' => 200,
                'body'   => json_encode([
                    'data' => ['account' => ['id' => 'acct_ok', 'accountBalance' => '0.00']],
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $adapter = new CashrampAdapter($transport);
        $result  = $adapter->testConnection('sandbox', ['secret_key' => 'CSHRMP-SECK_ok']);

        self::assertSame('CONNECTION_SUCCESS', $result['status']);
    }
}
