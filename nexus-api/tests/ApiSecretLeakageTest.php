<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\ProviderCredentialController;
use Nexus\Controllers\UserController;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Providers\SecretRedactor;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 2 — FUITE DE SECRETS À LA FRONTIÈRE HTTP.
 *
 * L'absence de secret dans une réponse est aujourd'hui garantie par
 * construction, mais rien ne l'empêche de régresser : il suffit qu'un
 * `SELECT *` remplace une liste de colonnes explicite pour que
 * `password_hash` se retrouve sérialisé.
 *
 * Ces tests verrouillent l'invariant au niveau de la réponse réellement
 * émise, et non par lecture du code.
 */
final class ApiSecretLeakageTest extends TestCase
{
    /** Motifs qui ne doivent jamais apparaître dans une réponse d'API. */
    private const FORBIDDEN = [
        'password_hash',
        'secret_key',
        'api_token',
        'webhook_secret',
        'private_key',
        'client_secret',
        '$2y$',                       // préfixe bcrypt
        '-----BEGIN',                 // clé privée PEM
    ];

    private PDO $pdo;
    private int $userId = 0;
    private string $token = '';

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Leak Probe',
            'e' => 'leak_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('SuperSecret#2026', PASSWORD_BCRYPT),
            't' => 'business',
            'c' => 'CG',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $this->token = \Nexus\Auth\Jwt::encode([
            'sub' => $this->userId,
            'iat' => time(),
            'exp' => time() + 3600,
        ]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION']);

        if ($this->userId > 0) {
            $this->pdo->prepare('DELETE FROM provider_credentials WHERE user_id = ?')->execute([$this->userId]);
            $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$this->userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->userId = 0;
        }
    }

    /** Exécute un contrôleur et retourne le corps JSON brut réellement émis. */
    private function capture(callable $action): string
    {
        try {
            $action();
        } catch (ResponseSent $sent) {
            return $sent->body();
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]) ?: '';
        }

        return '';
    }

    private function assertNoSecret(string $body, string $context): void
    {
        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                sprintf('La réponse de %s ne doit jamais contenir « %s ».', $context, $needle)
            );
        }
    }

    // ══ 1. Le profil utilisateur ═══════════════════════════════════════════

    public function test_user_profile_never_exposes_the_password_hash(): void
    {
        $body = $this->capture(static fn () => UserController::me(new Request([])));

        $this->assertNotSame('', $body, 'Le contrôleur doit émettre une réponse.');
        $this->assertNoSecret($body, 'GET /me');
    }

    // ══ 2. Le gestionnaire de credentials ══════════════════════════════════

    /**
     * Le cas le plus sensible : cet endpoint manipule par nature des secrets
     * de providers. Il doit en exposer les métadonnées, jamais les valeurs.
     */
    public function test_credential_listing_never_exposes_secret_values(): void
    {
        $secret = 'sk_live_VALEUR_ULTRA_SECRETE_' . bin2hex(random_bytes(4));

        $stmt = $this->pdo->prepare(
            'INSERT INTO provider_credentials (user_id, provider_slug, environment, credentials_enc, status)
             VALUES (:u, :s, :e, :c, :st)'
        );
        $stmt->execute([
            'u'  => $this->userId,
            's'  => 'stripe',
            'e'  => 'sandbox',
            'c'  => \Nexus\Core\Crypto::encrypt(json_encode(['secret_key' => $secret])),
            'st' => 'sandbox_only',
        ]);

        $body = $this->capture(static fn () => ProviderCredentialController::list(new Request([])));

        $this->assertStringNotContainsString(
            $secret,
            $body,
            'La valeur en clair d\'une credential ne doit jamais sortir de l\'API.'
        );
        $this->assertStringNotContainsString(
            'credentials_enc',
            $body,
            'Même la version chiffrée ne doit pas être exposée.'
        );
    }

    // ══ 3. Le redacteur central fait bien son travail ══════════════════════

    /**
     * `SecretRedactor` est la brique invoquée par les journaux et les
     * réponses. S'il laissait passer une clé sensible, toutes les protections
     * qui s'appuient sur lui tomberaient d'un coup.
     */
    public function test_secret_redactor_masks_every_sensitive_key(): void
    {
        $payload = [
            'provider'       => 'stripe',
            'secret_key'     => 'sk_live_NE_DOIT_PAS_SORTIR',
            'api_token'      => 'tok_NE_DOIT_PAS_SORTIR',
            'webhook_secret' => 'whsec_NE_DOIT_PAS_SORTIR',
            'private_key'    => '-----BEGIN PRIVATE KEY-----',
            'nested'         => ['client_secret' => 'cs_NE_DOIT_PAS_SORTIR'],
        ];

        $serialized = json_encode(SecretRedactor::redactArray($payload));

        foreach (['sk_live_NE_DOIT_PAS_SORTIR', 'tok_NE_DOIT_PAS_SORTIR',
                  'whsec_NE_DOIT_PAS_SORTIR', 'cs_NE_DOIT_PAS_SORTIR'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $serialized);
        }

        // L'information non sensible reste exploitable pour le diagnostic.
        $this->assertStringContainsString('stripe', (string) $serialized);
    }

    // ══ 4. Aucun secret ne transite par les journaux d'audit ═══════════════

    public function test_audit_logs_contain_no_secret_material(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
              WHERE metadata REGEXP 'sk_live_|whsec_|BEGIN [A-Z ]*PRIVATE KEY'"
        );
        $stmt->execute();

        $this->assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'Aucune ligne d\'audit ne doit contenir de matière secrète.'
        );
    }
}
