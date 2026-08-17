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
        // Serveur TCP éphémère local : le test est autonome (pas de dépendance
        // à un port fixe du sandbox).
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "Impossible d'ouvrir un serveur de test : $errstr");
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        putenv('PROVIDERS_CONNECTIVITY_CHECK=true');
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        putenv("PROVIDER_STRIPE_SANDBOX_BASE_URL=http://$host:$port");

        try {
            $health = ProviderRegistry::adapter('stripe')->healthCheck();
            $this->assertSame(ProviderStatus::HEALTHY->value, $health['status']);
            $this->assertTrue($health['healthy']);
            $this->assertIsInt($health['latency_ms']);
        } finally {
            fclose($server);
        }
    }

    // ── 6bis. Transitions de statut (§6) ────────────────────────────────────

    public function test_status_transition_missing_to_configured(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        // 1. Credentials manquants.
        $this->assertSame(ProviderStatus::MISSING_CREDENTIALS, ProviderRegistry::status('stripe'));

        // 2. Ajout de la clé requise → configured.
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        $this->assertSame(ProviderStatus::CONFIGURED, ProviderRegistry::status('stripe'));
    }

    public function test_status_transition_configured_to_unavailable(): void
    {
        putenv('PROVIDERS_CONNECTIVITY_CHECK=true');
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_ENV=sandbox');
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_123');
        putenv('PROVIDER_STRIPE_SANDBOX_BASE_URL=https://127.0.0.1:1'); // port fermé

        // configured (validation) mais unavailable (santé).
        $this->assertSame(ProviderStatus::CONFIGURED, ProviderRegistry::status('stripe'));
        $health = ProviderRegistry::adapter('stripe')->healthCheck();
        $this->assertSame(ProviderStatus::UNAVAILABLE->value, $health['status']);
    }

    // ── 8. Routing Engine : seuls les providers configurés participent ──────

    public function test_routing_filters_unconfigured_providers(): void
    {
        // Seul pawaPay est configuré → le CapabilityEngine ne doit renvoyer
        // QUE pawaPay pour le corridor EUR→CG mobile_money (les autres
        // providers mobile_money non configurés sont exclus).
        putenv('PROVIDER_PAWAPAY_ENABLED=true');
        putenv('PROVIDER_PAWAPAY_ENV=sandbox');
        putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN=test_token_123');

        $intent = [
            'amount'          => 1000.0,
            'sourceCurrency'  => 'EUR',
            'destCountry'     => 'CG',
            'destCurrency'    => 'XAF',
            'receivingMethod' => 'mobile_money',
            'objective'       => 'optimized',
        ];

        $eligible = \Nexus\Services\CapabilityEngine::findEligible($intent);
        $slugs = array_column($eligible, 'slug');

        $this->assertNotEmpty($slugs);
        $this->assertSame(['pawapay'], $slugs);
    }

    // ── 5. AUCUN mode démo : catalogue ≠ opérationnel (§10) ─────────────────

    public function test_no_provider_is_routable_without_configuration(): void
    {
        // Le « mode démo » historique (tout le catalogue éligible tant qu'aucun
        // provider n'était configuré) est supprimé : sans credentials, AUCUN
        // provider du catalogue ne participe au routing, dans aucun environnement.
        putenv('APP_ENV=production');

        try {
            $this->assertFalse(ProviderRegistry::isAvailableForRouting('stripe'));
            $this->assertFalse(ProviderRegistry::isAvailableForRouting('pawapay'));
            // Le CapabilityEngine doit REFUSER (provider configuré requis).
            try {
                \Nexus\Services\CapabilityEngine::findEligible([
                    'amount'          => 100.0,
                    'sourceCurrency'  => 'EUR',
                    'destCountry'     => 'CG',
                    'destCurrency'    => 'XAF',
                    'receivingMethod' => 'mobile_money',
                    'objective'       => 'optimized',
                ]);
                $this->fail('Le CapabilityEngine aurait dû refuser sans provider configuré.');
            } catch (\Nexus\Core\HttpException $e) {
                $this->assertSame('NO_AVAILABLE_PROVIDER', $e->errorCode());
            }
        } finally {
            putenv('APP_ENV');
        }
    }

    // ── Mode strict & disponibilité pour le routing ─────────────────────────

    public function test_catalog_alone_never_makes_a_provider_routable(): void
    {
        $this->assertFalse(ProviderRegistry::isStrictMode());
        // Présence au catalogue ≠ opérationnel : sans configuration, un
        // provider n'est JAMAIS disponible pour le routing (§10).
        $this->assertFalse(ProviderRegistry::isAvailableForRouting('stripe'));
        $this->assertFalse(ProviderRegistry::isAvailableForRouting('pawapay'));
        $this->assertFalse(ProviderRegistry::isConfigured('stripe'));
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
