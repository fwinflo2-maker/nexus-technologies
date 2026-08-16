<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\AbstractProviderAdapter;
use Nexus\Providers\ProviderCredentialSchema;
use Nexus\Providers\ProviderRegistry;
use Nexus\Services\ControlCenterService;
use Nexus\Services\ProviderCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * NEXUS CONTROL CENTER — tests d'honnêteté du plan de contrôle.
 *
 * Ces tests protègent une règle unique : le Control Center n'affiche que
 * des faits vérifiables. Il ne doit jamais annoncer une capacité, une
 * intégration « vérifiée » ou une clé exposable sans preuve dans le code.
 */
final class ControlCenterServiceTest extends TestCase
{
    /**
     * La matrice des opérations doit refléter le code réel, pas une
     * déclaration. Aujourd'hui aucun adapter n'implémente d'opération métier :
     * la matrice doit donc être intégralement `false`. Le jour où une
     * opération est réellement écrite, ce test échoue et force la mise à jour
     * de l'état documenté — c'est le comportement attendu.
     */
    public function testOperationMatrixReportsNoFalseCapability(): void
    {
        $checked = 0;

        foreach (array_keys(ProviderCatalog::all()) as $slug) {
            $matrix = ControlCenterService::operationMatrix($slug);

            $this->assertSame(
                ControlCenterService::PROVIDER_OPERATIONS,
                array_keys($matrix),
                "La matrice de {$slug} doit couvrir exactement les opérations recensées."
            );

            foreach ($matrix as $operation => $implemented) {
                $checked++;
                if ($implemented === false) {
                    continue;
                }

                // Si la matrice annonce « implémenté », le prouver par réflexion.
                $adapter = ProviderRegistry::adapter($slug);
                $declaring = (new ReflectionMethod($adapter, $operation))->getDeclaringClass()->getName();

                $this->assertNotSame(
                    AbstractProviderAdapter::class,
                    $declaring,
                    "Le Control Center annonce {$slug}::{$operation} comme implémenté "
                    . 'alors que la méthode est héritée de la classe abstraite.'
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'Aucune opération n\'a été vérifiée.');
    }

    /**
     * Test négatif : la matrice doit savoir dire « oui ». Un adapter anonyme
     * qui implémente réellement une opération doit être détecté — sinon le
     * test précédent passerait pour de mauvaises raisons.
     */
    public function testOperationDetectionRecognisesRealImplementation(): void
    {
        $method = new ReflectionMethod(AbstractProviderAdapter::class, 'getBalance');

        $this->assertSame(
            AbstractProviderAdapter::class,
            $method->getDeclaringClass()->getName(),
            'getBalance() doit être héritée tant qu\'aucun adapter ne la surcharge.'
        );

        // Un adapter concret qui surcharge la méthode change la classe déclarante.
        $fake = new class ('fake') extends AbstractProviderAdapter {
            protected function declaredMethods(): array { return ['card']; }
            public function getBalance(): array { return ['implemented' => true]; }
        };

        $this->assertNotSame(
            AbstractProviderAdapter::class,
            (new ReflectionMethod($fake, 'getBalance'))->getDeclaringClass()->getName(),
            'La détection par réflexion doit reconnaître une implémentation réelle.'
        );
    }

    /**
     * §7/§8 : toute clé est backend-only par défaut. Une clé n'est déclarée
     * exposable au frontend que si le provider le documente explicitement.
     * Un secret ne doit JAMAIS être marqué exposable.
     */
    public function testNoSecretIsMarkedFrontendExposable(): void
    {
        $exposable = [];

        foreach (array_keys(ProviderCatalog::all()) as $slug) {
            $defs = ProviderCredentialSchema::for($slug);
            if ($defs === null) {
                continue;
            }

            foreach ($defs as $def) {
                if (!$def->frontendExposable) {
                    continue;
                }

                $exposable[] = $slug . '.' . $def->name;

                $this->assertNotSame(
                    'secret',
                    $def->sensitivity,
                    "{$slug}.{$def->name} est marqué exposable au frontend alors "
                    . 'qu\'il est classé comme secret.'
                );
                $this->assertFalse(
                    $def->mustRedact(),
                    "{$slug}.{$def->name} est exposable mais exige une redaction : contradiction."
                );
                $this->assertNotSame(
                    '',
                    trim((string) $def->justification),
                    "{$slug}.{$def->name} est exposable sans justification documentée."
                );
            }
        }

        // Verrou explicite : à ce jour, une seule clé au monde est exposable.
        $this->assertSame(
            ['stripe.publishable_key'],
            $exposable,
            'La liste des clés exposables au frontend a changé : toute addition '
            . 'doit être justifiée par la documentation officielle du provider.'
        );
    }

    /**
     * §22 : aucun statut « verified » sans schéma réellement défini.
     */
    public function testDocumentationStatusNeverClaimsVerifiedWithoutSchema(): void
    {
        foreach (array_keys(ProviderCatalog::all()) as $slug) {
            $status = ControlCenterService::documentationStatus($slug);
            $hasSchema = ProviderCredentialSchema::for($slug) !== null;

            if (!$hasSchema) {
                $this->assertSame(
                    'unknown',
                    $status['documentation'],
                    "{$slug} n'a pas de schéma vérifié mais est annoncé comme documenté."
                );
                $this->assertSame('unknown', $status['authentication']);
                $this->assertSame('unknown', $status['webhook']);
            }
        }
    }

    /**
     * Les opérations recensées doivent exister sur le contrat d'adapter :
     * la matrice ne peut pas inventer d'opérations fantômes.
     */
    public function testEveryListedOperationExistsOnAdapterContract(): void
    {
        foreach (ControlCenterService::PROVIDER_OPERATIONS as $operation) {
            $this->assertTrue(
                method_exists(AbstractProviderAdapter::class, $operation),
                "L'opération « {$operation} » listée par le Control Center n'existe pas sur l'adapter."
            );
        }
    }
}
