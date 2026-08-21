<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\MoneyGramAdapter;
use PHPUnit\Framework\TestCase;

final class MoneyGramAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PROVIDER_MONEYGRAM_ENABLED=true');
        putenv('PROVIDER_MONEYGRAM_ENV=sandbox');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_ID=mg_client_test');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_SECRET=mg_secret_test');
    }

    protected function tearDown(): void
    {
        putenv('PROVIDER_MONEYGRAM_ENABLED');
        putenv('PROVIDER_MONEYGRAM_ENV');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_ID');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_SECRET');
    }

    public function test_oauth_success_returns_connection_success(): void
    {
        $captured = [];
        $adapter = new MoneyGramAdapter(
            static function (string $method, string $url, array $headers, string $body) use (&$captured): array {
                $captured = compact('method', 'url', 'headers', 'body');
                return [
                    'status' => 200,
                    'body' => '{"access_token":"tok_abc","expires_in":"3599","token_type":"BearerToken"}',
                ];
            }
        );

        $result = $adapter->testConnection('sandbox');

        self::assertSame('CONNECTION_SUCCESS', $result['status']);
        self::assertSame('GET', $captured['method']);
        self::assertStringContainsString('/oauth/accesstoken?grant_type=client_credentials', $captured['url']);
        self::assertStringContainsString('sandboxapi.moneygram.com', $captured['url']);
        $auth = null;
        foreach ($captured['headers'] as $h) {
            if (str_starts_with($h, 'Authorization: Basic ')) {
                $auth = substr($h, strlen('Authorization: Basic '));
            }
        }
        self::assertNotNull($auth);
        self::assertSame('mg_client_test:mg_secret_test', base64_decode($auth, true));
    }

    public function test_missing_credentials_is_not_configured(): void
    {
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_ID');
        putenv('PROVIDER_MONEYGRAM_SANDBOX_CLIENT_SECRET');
        $adapter = new MoneyGramAdapter(static fn (): array => ['status' => 200, 'body' => '{}']);
        $result = $adapter->testConnection('sandbox');
        self::assertSame('PROVIDER_NOT_CONFIGURED', $result['status']);
    }

    public function test_401_is_invalid_credentials(): void
    {
        $adapter = new MoneyGramAdapter(static fn (): array => [
            'status' => 401,
            'body' => '{"error":"invalid_client"}',
        ]);
        $result = $adapter->testConnection('sandbox');
        self::assertSame('INVALID_CREDENTIALS', $result['status']);
    }

    public function test_200_without_token_is_configuration_error(): void
    {
        $adapter = new MoneyGramAdapter(static fn (): array => [
            'status' => 200,
            'body' => '{"status":"approved"}',
        ]);
        $result = $adapter->testConnection('sandbox');
        self::assertSame('CONFIGURATION_ERROR', $result['status']);
    }

    public function test_injected_credentials_override_env(): void
    {
        $captured = [];
        $adapter = new MoneyGramAdapter(
            static function (string $method, string $url, array $headers, string $body) use (&$captured): array {
                $captured = compact('headers');
                return [
                    'status' => 200,
                    'body' => '{"access_token":"x","expires_in":"3599"}',
                ];
            }
        );
        $result = $adapter->testConnection('sandbox', [
            'client_id' => 'injected_id',
            'client_secret' => 'injected_secret',
        ]);
        self::assertSame('CONNECTION_SUCCESS', $result['status']);
        $auth = null;
        foreach ($captured['headers'] as $h) {
            if (str_starts_with($h, 'Authorization: Basic ')) {
                $auth = substr($h, strlen('Authorization: Basic '));
            }
        }
        self::assertSame('injected_id:injected_secret', base64_decode((string) $auth, true));
    }

    public function test_declared_methods_cash_pickup_only(): void
    {
        $adapter = new MoneyGramAdapter();
        $ref = new \ReflectionMethod($adapter, 'declaredMethods');
        $ref->setAccessible(true);
        self::assertSame(['cash_pickup'], $ref->invoke($adapter));
    }
}
