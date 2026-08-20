<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\ProviderCapabilityMatrix;
use Nexus\Providers\ProviderAuthProbe;
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
        self::assertSame(ProviderCapabilityMatrix::NOT_IMPLEMENTED, $caps['balance']);
        self::assertSame(ProviderCapabilityMatrix::NOT_IMPLEMENTED, $caps['quote']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['payout']);
        self::assertSame(ProviderCapabilityMatrix::CONFIG_REQUIRED, $caps['webhook']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['reconciliation']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['refund']);
    }

    public function test_stripe_declare_test_balance_et_webhook(): void
    {
        $caps = ProviderCapabilityMatrix::for('stripe');

        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['test_connection']);
        self::assertSame(ProviderCapabilityMatrix::NOT_IMPLEMENTED, $caps['balance'], 'getBalance() non exposé.');
        self::assertSame(ProviderCapabilityMatrix::CONFIG_REQUIRED, $caps['webhook'], 'Runtime = HMAC générique Nexus.');
        self::assertSame(ProviderCapabilityMatrix::NOT_IMPLEMENTED, $caps['payout'], 'Stripe Payouts non câblé.');
    }

    public function test_sumsub_reste_kyc_jamais_provider_de_paiement(): void
    {
        $caps = ProviderCapabilityMatrix::for('sumsub');

        self::assertSame(ProviderCapabilityMatrix::CONFIG_REQUIRED, $caps['test_connection']);
        self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['webhook']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['balance']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['payout']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['quote']);
        self::assertSame(ProviderCapabilityMatrix::NOT_SUPPORTED, $caps['refund']);
    }

    public function test_un_provider_config_driven_n_a_pas_de_payout_invente(): void
    {
        self::assertTrue(ProviderCatalog::exists('dlocal'));

        $caps = ProviderCapabilityMatrix::for('dlocal');
        self::assertSame(
            ProviderCapabilityMatrix::NOT_IMPLEMENTED,
            $caps['payout'],
            'dlocal.payout ne doit jamais être IMPLEMENTED sans adapter payout réel.'
        );
        // test_connection peut être IMPLEMENTED via ProviderAuthProbe (sonde HTTP).
        if (ProviderAuthProbe::supports('dlocal')) {
            self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, $caps['test_connection']);
            self::assertSame(ProviderCapabilityMatrix::IMPLEMENTED, ProviderCapabilityMatrix::integrationStatus('dlocal'));
        }
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
        // Stripe : test_connection réel.
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('stripe')
        );
        // Sumsub : webhook KYC réel (hors catalogue paiement).
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('sumsub')
        );
        // Thunes : ConfigDriven + AuthProbe (Basic /ping) → test_connection.
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::integrationStatus('thunes')
        );
        // Payout Thunes toujours NOT_IMPLEMENTED (pas d'adapter payout).
        self::assertSame(
            ProviderCapabilityMatrix::NOT_IMPLEMENTED,
            ProviderCapabilityMatrix::for('thunes')['payout']
        );
    }
}
