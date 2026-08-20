<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Crypto;
use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Rotation des credentials providers (§29) :
 *
 *   Active credential → New credential (staged) → Test → Activate
 *   → Old credential revoked (jamais supprimée avant validation).
 *
 * Garanties :
 *   1. staged ≠ active : les nouvelles credentials ne deviennent jamais
 *      résolvables avant activation explicite ;
 *   2. activation = transaction : l'ancienne valeur est ARCHIVÉE (revoked)
 *      avant promotion de la nouvelle — aucune perte de secret ;
 *   3. révocation : la valeur révoquée reste tracée, le provider redevient
 *      non configuré ;
 *   4. l'historique n'expose JAMAIS de secret (aucune colonne credentials_enc).
 */
final class CredentialRotationTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'rot-test-%@nexus.test'");

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES ('Rotation Test', :email, 'x', 'business', 'ACTIVE', 'none')"
        );
        $stmt->execute(['email' => 'rot-test-' . bin2hex(random_bytes(4)) . '@nexus.test']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        // Nettoyage strict : les lignes de plateforme (user_id IS NULL) et les
        // rotations créées par ce fichier, pour chaque slug utilisé.
        foreach (['stripe', 'pawapay'] as $slug) {
            $this->pdo->prepare(
                'DELETE FROM provider_credentials WHERE user_id IS NULL AND provider_slug = :slug'
            )->execute(['slug' => $slug]);
            $this->pdo->prepare(
                'DELETE FROM credential_rotations WHERE provider_slug = :slug'
            )->execute(['slug' => $slug]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->userId]);
    }

    public function test_staging_ne_touche_jamais_a_la_credential_active(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_ACTIVE'], 'sandbox_only', $this->userId
        );

        $rotationId = ProviderCredentialService::stagePlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_STAGED'], $this->userId
        );
        self::assertGreaterThan(0, $rotationId);

        // La résolution continue de servir l'ANCIENNE valeur.
        $active = ProviderCredentialService::resolvePlatform($this->pdo, 'stripe', 'sandbox');
        self::assertSame('sk_test_ACTIVE', $active['secret_key'] ?? null);

        // La staged n'est résolvable QUE par son identifiant de rotation.
        $staged = ProviderCredentialService::resolveStaged($this->pdo, 'stripe', 'sandbox', $rotationId);
        self::assertSame('sk_test_STAGED', $staged['secret_key'] ?? null);
    }

    public function test_activation_archive_l_ancienne_avant_de_promouvoir_la_nouvelle(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_OLD'], 'sandbox_only', $this->userId
        );

        $rotationId = ProviderCredentialService::stagePlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_NEW'], $this->userId
        );

        ProviderCredentialService::activateRotation($this->pdo, 'stripe', 'sandbox', $rotationId, $this->userId);

        // La nouvelle valeur est active.
        $active = ProviderCredentialService::resolvePlatform($this->pdo, 'stripe', 'sandbox');
        self::assertSame('sk_test_NEW', $active['secret_key'] ?? null);

        // L'ancienne est archivée (revoked) : deux rotations — active + revoked.
        $rows = ProviderCredentialService::listRotations($this->pdo, 'stripe', 'sandbox');
        self::assertCount(2, $rows);

        $revoked = null;
        $activeRot = null;
        foreach ($rows as $r) {
            if ($r['status'] === 'revoked') {
                $revoked = $r;
            }
            if ($r['status'] === 'active') {
                $activeRot = $r;
            }
        }
        self::assertNotNull($revoked, 'L\'ancienne credential doit être archivée comme révoquée.');
        self::assertNotNull($activeRot, 'La rotation activée doit être tracée.');
        self::assertNotNull($activeRot['activated_at']);
        self::assertNotNull($revoked['revoked_at']);

        // La valeur archivée est bien l'ANCIENNE (lue en SQL direct :
        // listRotations n'expose JAMAIS credentials_enc — par conception).
        $stmt = $this->pdo->prepare(
            'SELECT credentials_enc FROM credential_rotations WHERE id = :id'
        );
        $stmt->execute(['id' => (int) $revoked['id']]);
        $plain = Crypto::decrypt((string) $stmt->fetchColumn());
        self::assertNotNull($plain);
        $payload = json_decode($plain, true);
        self::assertSame('sk_test_OLD', $payload['credentials']['secret_key'] ?? null);
    }

    public function test_activation_sans_rotation_staged_est_refusee(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'pawapay', 'sandbox',
            ['api_token' => 'tok_active'], 'sandbox_only', $this->userId
        );

        $this->expectException(RuntimeException::class);
        ProviderCredentialService::activateRotation($this->pdo, 'pawapay', 'sandbox', 999999, $this->userId);
    }

    public function test_activation_d_une_rotation_d_un_autre_environnement_est_refusee(): void
    {
        $rotationId = ProviderCredentialService::stagePlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_SB'], $this->userId
        );

        // Tenter d'activer une rotation sandbox comme si elle était production.
        $this->expectException(RuntimeException::class);
        ProviderCredentialService::activateRotation($this->pdo, 'stripe', 'production', $rotationId, $this->userId);
    }

    public function test_revocation_archive_et_retire_la_credential_active(): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_TO_REVOKE'], 'sandbox_only', $this->userId
        );

        ProviderCredentialService::revokePlatform($this->pdo, 'stripe', 'sandbox', $this->userId);

        // Plus aucune credential active pour cet environnement.
        self::assertNull(ProviderCredentialService::resolvePlatform($this->pdo, 'stripe', 'sandbox'));

        // Mais l'historique garde la trace de la valeur révoquée.
        $rows = ProviderCredentialService::listRotations($this->pdo, 'stripe', 'sandbox');
        self::assertCount(1, $rows);
        self::assertSame('revoked', $rows[0]['status']);

        $stmt = $this->pdo->prepare(
            'SELECT credentials_enc FROM credential_rotations WHERE id = :id'
        );
        $stmt->execute(['id' => (int) $rows[0]['id']]);
        $plain = Crypto::decrypt((string) $stmt->fetchColumn());
        self::assertNotNull($plain);
        $payload = json_decode($plain, true);
        self::assertSame('sk_test_TO_REVOKE', $payload['credentials']['secret_key'] ?? null);
    }

    public function test_historique_des_rotations_n_expose_jamais_de_secret(): void
    {
        ProviderCredentialService::stagePlatform(
            $this->pdo, 'stripe', 'sandbox',
            ['secret_key' => 'sk_test_SUPERSECRET'], $this->userId
        );
        ProviderCredentialService::stagePlatform(
            $this->pdo, 'stripe', 'production',
            ['secret_key' => 'sk_live_SUPERSECRET'], $this->userId
        );

        $items = ProviderCredentialService::listRotations($this->pdo);

        self::assertGreaterThanOrEqual(2, count($items));
        foreach ($items as $item) {
            self::assertArrayNotHasKey('credentials_enc', $item, 'Jamais de valeur chiffrée dans l\'inventaire.');
            $json = json_encode($item, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('SUPERSECRET', (string) $json, 'Aucun secret dans la réponse.');
        }
    }
}
