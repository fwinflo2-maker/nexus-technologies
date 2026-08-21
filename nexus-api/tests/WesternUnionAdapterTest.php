<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\WesternUnionAdapter;
use PHPUnit\Framework\TestCase;

final class WesternUnionAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PROVIDER_WESTERN_UNION_ENABLED=true');
        putenv('PROVIDER_WESTERN_UNION_ENV=sandbox');
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_ID=wu_client_1');
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_CERT_PATH=/tmp/wu-client.crt');
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_KEY_PATH=/tmp/wu-client.key');
    }

    protected function tearDown(): void
    {
        putenv('PROVIDER_WESTERN_UNION_ENABLED');
        putenv('PROVIDER_WESTERN_UNION_ENV');
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_ID');
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_CERT_PATH');
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_KEY_PATH');
    }

    public function test_ping_success_returns_connection_success(): void
    {
        $captured = [];
        $adapter = new WesternUnionAdapter(
            static function (
                string $method,
                string $url,
                array $headers,
                string $body,
                ?string $cert,
                ?string $key
            ) use (&$captured): array {
                $captured = compact('method', 'url', 'cert', 'key');
                return ['status' => 200, 'body' => 'pong'];
            }
        );

        $result = $adapter->testConnection('sandbox');

        self::assertSame('CONNECTION_SUCCESS', $result['status']);
        self::assertSame('GET', $captured['method']);
        self::assertStringEndsWith('/Ping', $captured['url']);
        self::assertStringContainsString('api-sandbox.westernunion.com', $captured['url']);
        self::assertSame('/tmp/wu-client.crt', $captured['cert']);
        self::assertSame('/tmp/wu-client.key', $captured['key']);
    }

    public function test_missing_credentials_is_not_configured(): void
    {
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_CERT_PATH');
        putenv('PROVIDER_WESTERN_UNION_SANDBOX_CLIENT_KEY_PATH');
        $adapter = new WesternUnionAdapter(static fn (): array => ['status' => 200, 'body' => '']);
        $result = $adapter->testConnection('sandbox');
        self::assertSame('PROVIDER_NOT_CONFIGURED', $result['status']);
    }

    public function test_401_is_invalid_credentials(): void
    {
        $adapter = new WesternUnionAdapter(static fn (): array => [
            'status' => 401,
            'body' => '',
        ]);
        $result = $adapter->testConnection('sandbox');
        self::assertSame('INVALID_CREDENTIALS', $result['status']);
    }

    public function test_injected_credentials_used_without_files(): void
    {
        $adapter = new WesternUnionAdapter(static fn (): array => [
            'status' => 200,
            'body' => 'pong',
        ]);
        $result = $adapter->testConnection('sandbox', [
            'client_id' => 'inj',
            'client_cert_path' => '/virtual/cert.pem',
            'client_key_path' => '/virtual/key.pem',
        ]);
        self::assertSame('CONNECTION_SUCCESS', $result['status']);
    }
}
