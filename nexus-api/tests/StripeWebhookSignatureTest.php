<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\StripeAdapter;
use PHPUnit\Framework\TestCase;

/**
 * §7/§8 — Webhook Stripe : vérification RÉELLE de l'en-tête
 * `Stripe-Signature` (t=..., v1=...), HMAC-SHA256 du corps avec le
 * webhook_signing_secret, tolérance anti-replay — jamais une vérification
 * générique. Un webhook falsifié est TOUJOURS rejeté.
 */
final class StripeWebhookSignatureTest extends TestCase
{
    private const TEST_WEBHOOK_SECRET = 'whsec_test_secret_local_only';

    protected function setUp(): void
    {
        // Environnement sandbox, secret de test purement local.
        putenv('PROVIDERS_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_WEBHOOK_SECRET=' . self::TEST_WEBHOOK_SECRET);
    }

    protected function tearDown(): void
    {
        putenv('PROVIDERS_ENV');
        putenv('PROVIDER_STRIPE_SANDBOX_WEBHOOK_SECRET');
    }

    /** Construit l'en-tête Stripe-Signature officiel pour un payload. */
    private function sign(string $payload, int $timestamp, string $secret = self::TEST_WEBHOOK_SECRET): string
    {
        return sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', $timestamp . '.' . $payload, $secret)
        );
    }

    public function test_webhook_valide_accepte(): void
    {
        $payload   = '{"id":"evt_123","type":"payment_intent.succeeded"}';
        $signature = $this->sign($payload, time());

        self::assertTrue(
            (new StripeAdapter())->verifyWebhook($payload, $signature),
            'Un webhook Stripe correctement signé doit être accepté.'
        );
    }

    public function test_webhook_payload_modifie_refuse(): void
    {
        $payload   = '{"id":"evt_123","type":"payment_intent.succeeded"}';
        $signature = $this->sign($payload, time());

        $tampered = '{"id":"evt_123","type":"payment_intent.canceled"}';
        self::assertFalse(
            (new StripeAdapter())->verifyWebhook($tampered, $signature),
            'Modifier le payload casse la signature → refus.'
        );
    }

    public function test_webhook_signature_falsifiee_refuse(): void
    {
        $payload = '{"id":"evt_123"}';
        $signature = 't=' . time() . ',v1=' . str_repeat('0', 64);

        self::assertFalse((new StripeAdapter())->verifyWebhook($payload, $signature));
    }

    public function test_webhook_sans_signature_refuse(): void
    {
        self::assertFalse((new StripeAdapter())->verifyWebhook('{}', ''));
        self::assertFalse((new StripeAdapter())->verifyWebhook('{}', 'v1=abcdef'));
    }

    public function test_webhook_timestamp_trop_ancien_refuse(): void
    {
        $payload   = '{"id":"evt_123"}';
        $signature = $this->sign($payload, time() - 3600); // 1 h dans le passé

        self::assertFalse(
            (new StripeAdapter())->verifyWebhook($payload, $signature),
            'Rejeu ancien : la tolérance anti-replay doit le rejeter.'
        );
    }

    public function test_webhook_mauvais_secret_refuse(): void
    {
        $payload   = '{"id":"evt_123"}';
        $signature = $this->sign($payload, time(), 'whsec_MAUVAIS_SECRET');

        self::assertFalse((new StripeAdapter())->verifyWebhook($payload, $signature));
    }
}
