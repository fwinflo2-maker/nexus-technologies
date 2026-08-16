<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Execution\AccountContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Execution\ProductionAuthorizationPolicy as Policy;
use PHPUnit\Framework\TestCase;

/**
 * AUTORISATION D'UTILISER LA PRODUCTION.
 *
 * Règle centrale prouvée ici :
 *
 *     Disposer d'une credential de production n'est PAS une autorisation.
 *
 * L'autorisation est une propriété du compte ; la credential est un moyen
 * technique. Les deux conditions sont indépendantes et doivent être réunies.
 * La policy est fail-closed : toute inconnue se résout en refus.
 */
final class ProductionAuthorizationPolicyTest extends TestCase
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
    }

    private function account(int $id = 42, string $type = 'personal'): AccountContext
    {
        return AccountContext::of(accountId: $id, accountType: $type);
    }

    // ══ 1. Deny by default ═════════════════════════════════════════════════

    public function test_production_is_denied_by_default(): void
    {
        $this->assertFalse(
            Policy::isProductionAllowed($this->account()),
            'Sans autorisation explicite, la production doit être refusée.'
        );
    }

    // ══ 2. La sandbox reste ouverte ════════════════════════════════════════

    public function test_sandbox_is_allowed_by_default(): void
    {
        $this->assertTrue(
            Policy::isAllowed($this->account(), ExecutionEnvironment::SANDBOX),
            'La sandbox ne déplace pas d\'argent réel : elle reste ouverte.'
        );
    }

    // ══ 3. Un compte business n'a AUCUN privilège implicite ════════════════

    public function test_business_account_type_grants_no_implicit_production(): void
    {
        $this->assertFalse(
            Policy::isProductionAllowed($this->account(7, 'business')),
            'Le type de compte n\'est pas une autorisation : règle métier non inventée.'
        );
    }

    // ══ 4. Autorisation explicite de la plateforme ═════════════════════════

    public function test_platform_wide_flag_grants_production(): void
    {
        putenv(Policy::ENV_ALLOW_ALL . '=true');

        $this->assertTrue(Policy::isProductionAllowed($this->account()));
    }

    // ══ 5. Liste explicite de comptes ══════════════════════════════════════

    public function test_allow_list_grants_only_listed_accounts(): void
    {
        putenv(Policy::ENV_ALLOW_LIST . '=10,42,77');

        $this->assertTrue(
            Policy::isProductionAllowed($this->account(42)),
            'Le compte listé est autorisé.'
        );
        $this->assertFalse(
            Policy::isProductionAllowed($this->account(43)),
            'Un compte absent de la liste reste refusé.'
        );
    }

    // ══ 6. Une liste malformée n'élargit jamais l'autorisation ═════════════

    public function test_malformed_allow_list_never_widens_authorization(): void
    {
        // Entrées non numériques, vides, jokers : toutes ignorées.
        putenv(Policy::ENV_ALLOW_LIST . '=,,*,all,abc, ');

        $this->assertFalse(
            Policy::isProductionAllowed($this->account()),
            'Une entrée malformée ne doit jamais valoir « tout le monde ».'
        );
    }

    // ══ 7. Déploiement de production ═══════════════════════════════════════

    public function test_production_deployment_allows_production_and_denies_sandbox(): void
    {
        putenv('APP_ENV=production');

        $this->assertTrue(
            Policy::isProductionAllowed($this->account()),
            'Sur un déploiement de production, la production est le mode nominal.'
        );
        $this->assertFalse(
            Policy::isSandboxAllowed($this->account()),
            'Un client ne choisit pas un mode dégradé sur une plateforme réelle.'
        );
    }

    // ══ 8. Indépendance credential / autorisation ══════════════════════════

    public function test_production_credential_does_not_grant_authorization(): void
    {
        // Une credential de production parfaitement valide est présente…
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_authentique');
        putenv('PROVIDER_STRIPE_PRODUCTION_PUBLISHABLE_KEY=pk_live_authentique');

        // … et pourtant le compte n'a toujours pas le droit d'exécuter en réel.
        $this->assertFalse(
            Policy::isProductionAllowed($this->account()),
            'La possession d\'une clé live ne confère aucun droit.'
        );
    }

    // ══ Le motif de refus ne divulgue rien ═════════════════════════════════

    public function test_denial_reason_leaks_no_configuration(): void
    {
        putenv(Policy::ENV_ALLOW_LIST . '=10,42');

        $reason = Policy::denialReason($this->account(999), ExecutionEnvironment::PRODUCTION);

        $this->assertStringNotContainsString('10', $reason);
        $this->assertStringNotContainsString(Policy::ENV_ALLOW_LIST, $reason);
        $this->assertNotSame('', trim($reason));
    }
}
