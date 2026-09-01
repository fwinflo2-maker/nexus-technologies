<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\Cashramp\CashrampClient;
use Nexus\Providers\Cashramp\CashrampException;
use PHPUnit\Framework\TestCase;

final class CashrampClientTest extends TestCase
{
    public function testGraphqlParsesSuccessfulAccountQuery(): void
    {
        $transport = static function (): array {
            return [
                'status' => 200,
                'body'   => json_encode([
                    'data' => ['account' => ['id' => 'acct_1', 'accountBalance' => '10.00']],
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $client = new CashrampClient('sandbox', 'CSHRMP-SECK_test', $transport);
        $data   = $client->testAccountConnection();

        self::assertSame('acct_1', $data['account']['id']);
        self::assertSame('10.00', $data['account']['accountBalance']);
    }

    public function testGraphqlRaisesOn401(): void
    {
        $transport = static fn (): array => ['status' => 401, 'body' => '{}'];

        $client = new CashrampClient('sandbox', 'bad', $transport);

        $this->expectException(CashrampException::class);
        $this->expectExceptionMessage('401');
        $client->testAccountConnection();
    }

    public function testBaseUrlUsesOfficialEndpoints(): void
    {
        self::assertStringContainsString('staging.api.useaccrue.com', CashrampClient::baseUrl('sandbox'));
        self::assertStringContainsString('api.useaccrue.com', CashrampClient::baseUrl('production'));
    }
}
