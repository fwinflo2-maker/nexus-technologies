<?php

declare(strict_types=1);

namespace Nexus\Tests;

use InvalidArgumentException;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Execution\ProviderResolver;
use PHPUnit\Framework\TestCase;

/**
 * RÉSOLUTION FORMELLE DE L'ENVIRONNEMENT D'EXÉCUTION.
 *
 * Règle centrale prouvée ici :
 *
 *     L'environnement n'est JAMAIS déduit d'une credential disponible.
 *
 * La politique décide, puis la credential de l'environnement décidé est
 * exigée. L'inverse — « une clé de production existe, donc exécutons en
 * production » — est précisément l'accident que ces tests interdisent.
 */
final class ExecutionContextTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach (getenv() as $key => $_) {
            if (str_starts_with((string) $key, 'PROVIDER')) {
                putenv((string) $key);
            }
        }
        putenv('APP_ENV');
        putenv('PROVIDERS_ENV');
    }

    /** @param array<string,mixed> $body */
    private function request(array $body = [], array $headers = []): Request
    {
        foreach ($headers as $name => $value) {
            // Les en-têtes HTTP arrivent dans $_SERVER sous forme HTTP_*.
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request($body);
    }

    private function clearHeaders(): void
    {
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with((string) $k, 'HTTP_X_NEXUS')) {
                unset($_SERVER[$k]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function user(int $id = 1, string $type = 'business'): array
    {
        return ['id' => $id, 'account_type' => $type, 'email' => 'ctx@nexus.test'];
    }

    // ══ Le type interdit les environnements fantômes ═══════════════════════

    /** @dataProvider invalidEnvironments */
    public function test_environment_enum_rejects_aliases(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExecutionEnvironment::fromString($value);
    }

    /** @return array<string, list<string>> */
    public static function invalidEnvironments(): array
    {
        return [
            'staging' => ['staging'],
            'test'    => ['test'],
            'prod'    => ['prod'],
            'live'    => ['live'],
            'dev'     => ['dev'],
            'vide'    => [''],
        ];
    }

    public function test_environment_enum_accepts_canonical_values(): void
    {
        $this->assertSame(ExecutionEnvironment::SANDBOX, ExecutionEnvironment::fromString('sandbox'));
        $this->assertSame(ExecutionEnvironment::PRODUCTION, ExecutionEnvironment::fromString('PRODUCTION'));
        $this->assertSame(ExecutionEnvironment::PRODUCTION, ExecutionEnvironment::fromString(' production '));

        $this->assertTrue(ExecutionEnvironment::PRODUCTION->isRealMoney());
        $this->assertFalse(ExecutionEnvironment::SANDBOX->isRealMoney());
    }

    // ══ Défaut serveur ═════════════════════════════════════════════════════

    public function test_defaults_to_sandbox_without_any_request(): void
    {
        $this->clearHeaders();
        $ctx = ExecutionContext::fromRequest($this->request(), $this->user());

        $this->assertSame('sandbox', $ctx->environmentValue());
        $this->assertSame(ExecutionContext::SOURCE_SERVER_DEFAULT, $ctx->environmentSource);
        $this->assertFalse($ctx->isRealMoney());
    }

    public function test_server_default_can_be_production_by_configuration(): void
    {
        $this->clearHeaders();
        putenv('PROVIDERS_ENV=production');

        $ctx = ExecutionContext::fromRequest($this->request(), $this->user());

        $this->assertSame('production', $ctx->environmentValue());
        $this->assertSame(ExecutionContext::SOURCE_SERVER_DEFAULT, $ctx->environmentSource);
    }

    // ══ Demande explicite du client, arbitrée par le serveur ═══════════════

    public function test_client_may_request_environment_via_header(): void
    {
        $this->clearHeaders();
        $ctx = ExecutionContext::fromRequest(
            $this->request([], ['X-Nexus-Environment' => 'production']),
            $this->user()
        );

        $this->assertSame('production', $ctx->environmentValue());
        $this->assertSame(ExecutionContext::SOURCE_CLIENT, $ctx->environmentSource);
        $this->assertTrue($ctx->isRealMoney());
        $this->clearHeaders();
    }

    public function test_client_may_request_environment_via_body(): void
    {
        $this->clearHeaders();
        $ctx = ExecutionContext::fromRequest($this->request(['environment' => 'sandbox']), $this->user());

        $this->assertSame('sandbox', $ctx->environmentValue());
        $this->assertSame(ExecutionContext::SOURCE_CLIENT, $ctx->environmentSource);
    }

    /**
     * Une valeur inconnue est REFUSÉE, jamais remplacée par un défaut : sinon
     * le client croirait sa demande honorée alors qu'elle est ignorée.
     */
    public function test_invalid_requested_environment_is_rejected_not_defaulted(): void
    {
        $this->clearHeaders();

        try {
            ExecutionContext::fromRequest(
                $this->request([], ['X-Nexus-Environment' => 'staging']),
                $this->user()
            );
            $this->fail('Une demande d\'environnement invalide doit être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->statusCode());
            $this->assertSame('ENVIRONMENT_INVALID', $e->errorCode());
        } finally {
            $this->clearHeaders();
        }
    }

    // ══ Déploiement de production : la sandbox est interdite ═══════════════

    public function test_production_deployment_forces_production(): void
    {
        $this->clearHeaders();
        putenv('APP_ENV=production');

        $ctx = ExecutionContext::fromRequest($this->request(), $this->user());

        $this->assertSame('production', $ctx->environmentValue());
        $this->assertSame(ExecutionContext::SOURCE_SERVER_FORCED, $ctx->environmentSource);
        $this->assertTrue($ctx->isRealMoney());
    }

    public function test_production_deployment_refuses_sandbox_request(): void
    {
        $this->clearHeaders();
        putenv('APP_ENV=production');

        try {
            ExecutionContext::fromRequest(
                $this->request([], ['X-Nexus-Environment' => 'sandbox']),
                $this->user()
            );
            $this->fail('Un déploiement de production ne doit jamais accepter une exécution sandbox.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame('ENVIRONMENT_NOT_ALLOWED', $e->errorCode());
        } finally {
            $this->clearHeaders();
        }
    }

    // ══ RÈGLE CENTRALE : la credential ne décide pas de l'environnement ════

    /**
     * Une credential de PRODUCTION est disponible, et AUCUNE en sandbox.
     * Le contexte doit rester en sandbox : la disponibilité d'une clé ne
     * constitue jamais une décision d'exécution.
     */
    public function test_available_production_credential_never_switches_environment(): void
    {
        $this->clearHeaders();
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_disponible');

        $ctx = ExecutionContext::fromRequest($this->request(), $this->user());

        $this->assertSame(
            'sandbox',
            $ctx->environmentValue(),
            'La présence d\'une credential production a modifié l\'environnement : inversion interdite.'
        );
    }

    /**
     * Corollaire : dans ce contexte sandbox, le provider doit être déclaré
     * NON configuré — la clé de production ne le rend pas utilisable.
     */
    public function test_provider_not_usable_when_credential_belongs_to_other_environment(): void
    {
        $this->clearHeaders();
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_disponible');

        $ctx = ExecutionContext::explicit(1, ExecutionEnvironment::SANDBOX);

        $this->assertFalse(
            ProviderResolver::hasCredentialFor('stripe', $ctx),
            'Une credential production rend le provider utilisable en sandbox : fuite.'
        );

        try {
            ProviderResolver::resolve('stripe', $ctx);
            $this->fail('La résolution aurait dû échouer faute de credential sandbox.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT', $e->errorCode());
            // Le message explique l'absence de repli, sans divulguer de valeur.
            $this->assertStringNotContainsString('sk_live_disponible', $e->getMessage());
        }
    }

    /** Et l'inverse : une clé sandbox ne rend pas le provider utilisable en production. */
    public function test_sandbox_credential_does_not_enable_production(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_disponible');

        $sandboxCtx    = ExecutionContext::explicit(1, ExecutionEnvironment::SANDBOX);
        $productionCtx = ExecutionContext::explicit(1, ExecutionEnvironment::PRODUCTION);

        $this->assertTrue(ProviderResolver::hasCredentialFor('stripe', $sandboxCtx));
        $this->assertFalse(
            ProviderResolver::hasCredentialFor('stripe', $productionCtx),
            'Une credential sandbox rend le provider utilisable en production : fuite.'
        );
    }

    /** Le routing ne doit proposer que des providers utilisables ICI. */
    public function test_usable_slugs_filters_by_environment(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_ok');

        $sandbox    = ExecutionContext::explicit(1, ExecutionEnvironment::SANDBOX);
        $production = ExecutionContext::explicit(1, ExecutionEnvironment::PRODUCTION);

        $this->assertSame(['stripe'], ProviderResolver::usableSlugs(['stripe', 'wise'], $sandbox));
        $this->assertSame([], ProviderResolver::usableSlugs(['stripe', 'wise'], $production));
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $ctx = ExecutionContext::explicit(1, ExecutionEnvironment::SANDBOX);

        try {
            ProviderResolver::resolve('provider_inexistant', $ctx);
            $this->fail('Un provider inconnu doit être refusé.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->statusCode());
            $this->assertSame('PROVIDER_UNKNOWN', $e->errorCode());
        }
    }

    // ══ Traçabilité : la décision est auditable, sans secret ═══════════════

    public function test_context_is_auditable_without_secrets(): void
    {
        $this->clearHeaders();
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_confidentiel');

        $ctx   = ExecutionContext::fromRequest($this->request(), $this->user(7, 'business'), 42);
        $audit = $ctx->toArray();

        $this->assertSame(7, $audit['actor_user_id']);
        $this->assertSame(42, $audit['subject_user_id']);
        $this->assertSame('business', $audit['account_type']);
        $this->assertSame('sandbox', $audit['environment']);
        $this->assertSame(ExecutionContext::SOURCE_SERVER_DEFAULT, $audit['environment_source']);
        $this->assertFalse($audit['real_money']);

        $this->assertStringNotContainsString(
            'sk_test_confidentiel',
            json_encode($audit, JSON_THROW_ON_ERROR),
            'Le contexte d\'audit ne doit contenir aucun secret.'
        );
    }

    /** Le sujet par défaut est l'acteur : aucun espace tiers implicite. */
    public function test_subject_defaults_to_actor(): void
    {
        $this->clearHeaders();
        $ctx = ExecutionContext::fromRequest($this->request(), $this->user(9));

        $this->assertSame(9, $ctx->actorUserId);
        $this->assertSame(9, $ctx->subjectUserId);
    }
}
