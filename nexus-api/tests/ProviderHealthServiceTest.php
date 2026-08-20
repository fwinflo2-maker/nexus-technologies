<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;
use Nexus\Services\ProviderHealthService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * §6 — Provider Health : configured / connected / degraded / unavailable /
 * disabled, avec derniers tests — AUCUN secret dans la sortie.
 */
final class ProviderHealthServiceTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'health-test-%@nexus.test'");

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES ('Health Test', :email, 'x', 'business', 'ACTIVE', 'none')"
        );
        $stmt->execute(['email' => 'health-test-' . bin2hex(random_bytes(4)) . '@nexus.test']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare(
            'DELETE FROM provider_credentials WHERE user_id IS NULL AND provider_slug = :slug'
        )->execute(['slug' => 'stripe']);
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->userId]);
    }

    public function test_sans_credentials_le_provider_est_disabled(): void
    {
        $health = ProviderHealthService::healthFor($this->pdo, 'stripe', 'sandbox');
        self::assertFalse($health['configured']);
        self::assertSame('NOT_CONFIGURED', $health['connection']);
        self::assertNull($health['last_successful_test']);
    }

    public function test_credentials_sans_test_configured_pas_connected(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'stripe', 'sandbox', ['secret_key' => 'sk_test_x'], 'sandbox_only', $this->userId
        );

        $health = ProviderHealthService::healthFor($this->pdo, 'stripe', 'sandbox');
        self::assertTrue($health['configured']);
        self::assertSame('configured', $health['connection'], 'Présence de credentials ≠ connecté.');
        self::assertNull($health['last_successful_test']);
    }

    public function test_dernier_test_reussi_connected(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'stripe', 'sandbox', ['secret_key' => 'sk_test_x'], 'sandbox_only', $this->userId
        );
        ProviderCredentialService::markPlatformTested($this->pdo, 'stripe', 'sandbox', 'sandbox_only', null);

        $health = ProviderHealthService::healthFor($this->pdo, 'stripe', 'sandbox');
        self::assertSame('connected', $health['connection']);
        self::assertNotNull($health['last_successful_test']);
        self::assertNull($health['last_error_code']);
    }

    public function test_dernier_test_en_echec_degraded(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'stripe', 'sandbox', ['secret_key' => 'sk_test_x'], 'sandbox_only', $this->userId
        );
        ProviderCredentialService::markPlatformTested(
            $this->pdo, 'stripe', 'sandbox', 'error', 'Authentification Stripe refusée (401).'
        );

        $health = ProviderHealthService::healthFor($this->pdo, 'stripe', 'sandbox');
        self::assertSame('degraded', $health['connection']);
        self::assertNotNull($health['last_failed_test']);
        self::assertStringContainsString('401', (string) $health['last_error_code']);
    }

    public function test_la_sante_n_expose_jamais_de_secret(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_TRES_SECRET'], 'sandbox_only', $this->userId
        );

        $health = ProviderHealthService::healthFor($this->pdo, 'stripe', 'sandbox');
        $json = json_encode($health, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('sk_test_TRES_SECRET', (string) $json);
    }

    public function test_le_resume_couvre_tous_les_providers_dans_les_deux_environnements(): void
    {
        $summary = ProviderHealthService::summary($this->pdo);

        $envs = [];
        foreach ($summary as $row) {
            $envs[$row['provider'] . '/' . $row['environment']] = true;
        }
        self::assertArrayHasKey('stripe/sandbox', $envs);
        self::assertArrayHasKey('stripe/production', $envs);
        self::assertArrayHasKey('pawapay/sandbox', $envs);
        self::assertArrayHasKey('sumsub/sandbox', $envs);
    }
}
