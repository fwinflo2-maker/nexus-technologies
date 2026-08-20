<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\HttpException;
use Nexus\Providers\PawaPayAdapter;
use PHPUnit\Framework\TestCase;

final class PawaPayAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PROVIDER_PAWAPAY_ENABLED=true');
        putenv('PROVIDER_PAWAPAY_ENV=sandbox');
        putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN=local_test_token');
    }

    protected function tearDown(): void
    {
        putenv('PROVIDER_PAWAPAY_ENABLED');
        putenv('PROVIDER_PAWAPAY_ENV');
        putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN');
    }

    public function test_payout_v2_payload_and_accepted_mapping(): void
    {
        $captured = [];
        $adapter = new PawaPayAdapter(
            static function (string $method, string $url, array $headers, string $body) use (&$captured): array {
                $captured = compact('method', 'url', 'headers', 'body');
                return [
                    'status' => 200,
                    'body' => '{"payoutId":"f4401bd2-1568-4140-bf2d-eb77d2b2b639","status":"ACCEPTED","created":"2026-08-20T12:00:00Z"}',
                ];
            }
        );

        $result = $adapter->createPayment([
            'operation_id' => 'f4401bd2-1568-4140-bf2d-eb77d2b2b639',
            'dest_amount' => '65000.00000000',
            'dest_currency' => 'XAF',
            'destination' => '+242 06 123 45 67',
            'operator' => 'MTN_MOMO_COG',
            'environment' => 'sandbox',
        ]);

        self::assertSame('accepted', $result['status']);
        self::assertSame('ACCEPTED', $result['provider_status']);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/v2/payouts', $captured['url']);
        $payload = json_decode($captured['body'], true);
        self::assertSame('65000', $payload['amount']);
        self::assertSame('XAF', $payload['currency']);
        self::assertSame('242061234567', $payload['recipient']['accountDetails']['phoneNumber']);
        self::assertSame('MTN_MOMO_COG', $payload['recipient']['accountDetails']['provider']);
        self::assertArrayNotHasKey('user_id', $payload);
    }

    public function test_duplicate_is_processing_not_success(): void
    {
        $adapter = new PawaPayAdapter(static fn (): array => [
            'status' => 200,
            'body' => '{"payoutId":"f4401bd2-1568-4140-bf2d-eb77d2b2b639","status":"DUPLICATE_IGNORED"}',
        ]);
        $result = $adapter->createPayment([
            'operation_id' => 'f4401bd2-1568-4140-bf2d-eb77d2b2b639',
            'dest_amount' => '100',
            'dest_currency' => 'XAF',
            'destination' => '242061234567',
            'operator' => 'MTN_MOMO_COG',
            'environment' => 'sandbox',
        ]);
        self::assertSame('duplicate_ignored', $result['status']);
        self::assertSame('processing', PawaPayAdapter::STATUS_MAP['DUPLICATE_IGNORED']);
    }

    public function test_rejected_payout_never_returns_success(): void
    {
        $adapter = new PawaPayAdapter(static fn (): array => [
            'status' => 200,
            'body' => '{"payoutId":"f4401bd2-1568-4140-bf2d-eb77d2b2b639","status":"REJECTED","failureReason":{"failureCode":"PROVIDER_TEMPORARILY_UNAVAILABLE"}}',
        ]);
        $this->expectException(HttpException::class);
        $adapter->createPayment([
            'operation_id' => 'f4401bd2-1568-4140-bf2d-eb77d2b2b639',
            'dest_amount' => '100',
            'dest_currency' => 'XAF',
            'destination' => '242061234567',
            'operator' => 'MTN_MOMO_COG',
            'environment' => 'sandbox',
        ]);
    }

    public function test_status_polling_maps_completed(): void
    {
        $adapter = new PawaPayAdapter(static fn (): array => [
            'status' => 200,
            'body' => '{"status":"FOUND","data":{"payoutId":"f4401bd2-1568-4140-bf2d-eb77d2b2b639","status":"COMPLETED","amount":"65000.00","currency":"XAF","providerTransactionId":"mmo-1"}}',
        ]);
        $result = $adapter->getPaymentStatus('f4401bd2-1568-4140-bf2d-eb77d2b2b639');
        self::assertSame('completed', $result['status']);
        self::assertSame('COMPLETED', $result['provider_status']);
        self::assertSame('65000.00', $result['amount']);
    }

    public function test_enqueued_initiation_is_processing_not_success(): void
    {
        $adapter = new PawaPayAdapter(static fn (): array => [
            'status' => 200,
            'body' => '{"payoutId":"f4401bd2-1568-4140-bf2d-eb77d2b2b639","status":"ENQUEUED"}',
        ]);
        $result = $adapter->createPayment([
            'operation_id' => 'f4401bd2-1568-4140-bf2d-eb77d2b2b639',
            'dest_amount' => '100',
            'dest_currency' => 'XAF',
            'destination' => '242061234567',
            'operator' => 'MTN_MOMO_COG',
            'environment' => 'sandbox',
        ]);
        self::assertSame('enqueued', $result['status']);
        self::assertSame('processing', PawaPayAdapter::STATUS_MAP['ENQUEUED']);
    }

    public function test_timeout_is_retryable_never_success(): void
    {
        $adapter = new PawaPayAdapter(static fn (): array => [
            'status' => 504,
            'body' => '',
        ]);
        try {
            $adapter->createPayment([
                'operation_id' => 'f4401bd2-1568-4140-bf2d-eb77d2b2b639',
                'dest_amount' => '100',
                'dest_currency' => 'XAF',
                'destination' => '242061234567',
                'operator' => 'MTN_MOMO_COG',
                'environment' => 'sandbox',
            ]);
            self::fail('HttpException attendue.');
        } catch (HttpException $e) {
            self::assertSame('PROVIDER_RETRYABLE', $e->errorCode());
            self::assertSame(503, $e->statusCode());
        }
    }

    public function test_missing_token_is_credentials_not_configured(): void
    {
        putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN');
        $adapter = new PawaPayAdapter(static fn (): array => [
            'status' => 200,
            'body' => '{"status":"ACCEPTED"}',
        ]);
        try {
            $adapter->createPayment([
                'operation_id' => 'f4401bd2-1568-4140-bf2d-eb77d2b2b639',
                'dest_amount' => '100',
                'dest_currency' => 'XAF',
                'destination' => '242061234567',
                'operator' => 'MTN_MOMO_COG',
                'environment' => 'sandbox',
            ]);
            self::fail('HttpException attendue.');
        } catch (HttpException $e) {
            self::assertSame('CREDENTIALS_NOT_CONFIGURED', $e->errorCode());
        }
    }
}
