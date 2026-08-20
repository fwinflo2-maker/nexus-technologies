<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderCapabilityMatrix;
use Nexus\Services\CapabilityEngine;
use Nexus\Services\FundingSourceEngine;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * §21 — Le routing ne promet que du réellement exécutable.
 *
 * 1. CapabilityEngine exclut les providers configurés mais dont l'intégration
 *    payout n'est pas implémentée (matrice = NOT_IMPLEMENTED) : un provider
 *    « configuré » ne doit JAMAIS être routé si l'exécution échouerait.
 * 2. Le Super Admin conserve son exemption d'origine à l'ÉXÉCUTION : une
 *    quote créée depuis un pays sans source vérifiée doit rester exécutable
 *    (pas de 403 ORIGIN_FORBIDDEN au re-jeu du contrôle).
 *
 * Base utilisée : nexus_test (isolée).
 */
final class RoutingOnlyImplementedProvidersTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Les tests RoutingOnlyImplementedProvidersTest doivent utiliser nexus_test uniquement.');
        }
    }

    /**
     * La matrice honnête : pawaPay est le seul payout réellement câblé.
     */
    public function test_matrice_payout_est_la_source_de_verite(): void
    {
        self::assertSame(
            ProviderCapabilityMatrix::IMPLEMENTED,
            ProviderCapabilityMatrix::for('pawapay')['payout'],
            'pawaPay Merchant API v2 payout est câblé.'
        );
        foreach (['nium', 'wise', 'western_union', 'stripe'] as $slug) {
            self::assertSame(
                ProviderCapabilityMatrix::NOT_IMPLEMENTED,
                ProviderCapabilityMatrix::for($slug)['payout'],
                "{$slug} ne doit pas prétendre exécuter un payout."
            );
        }
    }

    /**
     * Un provider payout NOT_IMPLEMENTED doit être EXCLU du routing, même
     * configuré. On vérifie via la matrice que la fonction d'éligibilité
     * consomme bien la matrice (test structurel : la constante est publique
     * et le filtre documenté dans CapabilityEngine).
     */
    public function test_le_filtre_capability_engine_est_present(): void
    {
        $src = file_get_contents(__DIR__ . '/../src/Services/CapabilityEngine.php');
        self::assertNotFalse($src, 'CapabilityEngine doit exister.');
        self::assertStringContainsString(
            'ProviderCapabilityMatrix',
            $src,
            'CapabilityEngine doit consommer la matrice de capacités.'
        );
        self::assertStringContainsString(
            "['payout']",
            $src,
            'Le filtre d\'éligibilité doit porter sur la capacité payout.'
        );
    }

    /**
     * L'exemption d'origine du Super Admin doit survivre à l'exécution :
     * ExecutionEngine relance validateOrigin avec $allowAny pour un superadmin.
     */
    public function test_execution_conserve_exemption_superadmin_origine(): void
    {
        $src = file_get_contents(__DIR__ . '/../src/Services/ExecutionEngine.php');
        self::assertNotFalse($src, 'ExecutionEngine doit exister.');
        self::assertStringContainsString(
            'platform_role',
            $src,
            'L\'exécution doit re-lire le rôle de l\'utilisateur.'
        );
        self::assertStringContainsString(
            '$isSuperAdmin',
            $src,
            'L\'exécution doit calculer l\'exemption Super Admin.'
        );
        self::assertStringContainsString(
            'validateOrigin(',
            $src,
            'L\'exécution doit re-valider l\'origine.'
        );
        self::assertMatchesRegularExpression(
            '/validateOrigin\([\s\S]*\$isSuperAdmin/',
            $src,
            'L\'exécution doit propager $allowAny pour le Super Admin.'
        );
    }

    /**
     * FundingSourceEngine::validateOrigin avec allowAny=true autorise
     * n'importe quel pays, même sans source vérifiée (contrat superadmin).
     */
    public function test_validate_origin_allow_any_superadmin(): void
    {
        $check = FundingSourceEngine::validateOrigin(
            999999,
            ['id' => 999999, 'kyc_level' => 'none', 'country_of_residence' => null],
            'PL',
            true
        );
        self::assertTrue($check['authorized'], 'allowAny doit autoriser PL sans source.');
        self::assertSame('superadmin', $check['sources'][0]['kind'] ?? null, 'La source signalée est superadmin.');
    }

    /**
     * Sans allowAny, un pays hors sources vérifiées est refusé (contrat client).
     */
    public function test_validate_origin_client_sans_source_refuse(): void
    {
        $check = FundingSourceEngine::validateOrigin(
            999998,
            ['id' => 999998, 'kyc_level' => 'none', 'country_of_residence' => null],
            'PL',
            false
        );
        self::assertFalse($check['authorized'], 'Un client sans source ne peut pas envoyer depuis PL.');
    }
}
