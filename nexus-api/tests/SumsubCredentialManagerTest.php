<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Kyc\SumsubAdapter;
use Nexus\Providers\CredentialDefinition;
use Nexus\Providers\ProviderCredentialSchema;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Sumsub géré par le Credential Manager (§20, §36) :
 *
 *   - Sumsub apparaît dans le Control Center comme Compliance / KYC, pas
 *     comme provider de paiement ;
 *   - App Token / Secret Key / Webhook Secret sont chiffrés en base, par
 *     environnement (sandbox ≠ production) — jamais en clair, jamais dans
 *     le frontend ;
 *   - l'adaptateur résout ses credentials via le Credential Manager ; les
 *     variables d'environnement ne servent que de bootstrap d'infrastructure ;
 *   - testConnection() = appel RÉEL (transport simulé ici), avec le
 *     vocabulaire CONNECTION_SUCCESS / INVALID_CREDENTIALS / …
 *
 * AUCUN appel réel à Sumsub ; aucun secret réel.
 */
final class SumsubCredentialManagerTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    private const TEST_APP_TOKEN  = 'test-app-token-db-only';
    private const TEST_SECRET_KEY = 'test-secret-key-db-only';
    private const TEST_WH_SECRET  = 'test-wh-secret-db-only';

    protected function setUp(): void
    {
        // Enlever les variables d'environnement : la base doit être la SEULE
        // source de credentials (sinon le test ne prouve rien).
        foreach (['SUMSUB_APP_TOKEN', 'SUMSUB_SECRET_KEY', 'SUMSUB_WEBHOOK_SECRET',
                  'SUMSUB_ENVIRONMENT', 'SUMSUB_LEVEL_NAME', 'SUMSUB_LEVEL_NAME_KYB'] as $k) {
            putenv($k);
        }

        $this->pdo = Database::getConnection();
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'sumsub-cm-%@nexus.test'");

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES ('Sumsub CM', :email, 'x', 'business', 'ACTIVE', 'none')"
        );
        $stmt->execute(['email' => 'sumsub-cm-' . bin2hex(random_bytes(4)) . '@nexus.test']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare(
            'DELETE FROM provider_credentials WHERE user_id IS NULL AND provider_slug = :slug'
        )->execute(['slug' => 'sumsub']);
        $this->pdo->prepare(
            'DELETE FROM credential_rotations WHERE provider_slug = :slug'
        )->execute(['slug' => 'sumsub']);
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->userId]);
    }

    /** Enregistre les credentials Sumsub de plateforme pour un environnement. */
    private function storeSumsub(string $environment, string $suffix): void
    {
        ProviderCredentialService::upsertPlatform(
            $this->pdo,
            'sumsub',
            $environment,
            [
                'app_token'      => self::TEST_APP_TOKEN . '-' . $suffix,
                'secret_key'     => self::TEST_SECRET_KEY . '-' . $suffix,
                'webhook_secret' => self::TEST_WH_SECRET . '-' . $suffix,
            ],
            $environment === 'production' ? 'active' : 'sandbox_only',
            $this->userId
        );
    }

    /** Adaptateur avec transport simulé. */
    private function adapter(array $responses, ?string $environment = 'sandbox'): SumsubAdapter
    {
        $calls = 0;
        return SumsubAdapter::fromCredentialManager(
            $this->pdo,
            $environment,
            null,
            function (string $m, string $u, string $b, array $h) use ($responses, &$calls): array {
                $r = $responses[$calls] ?? end($responses);
                $calls++;
                return $r;
            }
        );
    }

    // ── §20 : Sumsub = Compliance / KYC dans le catalogue ──────────────────

    public function test_sumsub_apparait_comme_compliance_kyc(): void
    {
        self::assertTrue(ProviderCatalog::exists('sumsub'), 'Sumsub doit être dans le catalogue.');
        $provider = ProviderCatalog::get('sumsub');
        self::assertSame('compliance', $provider['category'], 'Compliance / KYC, jamais un provider de paiement.');

        $keys = array_column($provider['credentials'], 'key');
        self::assertSame(['app_token', 'secret_key', 'webhook_secret'], $keys);
        foreach ($provider['credentials'] as $field) {
            self::assertTrue($field['required'], 'Tous les secrets Sumsub sont requis.');
            self::assertSame('password', $field['type']);
        }
    }

    public function test_schema_sumsub_verifie_et_aucun_champ_exposable(): void
    {
        self::assertTrue(ProviderCredentialSchema::isVerified('sumsub'));
        $defs = ProviderCredentialSchema::for('sumsub');
        self::assertCount(3, $defs);

        foreach ($defs as $def) {
            self::assertSame(CredentialDefinition::SENSITIVITY_SECRET, $def->sensitivity);
            self::assertFalse($def->frontendExposable, 'Le frontend ne reçoit jamais App Token / Secret Key.');
        }
        self::assertSame([], ProviderCredentialSchema::frontendExposableFields('sumsub'));
        self::assertFalse(ProviderCredentialSchema::isFrontendExposable('sumsub', 'app_token'));
    }

    // ── §35/§36 : résolution via le Credential Manager, jamais l'env ───────

    public function test_adaptateur_resout_les_credentials_en_base(): void
    {
        $this->storeSumsub('sandbox', 'sb');

        $adapter = SumsubAdapter::fromCredentialManager($this->pdo, 'sandbox');
        self::assertTrue($adapter->isConfigured(), 'Configuré depuis la base, sans variable d\'environnement.');
        self::assertSame('sandbox', $adapter->environment());
    }

    public function test_sandbox_et_production_sont_etanchement_separees(): void
    {
        $this->storeSumsub('sandbox', 'sb');
        $this->storeSumsub('production', 'prod');

        $sb = SumsubAdapter::fromCredentialManager($this->pdo, 'sandbox');
        $prod = SumsubAdapter::fromCredentialManager($this->pdo, 'production');

        // La signature sandbox utilise la Secret Key sandbox, jamais celle de
        // production (et inversement).
        $sigSb = $sb->signRequest('GET', '/x', '', 1234567890);
        $sigProd = $prod->signRequest('GET', '/x', '', 1234567890);
        self::assertNotSame($sigSb, $sigProd, 'Sandbox ≠ production : jamais de secret partagé.');

        self::assertSame(
            hash_hmac('sha256', '1234567890GET/x', self::TEST_SECRET_KEY . '-sb'),
            $sigSb
        );
        self::assertSame(
            hash_hmac('sha256', '1234567890GET/x', self::TEST_SECRET_KEY . '-prod'),
            $sigProd
        );
    }

    public function test_sans_credentials_en_base_l_adaptateur_n_est_pas_configure(): void
    {
        $adapter = SumsubAdapter::fromCredentialManager($this->pdo, 'sandbox');
        self::assertFalse($adapter->isConfigured(), 'Aucune variable d\'environnement, aucune ligne en base.');
    }

    public function test_credentials_stockees_chiffrees_en_base(): void
    {
        $this->storeSumsub('sandbox', 'sb');

        $stmt = $this->pdo->prepare(
            'SELECT credentials_enc FROM provider_credentials WHERE user_id IS NULL AND provider_slug = :s'
        );
        $stmt->execute(['s' => 'sumsub']);
        $stored = (string) $stmt->fetchColumn();

        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(self::TEST_APP_TOKEN, $stored);
        self::assertStringNotContainsString(self::TEST_SECRET_KEY, $stored);
    }

    // ── §8/§38 : test de connexion réel ────────────────────────────────────

    public function test_connection_test_succes_avec_token_valide(): void
    {
        $this->storeSumsub('sandbox', 'sb');

        // 200 → authentification acceptée.
        $result = $this->adapter([['status' => 200, 'body' => '{}']])->testConnection('sandbox');
        self::assertSame('CONNECTION_SUCCESS', $result['status']);

        // 422 « applicantId requis » → la requête a été AUTHENTIFIÉE.
        $result = $this->adapter([['status' => 422, 'body' => '{"error":"applicantId is required"}']])->testConnection('sandbox');
        self::assertSame('CONNECTION_SUCCESS', $result['status']);
    }

    public function test_connection_test_echec_authentification(): void
    {
        $this->storeSumsub('sandbox', 'sb');

        $result = $this->adapter([['status' => 401, 'body' => '{}']])->testConnection('sandbox');
        self::assertSame('INVALID_CREDENTIALS', $result['status']);

        $result = $this->adapter([['status' => 403, 'body' => '{}']])->testConnection('sandbox');
        self::assertSame('INVALID_CREDENTIALS', $result['status']);
    }

    public function test_connection_test_provider_indisponible(): void
    {
        $this->storeSumsub('sandbox', 'sb');

        $result = $this->adapter([['status' => 503, 'body' => '']])->testConnection('sandbox');
        self::assertSame('PROVIDER_UNAVAILABLE', $result['status']);
    }

    public function test_connection_test_sans_credentials_aucun_appel_envoye(): void
    {
        $result = SumsubAdapter::fromCredentialManager($this->pdo, 'sandbox')->testConnection('sandbox');
        self::assertSame('PROVIDER_NOT_CONFIGURED', $result['status']);
    }

    public function test_test_connection_envoie_les_trois_headers_signes(): void
    {
        $this->storeSumsub('sandbox', 'sb');

        $captured = null;
        $adapter = SumsubAdapter::fromCredentialManager(
            $this->pdo,
            'sandbox',
            null,
            function (string $m, string $u, string $b, array $h) use (&$captured): array {
                $captured = ['m' => $m, 'u' => $u, 'h' => $h];
                return ['status' => 401, 'body' => '{}'];
            }
        );
        $adapter->testConnection('sandbox');

        self::assertNotNull($captured);
        $headers = implode("\n", $captured['h']);
        self::assertStringContainsString('X-App-Token: ' . self::TEST_APP_TOKEN . '-sb', $headers);
        self::assertMatchesRegularExpression('/X-App-Access-Ts: \d+/', $headers);
        self::assertMatchesRegularExpression('/X-App-Access-Sig: [0-9a-f]{64}/', $headers);

        // La signature est le HMAC-SHA256 exact de ts + GET + path + ''.
        preg_match('/X-App-Access-Ts: (\d+)/', $headers, $m);
        preg_match('/X-App-Access-Sig: ([0-9a-f]{64})/', $headers, $sig);
        $expected = hash_hmac('sha256', $m[1] . 'GET/resources/applicants/-;status', self::TEST_SECRET_KEY . '-sb');
        self::assertSame($expected, $sig[1]);
    }
}
