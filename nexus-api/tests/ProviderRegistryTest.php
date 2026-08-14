<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderRegistry;
use Nexus\Providers\ProviderStatus;
use Nexus\Providers\SecretRedactor;
use Nexus\Providers\WebhookVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'architecture Provider Credentials & Configuration.
 *
 * Couvre (§14) :
 *   1. provider configuré ;
 *   2. provider non configuré ;
 *   3. credentials manquants ;
 *   4. credentials invalides ;
 *   5. sandbox ;
 *   6. production ;
 *   7. provider disabled ;
 *   8. provider degraded / santé ;
 *   9. provider unavailable ;
 *   10. provider health check.
 *
 * + séparation stricte sandbox/production, absence de fuite de secrets,
 *   mode strict de routing, et vérification de signature de webhook.
 *
 * Ces tests sont PURS (aucune base de données, aucun secret réel).
 */
final class ProviderRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearProviderEnv();
    }

    protected function tearDown(): void
    {
        $this->clearProviderEnv();
    }

    /** Désactive toutes les variables PROVIDER_* (isolation stricte). */
    private function clearProviderEnv(): void
    {
        foreach (getenv() as $key => $value) {
            if (str_starts_with((string) $key, 'PROVIDER')) {
                putenv((string) $key);
            }
        }
    }

    // ── 1. Provider configuré ──────────────────────────────────────────────

    public function test_provider_configured(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_1234567890abcdef');

        $status = ProviderRegistry::status('stripe');
        $this->assertSame(ProviderStatus::CONFIGURED, $status);
        $this->assertTrue(ProviderRegistry::isConfigured('stripe'));
    }

    // ── 2. Provider non configuré (désactivé par défaut) ───────────────────

    public function test_provider_not_configured_by_default(): void
    {
        // Aucune variable : le provider est désactivé.
        $status = ProviderRegistry::status('stripe');
        $this->assertSame(ProviderStatus::DISABLED, $status);
        $this->assertFalse(ProviderRegistry::isConfigured('stripe'));
    }

    // ── 3. Credentials manquants ────────────────────────────────────────────

    public function test_missing_credentials(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        // secret_key requis non fourni.

        $validation = ProviderRegistry::adapter('stripe')->validateConfiguration();
        $this->assertSame(ProviderStatus::MISSING_CREDENTIALS, $validation['status']);
        $this->assertContains('secret_key', $validation['missing']);
    }

    // ── 4. Credentials invalides (slug inconnu / URL invalide) ──────────────

    public function test_invalid_configuration_unknown_slug(): void
    {
        $validation = ProviderConfig::validate('provider_inconnu', 'sandbox');
        $this->assertSame(ProviderStatus::INVALID_CONFIGURATION, $validation['status']);
    }

    public function test_invalid_configuration_bad_base_url(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        putenv('PROVIDER_STRIPE_SANDBOX_BASE_URL=not-a-valid-url');

        $validation = ProviderRegistry::adapter('stripe')->validateConfiguration();
        $this->assertSame(ProviderStatus::INVALID_CONFIGURATION, $validation['status']);
    }

    // ── 5 & 6. Séparation stricte sandbox / production ─────────────────────

    public function test_sandbox_credentials_are_isolated(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_sandbox_value');
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_production_value');

        // Environnement actif = sandbox → on lit la valeur sandbox.
        $this->assertSame('sk_test_sandbox_value', ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox'));
        // La valeur production reste isolée et ne « fuit » pas dans le sandbox.
        $this->assertSame('sk_live_production_value', ProviderConfig::credential('stripe', 'SECRET_KEY', 'production'));
    }

    public function test_production_environment_activation(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=production');
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_prod_only');

        $this->assertSame('production', ProviderConfig::activeEnvironment('stripe'));
        $this->assertSame('sk_live_prod_only', ProviderConfig::credential('stripe', 'SECRET_KEY', 'production'));
        // En production, la clé sandbox ne doit jamais être utilisée.
        $this->assertNull(ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox'));
    }

    // ── 7. Provider disabled (explicite) ────────────────────────────────────

    public function test_provider_disabled(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=false');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');

        $status = ProviderRegistry::status('stripe');
        $this->assertSame(ProviderStatus::DISABLED, $status);
        $this->assertFalse(ProviderStatus::DISABLED->routable());
    }

    // ── 8. Provider degraded / santé non testée ─────────────────────────────

    public function test_configured_is_not_healthy_without_connectivity(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        // Connectivité non activée : « configured » ≠ « healthy ».
        $health = ProviderRegistry::adapter('stripe')->healthCheck();
        $this->assertSame('configured', $health['status']);
        $this->assertNull($health['healthy']);
    }

    // ── 9. Provider unavailable (base URL injoignable) ──────────────────────

    public function test_provider_unavailable(): void
    {
        putenv('PROVIDERS_CONNECTIVITY_CHECK=true');
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        putenv('PROVIDER_STRIPE_SANDBOX_BASE_URL=https://127.0.0.1:1'); // port fermé

        $health = ProviderRegistry::adapter('stripe')->healthCheck();
        $this->assertSame(ProviderStatus::UNAVAILABLE->value, $health['status']);
        $this->assertFalse($health['healthy']);
    }

    // ── 10. Provider health check (reachable) ───────────────────────────────

    public function test_provider_healthy(): void
    {
        // On sonde un hôte réellement joignable (port ouvert en sandbox).
        putenv('PROVIDERS_CONNECTIVITY_CHECK=true');
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        putenv('PROVIDER_STRIPE_SANDBOX_BASE_URL=https://127.0.0.1:8080');

        $health = ProviderRegistry::adapter('stripe')->healthCheck();
        $this->assertSame(ProviderStatus::HEALTHY->value, $health['status']);
        $this->assertTrue($health['healthy']);
    }

    // ── Mode strict & disponibilité pour le routing ─────────────────────────

    public function test_strict_mode_off_when_no_provider_configured(): void
    {
        $this->assertFalse(ProviderRegistry::isStrictMode());
        // En mode démo, tous les providers du catalogue sont disponibles.
        $this->assertTrue(ProviderRegistry::isAvailableForRouting('stripe'));
        $this->assertTrue(ProviderRegistry::isAvailableForRouting('pawapay'));
    }

    public function test_strict_mode_on_and_routing_filtered(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');

        $this->assertTrue(ProviderRegistry::isStrictMode());
        // Stripe est configuré → disponible.
        $this->assertTrue(ProviderRegistry::isAvailableForRouting('stripe'));
        // pawaPay n'est PAS configuré → exclu du routing (ne casse pas le Core).
        $this->assertFalse(ProviderRegistry::isAvailableForRouting('pawapay'));
        $this->assertFalse(ProviderRegistry::isConfigured('pawapay'));
    }

    public function test_strict_mode_via_explicit_flag(): void
    {
        putenv('PROVIDERS_STRICT_MODE=true');
        $this->assertTrue(ProviderRegistry::isStrictMode());
    }

    // ── Aucune fuite de secrets ─────────────────────────────────────────────

    public function test_no_secret_leak_in_summary(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_SUPER_SECRET_9876');

        $summary = json_encode(ProviderRegistry::summary());
        $this->assertStringNotContainsString('SUPER_SECRET', $summary);
        $this->assertStringNotContainsString('sk_test_', $summary);
    }

    // ── Redaction ───────────────────────────────────────────────────────────

    public function test_secret_redactor(): void
    {
        $this->assertSame('sk********76', SecretRedactor::redact('sk_test_SUPER_SECRET_9876'));
        $this->assertSame('********', SecretRedactor::mask('anything'));
        $this->assertSame('********', SecretRedactor::redact('short'));
        $redacted = SecretRedactor::redactArray(['api_key' => 'sk_abc', 'name' => 'Stripe']);
        $this->assertSame('********', $redacted['api_key']);
        $this->assertStringNotContainsString('sk_abc', $redacted['api_key']);
        $this->assertSame('Stripe', $redacted['name']);
    }

    // ── Webhooks ────────────────────────────────────────────────────────────

    public function test_webhook_signature_valid_and_invalid(): void
    {
        $secret  = 'whsec_test_1234567890';
        $payload = '{"type":"payment.succeeded","id":"evt_123"}';
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue(WebhookVerifier::verify($payload, $signature, $secret));
        $this->assertTrue(WebhookVerifier::verify($payload, 'sha256=' . $signature, $secret));
        $this->assertFalse(WebhookVerifier::verify($payload, $signature, 'mauvais_secret'));
        $this->assertFalse(WebhookVerifier::verify($payload . 'tampered', $signature, $secret));
        $this->assertFalse(WebhookVerifier::verify($payload, '', $secret));
        $this->assertFalse(WebhookVerifier::verify($payload, $signature, ''));
    }

    public function test_webhook_verify_requires_secret(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        // Aucun webhook_secret configuré → l'adaptateur refuse de vérifier.
        $adapter = ProviderRegistry::adapter('stripe');
        $this->assertFalse($adapter->verifyWebhook('{}', 'deadbeef'));
    }

    // ── Opérations non implémentées : honnêtes, ne cassent pas le Core ──────

    public function test_unimplemented_operations_throw_but_do_not_break_core(): void
    {
        $adapter = ProviderRegistry::adapter('stripe');
        $this->expectException(\Nexus\Providers\ProviderOperationNotImplemented::class);
        $adapter->getQuote(['amount' => 100]);
    }
}
