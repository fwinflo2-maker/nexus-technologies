<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderCapabilityMatrix;
use Nexus\Services\ProviderCatalog;
use PHPUnit\Framework\TestCase;

/**
 * §1/§16/§21 — matrice honnête des capacités réelles.
 *
 * Un provider présent dans le catalogue n'est PAS un provider intégré : la
 * matrice ne déclare IMPLEMENTED que ce qui est réellement câblé, testé,
 * derrière un adaptateur. Tout le reste est NOT_IMPLEMENTED / NOT_SUPPORTED /
 * CONFIG_REQUIRED.
 */
final class ProviderCapabilityMatrixTest extends TestCase
{
    public function test_pawapay_declare_ses_capacites_reelles(): void
    {
        $caps = ProviderCapabilityMatrix::for('pawapay');

        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['test_connection']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['balance']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['quote']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['payout']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['webhook']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['reconciliation']);
        // Doc pawaPay : un payout accepté est terminal — pas d'annulation.
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['refund']);
    }

    public function test_stripe_declare_test_balance_et_webhook(): void
    {
        $caps = ProviderCapabilityMatrix::for('stripe');

        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['test_connection']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['balance']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['webhook']);
        self::assertSame(ProviderCapabilityMatrix::NOT_IMPLEMENTED, $caps['payout'], 'Stripe Payouts non câblé.');
    }

    public function test_sumsub_reste_kyc_jamais_provider_de_paiement(): void
    {
        $caps = ProviderCapabilityMatrix::for('sumsub');

        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['test_connection']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['webhook']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['balance']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['payout']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['quote']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['refund']);
    }

    public function test_un_provider_sans_adapter_n_est_jamais_implemented(): void
    {
        // dlocal est au catalogue mais n'a pas d'adaptateur réel.
        self::assertTrue(ProviderCatalog::exists('dlocal'));

        $caps = ProviderCapabilityMatrix::for('dlocal');
        foreach ($caps as $capability => $status) {
            self::assertNotSame(
                ProviderCapabilityMatrix::IMPLEMENTED,
                $status,
                "dlocal.{$capability} ne doit JAMAIS être IMPLEMENTED sans adapter réel."
            );
        }
        self::assertSame(ProviderCapabilityMatrix::NOT_IMPLEMENTED, ProviderCapabilityMatrix::integrationStatus('dlocal'));
    }

    public function test_le_defaut_honnete_s_applique_a_tous_les_providers_du_catalogue(): void
    {
        foreach (ProviderCatalog::all() as $slug => $_) {
            $caps = ProviderCapabilityMatrix::for($slug);
            foreach (ProviderCapabilityMatrix::CAPABILITIES as $capability) {
                self::assertArrayHasKey($capability, $caps, $slug . ' doit déclarer ' . $capability);
                self::assertContains($caps[$capability], [
                    ProviderCapabilityMatrix::IMPLEMENTED,
                    ProviderCapabilityMatrix::NOT_IMPLEMENTED,
                    ProviderCapabilityMatrix::NOT_SUPPORTED,
                    ProviderCapabilityMatrix::CONFIG_REQUIRED,
                ]);
            }
        }
    }

    public function test_integration_globale_reflete_les_capacites_reelles(): void
    {
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('pawapay')
        );
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('stripe')
        );
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('sumsub')
        );
        self::assertSame(
            ProviderCapabilityMatrix::NOT_IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('thunes')
        );
    }
}
