<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Execution\ProviderResolver;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 10 — À QUI APPARTIENT UNE CREDENTIAL PROVIDER ?
 *
 * ─── LA CONTRADICTION ─────────────────────────────────────────────────────
 * La boucle 7 a réservé l'ÉCRITURE des credentials au personnel plateforme
 * (superadmin / provider_engineer) : c'est Nexus qui contracte avec Stripe ou
 * pawaPay, pas le client.
 *
 * Mais la LECTURE est restée scopée au client :
 *
 *     ProviderResolver::hasCredentialFor()
 *         -> ProviderCredentialService::findRow($pdo, $context->subjectUserId, …)
 *
 * et la table impose `UNIQUE (user_id, provider_slug, environment)`.
 *
 * Conséquence : la credential déposée par le superadmin porte SON `user_id`.
 * Aucun client ne la voit. Chaque client devrait posséder sa propre ligne —
 * or il n'a plus le droit d'en écrire une.
 *
 * Autrement dit : après la boucle 7, plus AUCUN transfert client ne peut
 * jamais résoudre un provider. La correction d'une faille de privilège a
 * refermé le chemin d'exécution nominal.
 *
 * ─── CE QUE CES TESTS ÉTABLISSENT ─────────────────────────────────────────
 * Ils décrivent le modèle correct : une credential provider est un actif de
 * la PLATEFORME. Elle vaut pour tous les clients, dans un environnement
 * donné, sans qu'aucun client ne puisse la lire, la deviner ou la modifier.
 *
 * Ils ne testent pas un détail d'implémentation : ils verrouillent la réponse
 * à « de qui dépend la capacité d'exécuter ? ».
 */
final class CredentialOwnershipTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $userIds = [];
    private int $platformUserId = 0;
    private int $customerId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $this->platformUserId = $this->createUser('superadmin');
        $this->customerId     = $this->createUser('user');

        // Table nettoyée pour ces slugs : on ne veut pas d'interférence.
        $this->pdo->exec("DELETE FROM provider_credentials WHERE provider_slug = 'stripe'");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM provider_credentials WHERE provider_slug = 'stripe'");

        foreach ($this->userIds as $id) {
            $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        $this->userIds = [];
    }

    private function createUser(string $platformRole): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, platform_role)
             VALUES (:n, :e, :p, :t, :c, :r)'
        );
        $stmt->execute([
            'n' => 'Cred ' . $platformRole,
            'e' => 'cred_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
            'r' => $platformRole,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }

    /**
     * Dépose une credential telle que le ferait le superadmin aujourd'hui.
     */
    private function storePlatformCredential(string $environment): void
    {
        // `user_id = NULL` : credential de PLATEFORME. Elle n'appartient à
        // aucun compte, pas même à celui du superadmin qui l'a déposée —
        // `configured_by` retient l'opérateur, ce qui est une TRACE, pas un
        // titre de propriété. Rattacher la credential au superadmin la rendrait
        // invisible dès qu'un autre opérateur reprendrait le poste.
        $stmt = $this->pdo->prepare(
            'INSERT INTO provider_credentials (user_id, provider_slug, environment, credentials_enc, status, configured_by)
             VALUES (NULL, :s, :e, :c, :st, :by)
             ON DUPLICATE KEY UPDATE credentials_enc = VALUES(credentials_enc), status = VALUES(status)'
        );
        $stmt->execute([
            'by' => $this->platformUserId,
            's'  => 'stripe',
            'e'  => $environment,
            // Valeur chiffrée factice : ces tests portent sur la RÉSOLUTION,
            // jamais sur le contenu. Aucun secret réel n'est manipulé.
            'c'  => 'enc:v1:' . base64_encode('placeholder-non-secret'),
            'st' => 'active',
        ]);
    }

    private function contextFor(int $userId, ExecutionEnvironment $env): ExecutionContext
    {
        return ExecutionContext::explicit($userId, $env);
    }

    // ══ 1. LE DÉFAUT CENTRAL ══════════════════════════════════════════════

    /**
     * Une credential déposée par la plateforme doit rendre le provider
     * utilisable POUR UN CLIENT.
     *
     * C'est le chemin nominal de toute exécution : sans lui, aucun transfert
     * n'est possible, quel que soit l'état du reste du système.
     */
    public function test_a_platform_credential_makes_the_provider_usable_for_a_customer(): void
    {
        $this->storePlatformCredential('sandbox');

        $usable = ProviderResolver::hasCredentialFor(
            'stripe',
            $this->contextFor($this->customerId, ExecutionEnvironment::SANDBOX)
        );

        $this->assertTrue(
            $usable,
            'La credential appartient à la PLATEFORME : elle doit valoir pour les clients. '
            . 'Sinon, réserver l\'écriture au superadmin (boucle 7) rend tout transfert client impossible.'
        );
    }

    // ══ 2. LA SÉPARATION DES ENVIRONNEMENTS RESTE ABSOLUE ═════════════════

    /**
     * Élargir la portée d'une credential ne doit JAMAIS élargir son
     * environnement. C'est le risque direct de la correction : en cherchant
     * « une credential visible par tous », on pourrait accepter celle de
     * l'autre environnement.
     */
    public function test_a_sandbox_credential_never_satisfies_production(): void
    {
        $this->storePlatformCredential('sandbox');

        $usable = ProviderResolver::hasCredentialFor(
            'stripe',
            $this->contextFor($this->customerId, ExecutionEnvironment::PRODUCTION)
        );

        $this->assertFalse(
            $usable,
            'Une credential sandbox ne doit jamais satisfaire la production : ce serait un repli interdit.'
        );
    }

    public function test_a_production_credential_never_satisfies_sandbox(): void
    {
        $this->storePlatformCredential('production');

        $usable = ProviderResolver::hasCredentialFor(
            'stripe',
            $this->contextFor($this->customerId, ExecutionEnvironment::SANDBOX)
        );

        $this->assertFalse(
            $usable,
            'Une credential production ne doit jamais être utilisée en sandbox.'
        );
    }

    // ══ 3. ABSENCE DE CREDENTIAL = PROVIDER INUTILISABLE ══════════════════

    /**
     * Le pendant indispensable du test 1 : sans credential, le provider reste
     * inutilisable. Sans cette vérification, un correctif qui renverrait
     * toujours `true` passerait pour valide.
     */
    public function test_without_any_credential_the_provider_is_unusable(): void
    {
        $usable = ProviderResolver::hasCredentialFor(
            'stripe',
            $this->contextFor($this->customerId, ExecutionEnvironment::SANDBOX)
        );

        $this->assertFalse($usable, 'Aucune credential enregistrée : le provider ne doit pas être utilisable.');
    }

    // ══ 4. LA PORTÉE PLATEFORME N'EST PAS UNE FUITE ═══════════════════════

    /**
     * Rendre une credential valable pour tous ne doit pas la rendre LISIBLE
     * par tous. La résolution répond « utilisable / pas utilisable » — jamais
     * une valeur.
     *
     * Ce test verrouille la frontière : disponibilité partagée, secret non
     * partagé.
     */
    public function test_resolution_exposes_availability_but_never_the_value(): void
    {
        $this->storePlatformCredential('sandbox');

        $result = ProviderResolver::hasCredentialFor(
            'stripe',
            $this->contextFor($this->customerId, ExecutionEnvironment::SANDBOX)
        );

        $this->assertIsBool($result, 'La résolution ne doit renvoyer qu\'un booléen de disponibilité.');
    }
}
