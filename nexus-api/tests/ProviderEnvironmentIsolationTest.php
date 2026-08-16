<?php

declare(strict_types=1);

namespace Nexus\Tests;

use InvalidArgumentException;
use Nexus\Core\Crypto;
use Nexus\Core\Database;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderStatus;
use Nexus\Services\ProviderCredentialService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * ISOLATION SANDBOX / PRODUCTION — tests de non-franchissement de frontière.
 *
 * Règle prouvée ici : le triplet `provider + environment + credential_name`
 * identifie une credential et une seule. Aucune résolution implicite ne peut
 * franchir la frontière entre environnements, dans un sens comme dans l'autre.
 *
 * Ces tests couvrent les DEUX chemins de résolution de l'application :
 *   1. variables d'environnement  → ProviderConfig::credential()
 *   2. base de données chiffrée   → ProviderCredentialService::resolve()
 *
 * Aucun secret réel n'est utilisé : uniquement des valeurs sentinelles dont la
 * présence dans le mauvais environnement constitue la preuve d'une fuite.
 */
final class ProviderEnvironmentIsolationTest extends TestCase
{
    /** Sentinelles : leur apparition dans le mauvais environnement = fuite. */
    private const SANDBOX_SENTINEL    = 'SANDBOX_SECRET_TEST';
    private const PRODUCTION_SENTINEL = 'PRODUCTION_SECRET_TEST';

    protected function setUp(): void
    {
        $this->clearProviderEnv();
    }

    protected function tearDown(): void
    {
        $this->clearProviderEnv();
    }

    private function clearProviderEnv(): void
    {
        foreach (getenv() as $key => $_) {
            if (str_starts_with((string) $key, 'PROVIDER')) {
                putenv((string) $key);
            }
        }
    }

    // ══ Test A — credential définie en PRODUCTION uniquement ═══════════════

    public function test_production_only_credential_is_never_served_to_sandbox(): void
    {
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=' . self::PRODUCTION_SENTINEL);

        $this->assertSame(
            self::PRODUCTION_SENTINEL,
            ProviderConfig::credential('stripe', 'SECRET_KEY', 'production'),
            'La credential production doit être lisible dans son propre environnement.'
        );

        $sandbox = ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox');

        $this->assertNull($sandbox, 'Aucune credential ne doit exister en sandbox.');
        $this->assertNotSame(
            self::PRODUCTION_SENTINEL,
            $sandbox,
            'FUITE : une clé de PRODUCTION a été servie à l\'environnement SANDBOX.'
        );
    }

    // ══ Test B — credential définie en SANDBOX uniquement ══════════════════

    public function test_sandbox_only_credential_is_never_served_to_production(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=' . self::SANDBOX_SENTINEL);

        $this->assertSame(
            self::SANDBOX_SENTINEL,
            ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox')
        );

        $production = ProviderConfig::credential('stripe', 'SECRET_KEY', 'production');

        $this->assertNull($production);
        $this->assertNotSame(
            self::SANDBOX_SENTINEL,
            $production,
            'FUITE : une clé SANDBOX a été servie à l\'environnement PRODUCTION.'
        );
    }

    // ══ Test C — les deux environnements coexistent sans interférence ══════

    public function test_both_environments_resolve_independently(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=' . self::SANDBOX_SENTINEL);
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=' . self::PRODUCTION_SENTINEL);

        $this->assertSame(self::SANDBOX_SENTINEL, ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox'));
        $this->assertSame(self::PRODUCTION_SENTINEL, ProviderConfig::credential('stripe', 'SECRET_KEY', 'production'));

        // L'ordre d'appel ne doit rien changer (absence d'état résiduel).
        $this->assertSame(self::PRODUCTION_SENTINEL, ProviderConfig::credential('stripe', 'SECRET_KEY', 'production'));
        $this->assertSame(self::SANDBOX_SENTINEL, ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox'));
    }

    // ══ Test D — credential inexistante : aucun repli ══════════════════════

    public function test_missing_credential_never_falls_back_to_another_value(): void
    {
        // Un autre champ ET l'autre environnement sont renseignés : aucun des
        // deux ne doit servir de repli au champ demandé.
        putenv('PROVIDER_STRIPE_SANDBOX_PUBLISHABLE_KEY=pk_test_autre_champ');
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=' . self::PRODUCTION_SENTINEL);

        $this->assertNull(
            ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox'),
            'Un champ absent ne doit jamais être résolu par un autre champ ni par l\'autre environnement.'
        );
        $this->assertNull(ProviderConfig::credential('stripe', 'WEBHOOK_SECRET', 'sandbox'));
        $this->assertNull(ProviderConfig::credential('stripe', 'CHAMP_INEXISTANT', 'production'));
    }

    /**
     * La variable GÉNÉRIQUE héritée (cause historique de la fuite) ne doit
     * plus jamais être lue, quel que soit l'environnement demandé.
     */
    public function test_legacy_generic_variable_is_never_read(): void
    {
        putenv('PROVIDER_STRIPE_SECRET_KEY=' . self::PRODUCTION_SENTINEL);

        $this->assertNull(
            ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox'),
            'La variable générique ne doit plus alimenter la sandbox.'
        );
        $this->assertNull(
            ProviderConfig::credential('stripe', 'SECRET_KEY', 'production'),
            'La variable générique ne doit plus alimenter la production.'
        );
    }

    /** Et sa présence doit être signalée, pas ignorée en silence. */
    public function test_legacy_generic_variable_is_reported_as_misconfiguration(): void
    {
        putenv('PROVIDER_STRIPE_ENABLED=true');
        putenv('PROVIDER_STRIPE_SECRET_KEY=' . self::PRODUCTION_SENTINEL);

        $this->assertSame(
            ['PROVIDER_STRIPE_SECRET_KEY'],
            ProviderConfig::legacyGenericVariables('stripe')
        );

        $validation = ProviderConfig::validate('stripe', 'sandbox');
        $this->assertSame(ProviderStatus::INVALID_CONFIGURATION, $validation['status']);

        // Le diagnostic nomme la variable mais ne divulgue JAMAIS sa valeur.
        $this->assertStringContainsString('PROVIDER_STRIPE_SECRET_KEY', (string) $validation['reason']);
        $this->assertStringNotContainsString(self::PRODUCTION_SENTINEL, (string) $validation['reason']);
    }

    // ══ Test E — plusieurs champs du même provider ═════════════════════════

    public function test_every_field_respects_its_own_environment(): void
    {
        $fields = ['PUBLISHABLE_KEY', 'SECRET_KEY', 'WEBHOOK_SECRET'];

        foreach ($fields as $field) {
            putenv("PROVIDER_STRIPE_SANDBOX_{$field}=sandbox_{$field}");
            putenv("PROVIDER_STRIPE_PRODUCTION_{$field}=production_{$field}");
        }

        foreach ($fields as $field) {
            $sandbox    = ProviderConfig::credential('stripe', $field, 'sandbox');
            $production = ProviderConfig::credential('stripe', $field, 'production');

            $this->assertSame("sandbox_{$field}", $sandbox);
            $this->assertSame("production_{$field}", $production);
            $this->assertNotSame($production, $sandbox, "Le champ {$field} mélange les environnements.");
        }
    }

    /** Un provider ne doit jamais lire les credentials d'un autre provider. */
    public function test_credentials_do_not_cross_provider_boundary(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=' . self::SANDBOX_SENTINEL);

        $this->assertNull(ProviderConfig::credential('pawapay', 'SECRET_KEY', 'sandbox'));
        $this->assertNull(ProviderConfig::credential('wise', 'SECRET_KEY', 'sandbox'));
    }

    // ══ Test F — pas de cache partagé entre environnements ═════════════════

    public function test_resolution_is_not_cached_across_environments(): void
    {
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY=' . self::SANDBOX_SENTINEL);
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=' . self::PRODUCTION_SENTINEL);

        // Lecture sandbox EN PREMIER, puis production : si un cache était
        // indexé sur (provider, field) seuls, la 2e lecture rendrait la 1re.
        $first  = ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox');
        $second = ProviderConfig::credential('stripe', 'SECRET_KEY', 'production');
        $this->assertSame(self::SANDBOX_SENTINEL, $first);
        $this->assertSame(self::PRODUCTION_SENTINEL, $second);

        // Puis l'inverse.
        $third  = ProviderConfig::credential('stripe', 'SECRET_KEY', 'production');
        $fourth = ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox');
        $this->assertSame(self::PRODUCTION_SENTINEL, $third);
        $this->assertSame(self::SANDBOX_SENTINEL, $fourth);

        // Une valeur retirée disparaît réellement (aucune rémanence de cache).
        putenv('PROVIDER_STRIPE_SANDBOX_SECRET_KEY');
        $this->assertNull(ProviderConfig::credential('stripe', 'SECRET_KEY', 'sandbox'));
        $this->assertSame(self::PRODUCTION_SENTINEL, ProviderConfig::credential('stripe', 'SECRET_KEY', 'production'));
    }

    // ══ Alias d'environnement : refusés explicitement ══════════════════════

    /**
     * « test », « staging », « prod »… ne sont pas des environnements Nexus.
     * Les accepter créerait un environnement fantôme aux credentials toujours
     * vides — un échec silencieux. On exige une erreur explicite.
     *
     * @dataProvider invalidEnvironments
     */
    public function test_invalid_environment_is_rejected(string $environment): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderConfig::credential('stripe', 'SECRET_KEY', $environment);
    }

    /** @return array<string, list<string>> */
    public static function invalidEnvironments(): array
    {
        return [
            'test'     => ['test'],
            'staging'  => ['staging'],
            'prod'     => ['prod'],
            'live'     => ['live'],
            'vide'     => [''],
            'inconnu'  => ['développement'],
        ];
    }

    /** La casse et les espaces restent tolérés (même environnement réel). */
    public function test_environment_casing_is_normalised(): void
    {
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=' . self::PRODUCTION_SENTINEL);

        $this->assertSame(self::PRODUCTION_SENTINEL, ProviderConfig::credential('stripe', 'SECRET_KEY', 'PRODUCTION'));
        $this->assertSame(self::PRODUCTION_SENTINEL, ProviderConfig::credential('stripe', 'SECRET_KEY', ' production '));
    }

    // ══ Chemin BASE DE DONNÉES : même frontière, credentials chiffrées ═════

    /**
     * Test de sécurité réel (§10) : deux credentials chiffrées coexistent en
     * base pour le même provider. Chaque environnement ne doit voir que la
     * sienne. Les données de test sont supprimées à la fin.
     */
    public function test_database_credentials_are_isolated_per_environment(): void
    {
        $pdo    = Database::getConnection();
        $userId = $this->createTestUser($pdo);

        try {
            ProviderCredentialService::upsert(
                $pdo, $userId, 'stripe', 'sandbox',
                ['secret_key' => self::SANDBOX_SENTINEL], 'active'
            );
            ProviderCredentialService::upsert(
                $pdo, $userId, 'stripe', 'production',
                ['secret_key' => self::PRODUCTION_SENTINEL], 'active'
            );

            $sandbox    = ProviderCredentialService::resolve($pdo, $userId, 'stripe', 'sandbox');
            $production = ProviderCredentialService::resolve($pdo, $userId, 'stripe', 'production');

            $this->assertSame(self::SANDBOX_SENTINEL, $sandbox['secret_key'] ?? null);
            $this->assertSame(self::PRODUCTION_SENTINEL, $production['secret_key'] ?? null);

            $this->assertNotSame(
                self::PRODUCTION_SENTINEL,
                $sandbox['secret_key'] ?? null,
                'FUITE BASE : la credential production est résolue en sandbox.'
            );
            $this->assertNotSame(
                self::SANDBOX_SENTINEL,
                $production['secret_key'] ?? null,
                'FUITE BASE : la credential sandbox est résolue en production.'
            );

            // Supprimer un environnement ne doit pas affecter l'autre.
            ProviderCredentialService::delete($pdo, $userId, 'stripe', 'sandbox');
            $this->assertNull(ProviderCredentialService::resolve($pdo, $userId, 'stripe', 'sandbox'));
            $this->assertSame(
                self::PRODUCTION_SENTINEL,
                ProviderCredentialService::resolve($pdo, $userId, 'stripe', 'production')['secret_key'] ?? null,
                'La suppression de la sandbox ne doit pas toucher la production.'
            );
        } finally {
            $this->deleteTestUser($pdo, $userId);
        }
    }

    /** Les secrets ne doivent jamais être lisibles en clair dans MySQL. */
    public function test_database_credentials_are_encrypted_at_rest(): void
    {
        $pdo    = Database::getConnection();
        $userId = $this->createTestUser($pdo);

        try {
            ProviderCredentialService::upsert(
                $pdo, $userId, 'stripe', 'production',
                ['secret_key' => self::PRODUCTION_SENTINEL], 'active'
            );

            $stmt = $pdo->prepare(
                'SELECT credentials_enc FROM provider_credentials
                 WHERE user_id = :uid AND provider_slug = :slug AND environment = :env'
            );
            $stmt->execute(['uid' => $userId, 'slug' => 'stripe', 'env' => 'production']);
            $stored = (string) $stmt->fetchColumn();

            $this->assertNotSame('', $stored);
            $this->assertStringNotContainsString(
                self::PRODUCTION_SENTINEL,
                $stored,
                'Le secret apparaît EN CLAIR dans la colonne chiffrée.'
            );

            // Et il reste bien déchiffrable par le service légitime.
            $this->assertStringContainsString(self::PRODUCTION_SENTINEL, (string) Crypto::decrypt($stored));
        } finally {
            $this->deleteTestUser($pdo, $userId);
        }
    }

    // ── Utilitaires : jeu de données temporaire, nettoyé systématiquement ──

    private function createTestUser(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :a, :c)'
        );
        $stmt->execute([
            'n' => 'Isolation Test',
            'e' => 'isolation-' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('Nexus#2026Test', PASSWORD_BCRYPT),
            'a' => 'business',
            'c' => 'CG',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function deleteTestUser(PDO $pdo, int $userId): void
    {
        $pdo->prepare('DELETE FROM provider_credentials WHERE user_id = :uid')->execute(['uid' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :uid')->execute(['uid' => $userId]);
    }
}
