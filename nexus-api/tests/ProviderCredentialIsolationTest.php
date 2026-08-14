<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Providers\CredentialDefinition;
use Nexus\Providers\ProviderCredentialSchema;
use Nexus\Services\ProviderCredentialService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Isolation sandbox / production des credentials providers (§4, §33).
 *
 * Matrice exigée :
 *   sandbox    → sandbox     = PASS
 *   sandbox    → production  = FAIL
 *   production → production  = PASS
 *   production → sandbox     = FAIL
 */
final class ProviderCredentialIsolationTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'cred-iso-%@nexus.test'");

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES ('Cred Iso', :email, 'x', 'business', 'ACTIVE', 'none')"
        );
        $email = 'cred-iso-' . bin2hex(random_bytes(4)) . '@nexus.test';
        $stmt->execute(['email' => $email]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->userId]);
    }

    // ── Matrice d'isolation ────────────────────────────────────────────────

    public function test_sandbox_credential_resolue_en_sandbox_PASS(): void
    {
        ProviderCredentialService::upsert(
            $this->pdo, $this->userId, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_SANDBOX'], 'sandbox_only'
        );

        $resolved = ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'sandbox');

        self::assertNotNull($resolved);
        self::assertSame('sk_test_SANDBOX', $resolved['secret_key']);
    }

    public function test_sandbox_credential_JAMAIS_resolue_en_production_FAIL(): void
    {
        ProviderCredentialService::upsert(
            $this->pdo, $this->userId, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_SANDBOX'], 'sandbox_only'
        );

        $resolved = ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'production');

        self::assertNull($resolved, 'Une credential sandbox ne doit jamais être résolue en production.');
    }

    public function test_production_credential_resolue_en_production_PASS(): void
    {
        ProviderCredentialService::upsert(
            $this->pdo, $this->userId, 'stripe', 'production',
            ['secret_key' => 'sk_live_PROD'], 'active'
        );

        $resolved = ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'production');

        self::assertNotNull($resolved);
        self::assertSame('sk_live_PROD', $resolved['secret_key']);
    }

    public function test_production_credential_JAMAIS_resolue_en_sandbox_FAIL(): void
    {
        ProviderCredentialService::upsert(
            $this->pdo, $this->userId, 'stripe', 'production',
            ['secret_key' => 'sk_live_PROD'], 'active'
        );

        $resolved = ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'sandbox');

        self::assertNull($resolved, 'Une credential production ne doit jamais être résolue en sandbox.');
    }

    // ── Coexistence (régression corrigée en phase SQL) ─────────────────────

    public function test_sandbox_et_production_coexistent_sans_ecrasement(): void
    {
        ProviderCredentialService::upsert(
            $this->pdo, $this->userId, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_SANDBOX'], 'sandbox_only'
        );
        // Enregistrer la production ne doit PAS détruire la sandbox.
        ProviderCredentialService::upsert(
            $this->pdo, $this->userId, 'stripe', 'production',
            ['secret_key' => 'sk_live_PROD'], 'active'
        );

        $sandbox = ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'sandbox');
        $prod    = ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'production');

        self::assertSame('sk_test_SANDBOX', $sandbox['secret_key'] ?? null);
        self::assertSame('sk_live_PROD', $prod['secret_key'] ?? null);
    }

    public function test_mise_a_jour_sandbox_ne_touche_pas_production(): void
    {
        ProviderCredentialService::upsert($this->pdo, $this->userId, 'stripe', 'sandbox', ['secret_key' => 'v1'], 'sandbox_only');
        ProviderCredentialService::upsert($this->pdo, $this->userId, 'stripe', 'production', ['secret_key' => 'prod'], 'active');
        ProviderCredentialService::upsert($this->pdo, $this->userId, 'stripe', 'sandbox', ['secret_key' => 'v2'], 'sandbox_only');

        self::assertSame('v2', ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'sandbox')['secret_key']);
        self::assertSame('prod', ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'production')['secret_key']);
    }

    public function test_suppression_sandbox_preserve_production(): void
    {
        ProviderCredentialService::upsert($this->pdo, $this->userId, 'stripe', 'sandbox', ['secret_key' => 's'], 'sandbox_only');
        ProviderCredentialService::upsert($this->pdo, $this->userId, 'stripe', 'production', ['secret_key' => 'p'], 'active');

        ProviderCredentialService::delete($this->pdo, $this->userId, 'stripe', 'sandbox');

        self::assertNull(ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'sandbox'));
        self::assertNotNull(ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'production'));
    }

    // ── Credentials absentes / invalides ───────────────────────────────────

    public function test_credential_absente_retourne_null(): void
    {
        self::assertNull(ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'sandbox'));
    }

    public function test_environnement_invalide_refuse(): void
    {
        self::assertNull(ProviderCredentialService::normalizeEnvironment('staging'));
        self::assertNull(ProviderCredentialService::normalizeEnvironment(''));
        self::assertNull(ProviderCredentialService::resolve($this->pdo, $this->userId, 'stripe', 'staging'));
    }

    // ── Chiffrement : aucun secret en clair en base (§16) ──────────────────

    public function test_credential_est_chiffree_en_base(): void
    {
        ProviderCredentialService::upsert(
            $this->pdo, $this->userId, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_TRESSECRET'], 'sandbox_only'
        );

        $stmt = $this->pdo->prepare('SELECT credentials_enc FROM provider_credentials WHERE user_id = :u');
        $stmt->execute(['u' => $this->userId]);
        $stored = (string) $stmt->fetchColumn();

        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('sk_test_TRESSECRET', $stored, 'Le secret ne doit jamais être stocké en clair.');
    }

    // ── §6 : classification des clés publiques ─────────────────────────────

    public function test_stripe_publishable_key_est_exposable_secret_key_non(): void
    {
        self::assertTrue(ProviderCredentialSchema::isFrontendExposable('stripe', 'publishable_key'));
        self::assertFalse(ProviderCredentialSchema::isFrontendExposable('stripe', 'secret_key'));
        self::assertFalse(ProviderCredentialSchema::isFrontendExposable('stripe', 'webhook_secret'));
    }

    public function test_pawapay_aucune_credential_exposable_malgre_le_nom_public(): void
    {
        // Piège §6 : « private_key » de pawaPay sert à signer côté serveur.
        // Aucune credential pawaPay n'est destinée au navigateur.
        self::assertSame([], ProviderCredentialSchema::frontendExposableFields('pawapay'));
    }

    public function test_provider_non_verifie_n_expose_rien_par_defaut(): void
    {
        // Principe de précaution : schéma non vérifié → rien n'est exposable.
        self::assertFalse(ProviderCredentialSchema::isVerified('dlocal'));
        self::assertSame([], ProviderCredentialSchema::frontendExposableFields('dlocal'));
        self::assertFalse(ProviderCredentialSchema::isFrontendExposable('dlocal', 'publishable_key'));
    }

    public function test_toute_credential_secrete_est_backend_only(): void
    {
        foreach (['stripe', 'pawapay', 'wise', 'nium'] as $slug) {
            foreach (ProviderCredentialSchema::for($slug) as $def) {
                if ($def->sensitivity === CredentialDefinition::SENSITIVITY_SECRET) {
                    self::assertFalse(
                        $def->frontendExposable,
                        sprintf('%s.%s est secret et ne doit jamais être exposable.', $slug, $def->name)
                    );
                    self::assertTrue($def->mustRedact());
                }
            }
        }
    }
}
