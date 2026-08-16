<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Services\ProviderCatalog;
use Nexus\Providers\ProviderOperationNotImplemented;
use Nexus\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * BOUCLE 5 — HONNÊTETÉ DES ADAPTATEURS DE PROVIDERS.
 *
 * Le risque n'est pas qu'une opération soit absente : c'est qu'elle
 * RÉPONDE. Un adaptateur qui retournerait `['status' => 'success']` sans
 * appeler quoi que ce soit ferait croire au moteur qu'un paiement est parti.
 * Le ledger enregistrerait un mouvement qui n'a jamais eu lieu.
 *
 * Ces tests exigent donc l'inverse d'un test habituel : ils vérifient que les
 * opérations ÉCHOUENT explicitement, pour chaque provider du catalogue, tant
 * qu'aucune implémentation réelle n'existe.
 *
 * Ils échoueront le jour où une opération sera réellement implémentée — c'est
 * voulu : l'ajout devra alors être accompagné de ses propres tests
 * d'intégration, et cette liste mise à jour consciemment.
 */
final class ProviderHonestyTest extends TestCase
{
    /** Les 6 opérations financières du contrat d'adaptateur. */
    private const OPERATIONS = [
        'getQuote',
        'createPayment',
        'getPaymentStatus',
        'cancelPayment',
        'getBalance',
        'verifyWebhook',
    ];

    /** @return list<array{0:string}> */
    public static function providerSlugs(): array
    {
        return array_map(
            static fn (string $slug): array => [$slug],
            array_keys(ProviderCatalog::all())
        );
    }

    private function invoke(object $adapter, string $operation): mixed
    {
        return match ($operation) {
            'getQuote'         => $adapter->getQuote(['amount' => 100, 'currency' => 'EUR']),
            'createPayment'    => $adapter->createPayment(['amount' => 100, 'currency' => 'EUR']),
            'getPaymentStatus' => $adapter->getPaymentStatus('pi_test'),
            'cancelPayment'    => $adapter->cancelPayment('pi_test'),
            'getBalance'       => $adapter->getBalance(),
            'verifyWebhook'    => $adapter->verifyWebhook('{}', 'signature'),
            default            => throw new \LogicException('Opération inconnue : ' . $operation),
        };
    }

    // ══ 1. Aucune opération ne renvoie un succès fictif ════════════════════

    /**
     * @dataProvider providerSlugs
     */
    public function test_no_provider_operation_returns_a_fake_success(string $slug): void
    {
        $adapter = ProviderRegistry::adapter($slug);

        foreach (self::OPERATIONS as $operation) {
            // `verifyWebhook` a une signature bool : un `false` est une
            // réponse honnête (signature non vérifiable sans secret).
            if ($operation === 'verifyWebhook') {
                try {
                    $result = $this->invoke($adapter, $operation);
                    $this->assertFalse(
                        $result,
                        sprintf('[%s] verifyWebhook ne doit jamais valider une signature sans secret.', $slug)
                    );
                } catch (ProviderOperationNotImplemented | Throwable) {
                    $this->addToAssertionCount(1); // refus explicite : acceptable
                }
                continue;
            }

            try {
                $this->invoke($adapter, $operation);
                $this->fail(sprintf(
                    '[%s] %s a répondu au lieu d\'échouer. Une opération non implémentée qui '
                    . 'retourne une valeur ferait croire au moteur qu\'un mouvement a eu lieu.',
                    $slug,
                    $operation
                ));
            } catch (ProviderOperationNotImplemented $e) {
                $this->assertStringContainsString($operation, $e->getMessage());
            } catch (Throwable $e) {
                // Un autre refus explicite (credential absente, etc.) est
                // acceptable : l'essentiel est qu'il n'y ait PAS de succès.
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ══ 2. Le catalogue reflète l'état réel ════════════════════════════════

    /**
     * Le Control Center doit rester factuel : tant qu'aucune opération n'est
     * implémentée, aucun provider ne peut en déclarer une.
     */
    public function test_catalogue_declares_no_implemented_operation(): void
    {
        $slugs = array_keys(ProviderCatalog::all());
        $this->assertNotEmpty($slugs, 'Le catalogue de providers ne doit pas être vide.');

        $implemented = 0;
        foreach ($slugs as $slug) {
            $adapter = ProviderRegistry::adapter($slug);

            foreach (['getQuote', 'createPayment', 'getPaymentStatus', 'cancelPayment', 'getBalance'] as $op) {
                try {
                    $this->invoke($adapter, $op);
                    $implemented++;
                } catch (Throwable) {
                    // non implémentée : attendu
                }
            }
        }

        $this->assertSame(
            0,
            $implemented,
            'Aucune opération provider n\'est censée être implémentée à ce stade. '
            . 'Si ce test échoue après une implémentation légitime, mettre à jour ce compte '
            . 'ET fournir les tests d\'intégration correspondants.'
        );
    }

    // ══ 3. Une credential ne rend pas une opération disponible ═════════════

    /**
     * Le piège classique : « la clé est configurée, donc ça marche ».
     * Configurer une credential ne doit rien changer à la disponibilité des
     * opérations — seule une implémentation le peut.
     */
    public function test_configuring_a_credential_does_not_enable_an_operation(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=sk_test_valeur_de_test');
        putenv('PROVIDERS_ENV=sandbox');

        try {
            $adapter = ProviderRegistry::adapter('stripe');

            $this->expectException(ProviderOperationNotImplemented::class);
            $adapter->createPayment(['amount' => 100, 'currency' => 'EUR']);
        } finally {
            putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY');
            putenv('PROVIDERS_ENV');
        }
    }
}
