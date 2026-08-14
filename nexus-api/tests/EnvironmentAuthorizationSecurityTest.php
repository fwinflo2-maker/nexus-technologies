<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ProductionAuthorizationPolicy as Policy;
use Nexus\Execution\ProviderResolver;
use PHPUnit\Framework\TestCase;

/**
 * SÉCURITÉ DU COUPLE (ENVIRONNEMENT, AUTORISATION).
 *
 * Ce que ces tests interdisent :
 *
 *   1. contourner la policy en changeant de canal (header / body / query) ;
 *   2. transformer une credential en autorisation ;
 *   3. transformer une autorisation en credential ;
 *   4. convertir un refus en repli silencieux vers l'autre environnement.
 */
final class EnvironmentAuthorizationSecurityTest extends TestCase
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
        putenv(Policy::ENV_ALLOW_ALL);
        putenv(Policy::ENV_ALLOW_LIST);
        unset($_SERVER['HTTP_X_NEXUS_ENVIRONMENT'], $_GET['environment']);
    }

    /** @return array<string,mixed> */
    private function user(int $id = 42): array
    {
        return ['id' => $id, 'account_type' => 'personal'];
    }

    // ══ 1. Le canal ne change pas la décision ══════════════════════════════

    /**
     * Header, body et query désignent la même demande : « production ».
     * Non autorisée, elle doit être refusée de façon identique, sinon le
     * canal le plus permissif devient la porte d'entrée.
     */
    public function test_header_body_and_query_are_subject_to_the_same_policy(): void
    {
        $channels = [
            'header' => function (): Request {
                $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'production';
                return new Request([]);
            },
            'body'   => fn (): Request => new Request(['environment' => 'production']),
            'query'  => function (): Request {
                $_GET['environment'] = 'production';
                return new Request([]);
            },
        ];

        foreach ($channels as $name => $build) {
            $this->clearEnv();
            $request = $build();

            try {
                ExecutionContext::fromRequest($request, $this->user());
                $this->fail(sprintf('Le canal « %s » a contourné la policy.', $name));
            } catch (HttpException $e) {
                $this->assertSame(403, $e->statusCode(), sprintf('Canal %s', $name));
                $this->assertSame('ENVIRONMENT_NOT_ALLOWED', $e->errorCode(), sprintf('Canal %s', $name));
            }
        }
    }

    // ══ 2. Credential présente + compte non autorisé → 403 ═════════════════

    /**
     * Le scénario dangereux : la plateforme DISPOSE des clés de production.
     * Si la simple présence de la clé suffisait, tout compte exécuterait en
     * argent réel. L'autorisation doit trancher avant toute lecture de clé.
     */
    public function test_available_production_credential_does_not_bypass_authorization(): void
    {
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_present');
        putenv('PROVIDER_STRIPE_PRODUCTION_PUBLISHABLE_KEY=pk_live_present');
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'production';

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        try {
            ExecutionContext::fromRequest(new Request([]), $this->user());
            $this->fail('Une credential disponible a servi d\'autorisation.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame('ENVIRONMENT_NOT_ALLOWED', $e->errorCode());
            throw $e;
        }
    }

    // ══ 3. Autorisé mais credential absente → 409, jamais un repli ═════════

    /**
     * Réciproque : le compte est autorisé en production, mais seule une
     * credential SANDBOX existe. Le système doit échouer explicitement et
     * surtout PAS exécuter en sandbox « pour que ça passe ».
     */
    public function test_authorized_production_without_production_credential_fails_closed(): void
    {
        putenv(Policy::ENV_ALLOW_ALL . '=true');
        // Seule la sandbox est configurée.
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_only');
        putenv('PROVIDER_STRIPE_SANDBOX_PUBLISHABLE_KEY=pk_test_only');
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'production';

        $context = ExecutionContext::fromRequest(new Request([]), $this->user());
        $this->assertSame('production', $context->environmentValue(), 'L\'autorisation est accordée.');

        try {
            ProviderResolver::resolve('stripe', $context);
            $this->fail('Une credential sandbox a été utilisée pour une exécution production.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT', $e->errorCode());
        }
    }

    // ══ 4. Un refus sandbox ne devient jamais un accord production ═════════

    public function test_denied_sandbox_never_escalates_to_production(): void
    {
        // Déploiement de production : la sandbox y est refusée…
        putenv('APP_ENV=production');
        putenv('PROVIDERS_ENV=production');
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'sandbox';

        try {
            ExecutionContext::fromRequest(new Request([]), $this->user());
            $this->fail('Une demande sandbox refusée a été silencieusement acceptée.');
        } catch (HttpException $e) {
            // … et le refus reste un refus : pas de bascule vers production.
            $this->assertSame(403, $e->statusCode());
        }
    }

    // ══ 5. L'environnement reste immuable après résolution ═════════════════

    public function test_environment_is_immutable_once_resolved(): void
    {
        $context = ExecutionContext::fromRequest(new Request([]), $this->user());
        $this->assertSame('sandbox', $context->environmentValue());

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line écriture volontairement illégale */
        $context->environment = \Nexus\Execution\ExecutionEnvironment::PRODUCTION;
    }

    // ══ 6. La trace d'audit reconstitue la décision, sans secret ═══════════

    public function test_audit_payload_reconstructs_decision_without_secrets(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_super_secret_value');

        $context = ExecutionContext::fromRequest(new Request([]), $this->user())
            ->forOperation('stripe', 'createPayment');

        $audit = $context->toArray();

        // Reconstitution complète de la décision.
        $this->assertSame(42, $audit['account_id']);
        $this->assertSame('stripe', $audit['provider']);
        $this->assertSame('createPayment', $audit['operation']);
        $this->assertSame('sandbox', $audit['environment']);
        $this->assertArrayHasKey('environment_source', $audit);
        $this->assertNotSame('', (string) $audit['request_id']);

        // Aucun secret.
        $this->assertStringNotContainsString(
            'sk_test_super_secret_value',
            json_encode($audit, JSON_THROW_ON_ERROR)
        );
    }

    // ══ 7. Le contexte se précise sans se renégocier ═══════════════════════

    public function test_for_operation_carries_environment_without_recomputing(): void
    {
        putenv(Policy::ENV_ALLOW_ALL . '=true');
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'production';

        $context = ExecutionContext::fromRequest(new Request([]), $this->user());

        // La configuration change APRÈS la résolution : le contexte déjà
        // arbitré ne doit pas en tenir compte.
        putenv(Policy::ENV_ALLOW_ALL);
        putenv('PROVIDERS_ENV=sandbox');

        $derived = $context->forOperation('stripe', 'getBalance');

        $this->assertSame('production', $derived->environmentValue());
        $this->assertSame($context->requestId, $derived->requestId, 'La corrélation est conservée.');
    }
}
