<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Services\FundingProposalService;
use PHPUnit\Framework\TestCase;

/**
 * Proposals de dépôt dérivées du ProviderCatalog — filtre pays strict.
 */
final class FundingProposalServiceTest extends TestCase
{
    public function test_sans_pays_aucune_proposal(): void
    {
        $data = FundingProposalService::listForUser(['id' => 1, 'country_of_residence' => null]);
        $this->assertNull($data['country']);
        $this->assertSame([], $data['proposals']);
        $this->assertNotNull($data['message']);
    }

    public function test_congo_a_du_mobile_money_pas_sepa_fr(): void
    {
        $data = FundingProposalService::listForUser(
            ['id' => 1, 'country_of_residence' => 'CG'],
            'EUR'
        );
        $this->assertSame('CG', $data['country']);
        $this->assertNotEmpty($data['proposals']);

        $methods = array_column($data['proposals'], 'method');
        $this->assertContains('mobile_money', $methods);

        $labels = implode(' ', array_column($data['proposals'], 'label'));
        $this->assertStringContainsString('MTN', $labels);

        foreach ($data['proposals'] as $p) {
            $this->assertStringNotContainsStringIgnoringCase('SEPA · Swan', (string) $p['label']);
        }
    }

    public function test_france_sepa_carte_jamais_mtn_ni_orange_momo(): void
    {
        $data = FundingProposalService::listForUser(['id' => 1, 'country_of_residence' => 'FR']);
        $this->assertSame('FR', $data['country']);
        $this->assertNotEmpty($data['proposals']);

        $slugs = array_column($data['proposals'], 'provider_slug');
        $methods = array_column($data['proposals'], 'method');

        // Providers EU/FR du catalogue NEXUS
        $this->assertTrue(
            in_array('swan', $slugs, true)
            || in_array('wise', $slugs, true)
            || in_array('stripe', $slugs, true),
            'La France doit proposer Swan, Wise ou Stripe'
        );

        $this->assertNotContains('mobile_money', $methods, 'Pas de MoMo en France');
        $this->assertNotContains('pawapay', $slugs);
        $this->assertNotContains('mtn_momo', $slugs);
        $this->assertNotContains('orange_money', $slugs);

        $blob = strtolower(implode(' ', array_column($data['proposals'], 'label')));
        $this->assertStringNotContainsString('mtn mobile', $blob);
        $this->assertStringNotContainsString('orange money', $blob);
    }

    public function test_resolve_inconnu_leve_404(): void
    {
        $this->expectException(\Nexus\Core\HttpException::class);
        FundingProposalService::resolveForUser(
            ['id' => 1, 'country_of_residence' => 'CG'],
            'does_not_exist'
        );
    }

    public function test_resolve_ok_pour_pays_utilisateur(): void
    {
        $list = FundingProposalService::listForUser(['id' => 1, 'country_of_residence' => 'CG']);
        $this->assertNotEmpty($list['proposals']);
        $id = (string) $list['proposals'][0]['id'];
        $p = FundingProposalService::resolveForUser(['id' => 1, 'country_of_residence' => 'CG'], $id);
        $this->assertSame($id, $p['id']);
    }

    public function test_modes_france_sans_mobile_money(): void
    {
        $modes = FundingProposalService::availablePaymentModes('FR');
        $this->assertSame('FR', $modes['country']);
        $this->assertFalse($modes['has_mobile_money']);
        $this->assertNotContains('mobile_money', $modes['methods']);
        $this->assertNotContains('mobile_money', $modes['account_kinds']['source']);
        $this->assertNotContains('mobile_money', $modes['account_kinds']['destination']);
        $this->assertContains('bank_iban', $modes['account_kinds']['source']);
        $this->assertFalse(FundingProposalService::isAccountKindAllowed('FR', 'mobile_money', 'source'));
    }

    public function test_modes_congo_avec_mobile_money(): void
    {
        $modes = FundingProposalService::availablePaymentModes('CG');
        $this->assertTrue($modes['has_mobile_money']);
        $this->assertContains('mobile_money', $modes['methods']);
        $this->assertContains('mobile_money', $modes['account_kinds']['source']);
        $this->assertTrue(FundingProposalService::isAccountKindAllowed('CG', 'mobile_money', 'source'));
        $this->assertSame('XAF', $modes['default_currency']);
    }

    public function test_devises_depot_france_sans_xaf_xof(): void
    {
        $currencies = FundingProposalService::depositCurrenciesForCountry('FR');
        $this->assertContains('EUR', $currencies);
        $this->assertContains('USD', $currencies);
        $this->assertNotContains('XAF', $currencies);
        $this->assertNotContains('XOF', $currencies);
        $this->assertFalse(FundingProposalService::isDepositCurrencyAllowed('FR', 'XAF'));
        $this->assertTrue(FundingProposalService::isDepositCurrencyAllowed('FR', 'EUR'));
    }

    public function test_devises_depot_congo_avec_xaf_sans_xof(): void
    {
        $currencies = FundingProposalService::depositCurrenciesForCountry('CG');
        $this->assertContains('XAF', $currencies);
        $this->assertNotContains('XOF', $currencies);
        $this->assertTrue(FundingProposalService::isDepositCurrencyAllowed('CG', 'XAF'));
    }
}
