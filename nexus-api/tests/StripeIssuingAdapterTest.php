<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderCapabilityMatrix;
use Nexus\Providers\ProviderRegistry;
use Nexus\Providers\StripeIssuingAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StripeIssuingAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::resetAdapters();
        parent::tearDown();
    }

    public function test_registry_resolves_dedicated_adapter(): void
    {
        $adapter = ProviderRegistry::adapter('stripe_issuing');
        self::assertInstanceOf(StripeIssuingAdapter::class, $adapter);
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('stripe_issuing')
        );
    }

    public function test_test_connection_without_credentials_does_not_call_api(): void
    {
        $called = false;
        $adapter = new StripeIssuingAdapter(static function () use (&$called): array {
            $called = true;
            return ['status' => 200, 'body' => '{}'];
        });

        $result = $adapter->testConnection('sandbox', []);

        self::assertFalse($called);
        self::assertSame('PROVIDER_NOT_CONFIGURED', $result['status']);
    }

    public function test_test_connection_success_on_200(): void
    {
        $adapter = new StripeIssuingAdapter(static function (): array {
            return ['status' => 200, 'body' => '{"object":"list","data":[]}'];
        });

        $result = $adapter->testConnection('sandbox', ['secret_key' => 'sk_test_x']);

        self::assertSame('CONNECTION_SUCCESS', $result['status']);
    }

    public function test_issue_virtual_card_happy_path(): void
    {
        $calls = [];
        $adapter = new StripeIssuingAdapter(static function (
            string $method,
            string $url,
            array $headers,
            string $body
        ) use (&$calls): array {
            $calls[] = [$method, $url, $body];
            if (str_contains($url, '/issuing/cardholders') && $method === 'POST') {
                return ['status' => 200, 'body' => '{"id":"ich_test_1"}'];
            }
            if (str_contains($url, '/issuing/cards') && $method === 'POST') {
                return [
                    'status' => 200,
                    'body'   => '{"id":"ic_test_1","last4":"4242","brand":"visa","status":"active","currency":"eur"}',
                ];
            }
            return ['status' => 404, 'body' => '{}'];
        });

        $issued = $adapter->issueVirtualCard('sandbox', [
            'full_name'            => 'Alice Martin',
            'email'                => 'alice@example.com',
            'phone'                => '+33600000000',
            'address'              => '10 rue de Test',
            'city'                 => 'Paris',
            'postal_code'          => '75001',
            'country_of_residence' => 'FR',
        ], [
            'currency'    => 'EUR',
            'label'       => 'Voyages',
            'spend_limit' => 100.0,
        ], ['secret_key' => 'sk_test_x']);

        self::assertSame('ic_test_1', $issued['issuer_ref']);
        self::assertSame('ich_test_1', $issued['cardholder_id']);
        self::assertSame('4242', $issued['last4']);
        self::assertSame('visa', $issued['brand']);
        self::assertSame('active', $issued['status']);
        self::assertCount(2, $calls);
    }

    public function test_issue_rejects_unsupported_currency(): void
    {
        $adapter = new StripeIssuingAdapter(static function (): array {
            return ['status' => 200, 'body' => '{}'];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CURRENCY_NOT_SUPPORTED_BY_ISSUER');

        $adapter->issueVirtualCard('sandbox', [
            'full_name'            => 'Alice Martin',
            'address'              => '10 rue',
            'city'                 => 'Paris',
            'postal_code'          => '75001',
            'country_of_residence' => 'FR',
        ], ['currency' => 'XAF'], ['secret_key' => 'sk_test_x']);
    }

    public function test_issue_requires_address(): void
    {
        $adapter = new StripeIssuingAdapter(static function (): array {
            return ['status' => 200, 'body' => '{}'];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CARDHOLDER_ADDRESS_REQUIRED');

        $adapter->issueVirtualCard('sandbox', [
            'full_name'            => 'Alice Martin',
            'country_of_residence' => 'FR',
        ], ['currency' => 'EUR'], ['secret_key' => 'sk_test_x']);
    }
}
