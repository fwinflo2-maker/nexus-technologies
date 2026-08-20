<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\WebhookRegistry;
use Nexus\Services\ProviderCatalog;
use PHPUnit\Framework\TestCase;

/**
 * §7 — Webhook Registry : chaque provider déclare SON mécanisme
 * (path, signature_type, verification_key_type, timestamp_validation,
 * event_id_field, idempotency_field). La vérification n'est jamais une
 * logique générique unique présentée comme spécifique.
 */
final class WebhookRegistryTest extends TestCase
{
    public function test_pawapay_declare_sa_signature_rfc9421(): void
    {
        $wh = WebhookRegistry::for('pawapay');

        self::assertSame('/api/providers/webhook/pawapay', $wh['webhook_path']);
        self::assertSame('rfc9421', $wh['signature_type']);
        self::assertSame('public_key', $wh['verification_key_type']);
        self::assertTrue($wh['timestamp_validation']['enabled']);
        self::assertSame('CONFIG_REQUIRED', $wh['implementation']);
    }

    public function test_stripe_declare_stripe_signature_hmac(): void
    {
        $wh = WebhookRegistry::for('stripe');

        self::assertSame('/api/providers/webhook/stripe', $wh['webhook_path']);
        self::assertSame('hmac_sha256_stripe_signature', $wh['signature_type']);
        self::assertSame('webhook_secret', $wh['verification_key_type']);
        self::assertTrue($wh['timestamp_validation']['enabled']);
        self::assertSame('CONFIG_REQUIRED', $wh['implementation']);
    }

    public function test_sumsub_declare_le_digest_hmac_sans_timestamp(): void
    {
        $wh = WebhookRegistry::for('sumsub');

        self::assertSame('/api/kyc/webhook', $wh['webhook_path']);
        self::assertSame('hmac_sha256_digest', $wh['signature_type']);
        self::assertFalse($wh['timestamp_validation']['enabled'], 'Sumsub signe par digest seul.');
        self::assertSame('IMPLEMENTED', $wh['implementation']);
    }

    public function test_chaque_provider_du_catalogue_declare_son_registre(): void
    {
        $registry = WebhookRegistry::registry();

        foreach (ProviderCatalog::all() as $slug => $_) {
            self::assertArrayHasKey($slug, $registry, 'Chaque provider doit déclarer son webhook.');
            self::assertArrayHasKey('signature_type', $registry[$slug]);
            self::assertArrayHasKey('event_id_field', $registry[$slug]);
            self::assertArrayHasKey('idempotency_field', $registry[$slug]);
        }
    }

    public function test_un_provider_sans_webhook_specifique_reste_configure_requis(): void
    {
        $wh = WebhookRegistry::for('bvnk');
        self::assertSame('hmac_nexus', $wh['signature_type']);
        self::assertSame('CONFIG_REQUIRED', $wh['implementation'], 'Jamais IMPLEMENTED pour un pipeline générique.');
    }
}
