<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderCapabilityMatrix;
use Nexus\Providers\ProviderOperationalAudit;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * Audit opérationnel provider-agnostic — aucun faux positif.
 */
final class ProviderOperationalAuditTest extends TestCase
{
    public function test_pawapay_sans_credentials_est_bloque(): void
    {
        // Garantit l'absence de token dans l'env de test.
        putenv('PROVIDER_PAWAPAY_SANDBOX_API_TOKEN');
        putenv('PROVIDER_PAWAPAY_ENABLED');

        $row = ProviderOperationalAudit::audit('pawapay', 'sandbox', true);

        self::assertSame('IMPLEMENTED', $row['implementation']);
        self::assertSame('PawaPayAdapter', $row['adapter']);
        self::assertSame('CREDENTIALS_NOT_CONFIGURED', $row['credentials']);
        self::assertSame('BLOCKED', $row['connection']);
        self::assertFalse($row['available']);
        self::assertSame('P1', $row['priority']);
    }

    public function test_moneygram_et_western_union_utilisent_adapters_dedies(): void
    {
        self::assertSame('MoneyGramAdapter', ProviderOperationalAudit::adapterClass('moneygram'));
        self::assertSame('WesternUnionAdapter', ProviderOperationalAudit::adapterClass('western_union'));
        self::assertTrue(ProviderCatalog::exists('moneygram'));
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::for('moneygram')['test_connection']
        );
    }

    public function test_config_driven_avec_sonde_reste_bloque_sans_credentials(): void
    {
        $row = ProviderOperationalAudit::audit('thunes', 'sandbox', true);

        self::assertSame('IMPLEMENTED', $row['implementation'], 'Sonde AuthProbe = test_connection IMPLEMENTED.');
        self::assertSame('ConfigDrivenProviderAdapter', $row['adapter']);
        self::assertSame('CREDENTIALS_NOT_CONFIGURED', $row['credentials']);
        self::assertSame('BLOCKED', $row['connection']);
        self::assertFalse($row['available'], 'Jamais AVAILABLE sans connection réelle.');
    }

    public function test_alias_onafriq_resolu_via_catalogue_onfriq(): void
    {
        self::assertTrue(ProviderCatalog::exists('onfriq'));
        self::assertFalse(ProviderCatalog::exists('onafriq'));
        self::assertSame('P1', ProviderOperationalAudit::priority('onfriq'));
    }

    public function test_tazapay_a_sonde_mais_2c2p_reste_sans_probe(): void
    {
        self::assertTrue(ProviderCatalog::exists('tazapay'));
        self::assertTrue(ProviderCatalog::exists('2c2p'));
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('tazapay'),
            'tazapay : AuthProbe Basic → test_connection'
        );
        self::assertSame(
            ProviderCapabilityMatrix::NOT_IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('2c2p'),
            '2c2p : pas de sonde HTTP documentée'
        );
    }

    public function test_reconciliation_pollable_derive_de_la_matrice(): void
    {
        $pollable = ProviderReconciliationService::pollableProviders();
        self::assertContains('pawapay', $pollable);
        foreach ($pollable as $slug) {
            self::assertSame(
                ProviderCapabilityMatrix::IMPLEMENTED,
                ProviderCapabilityMatrix::for($slug)['reconciliation']
            );
        }
    }

    public function test_audit_all_couvre_tout_le_catalogue(): void
    {
        $rows = ProviderOperationalAudit::auditAll('sandbox', false);
        self::assertCount(count(ProviderCatalog::all()), $rows);
        foreach ($rows as $row) {
            self::assertContains($row['credentials'], ['CONFIGURED', 'CREDENTIALS_NOT_CONFIGURED']);
            self::assertContains($row['connection'], ['CONNECTED', 'CONNECTION_FAILED', 'BLOCKED', 'NOT_TESTED']);
            // Sans --connect, jamais CONNECTED inventé.
            self::assertNotSame('CONNECTED', $row['connection']);
        }
    }
}
