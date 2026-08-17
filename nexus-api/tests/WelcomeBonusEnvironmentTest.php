<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\LedgerService;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Le bonus de bienvenue ne doit jamais être écrit en production (boucle 12).
 *
 * CE QUI AVAIT ÉTÉ MANQUÉ
 * ───────────────────────
 * La boucle 17 avait corrigé `AuthController::seedDemoTransactions()`, qui
 * insérait ses transactions de démonstration sans colonne `environment`.
 * Mais l'inscription crédite AUSSI six wallets de bienvenue, et ce chemin-là
 * passe par `LedgerService::credit()` — pas par un INSERT direct. Il avait
 * échappé à la correction.
 *
 * `credit()` accepte un ExecutionContext optionnel ; sans lui, il retombe sur
 * `ProviderConfig::defaultEnvironment()`. Sur un déploiement dont
 * `PROVIDERS_ENV=production`, un bonus fictif de 2500 EUR était donc écrit au
 * ledger comme de l'ARGENT RÉEL — vérifié en base : six `wallet_operations`
 * de type `welcome_bonus` marquées `production` après une simple inscription.
 *
 * Le défaut SQL sûr (migration 0.19) ne protège pas ici : la valeur n'est pas
 * omise, elle est fournie — et elle est fausse. D'où ce test de comportement,
 * qui exerce le vrai chemin du ledger plutôt que de relire le source.
 */
final class WelcomeBonusEnvironmentTest extends TestCase
{
    private PDO $pdo;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $walletIds = [];

    /** @var string|false */
    private $savedProvidersEnv;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        // Le cœur du test : on force le défaut serveur sur « production ».
        // Un crédit de démonstration ne doit pas s'y conformer.
        $this->savedProvidersEnv = getenv('PROVIDERS_ENV');
        putenv('PROVIDERS_ENV=production');

        $this->userIds   = [];
        $this->walletIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->savedProvidersEnv === false) {
            putenv('PROVIDERS_ENV');
        } else {
            putenv('PROVIDERS_ENV=' . $this->savedProvidersEnv);
        }

        try {
            // `ledger_entries` porte wallet_id, pas user_id : les écritures
            // se suppriment par wallet, avant les wallets eux-mêmes.
            if ($this->walletIds !== []) {
                $ph = implode(',', array_fill(0, count($this->walletIds), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE wallet_id IN ($ph)")
                    ->execute($this->walletIds);
            }
            foreach ($this->userIds as $uid) {
                $this->pdo->prepare('DELETE FROM wallet_operations WHERE user_id = ?')->execute([$uid]);
            }
            if ($this->walletIds !== []) {
                $ph = implode(',', array_fill(0, count($this->walletIds), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")->execute($this->walletIds);
            }
            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[WelcomeBonusEnvironmentTest] ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Un crédit assorti d'un contexte sandbox reste sandbox, même quand le
     * serveur est par défaut en production.
     */
    public function test_un_credit_de_demo_reste_sandbox_malgre_un_defaut_production(): void
    {
        $userId = $this->createUser();
        $wallet = WalletService::ensureWallet($userId, 'EUR');
        $this->walletIds[] = (int) $wallet['id'];

        $operationId = LedgerService::credit(
            $userId,
            (int) $wallet['id'],
            '2500.00',
            'EUR',
            'welcome_bonus',
            'welcome_bonus:test:' . $userId . ':EUR',
            'Bonus de bienvenue à l\'inscription',
            ['source' => 'registration_seed'],
            ExecutionContext::explicit(
                actorUserId: $userId,
                environment: ExecutionEnvironment::SANDBOX,
                accountType: 'personal'
            )
        );

        $stmt = $this->pdo->prepare('SELECT environment FROM wallet_operations WHERE id = ?');
        $stmt->execute([$operationId]);

        self::assertSame(
            'sandbox',
            $stmt->fetchColumn(),
            'Un bonus de démonstration ne doit jamais être écrit en production.'
        );
    }

    /**
     * Le même crédit SANS contexte hérite du défaut serveur : c'est
     * exactement le piège. Ce test documente le comportement de la brique
     * bas niveau et justifie pourquoi l'appelant DOIT fournir un contexte.
     */
    public function test_sans_contexte_le_credit_herite_du_defaut_serveur(): void
    {
        $userId = $this->createUser();
        $wallet = WalletService::ensureWallet($userId, 'EUR');
        $this->walletIds[] = (int) $wallet['id'];

        $operationId = LedgerService::credit(
            $userId,
            (int) $wallet['id'],
            '10.00',
            'EUR',
            'deposit',
            'deposit:test:' . $userId . ':EUR'
        );

        $stmt = $this->pdo->prepare('SELECT environment FROM wallet_operations WHERE id = ?');
        $stmt->execute([$operationId]);

        self::assertSame(
            'production',
            $stmt->fetchColumn(),
            'Sans contexte, credit() suit PROVIDERS_ENV : tout appelant de démonstration '
            . 'doit donc fournir un ExecutionContext explicite.'
        );
    }

    /**
     * L'inscription ne crédite PLUS aucun bonus de bienvenue.
     *
     * Le bonus fictif de 2500 EUR a été supprimé avec le mode démo : un
     * solde créé sans argent réel serait une fausse donnée financière (§9
     * du brief), même marqué sandbox. L'inscription ne crée donc plus
     * aucune écriture ledger.
     */
    public function test_l_inscription_ne_credite_plus_aucun_bonus(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
        self::assertIsString($source);

        self::assertStringNotContainsString(
            'welcome_bonus',
            $source,
            'Le bonus de bienvenue a été supprimé : l\'inscription ne doit plus créditer de fonds fictifs.'
        );
        self::assertStringNotContainsString(
            '2500',
            $source,
            'Aucun montant de bonus ne doit subsister dans le chemin d\'inscription.'
        );
    }

    private function createUser(): int
    {
        $suffix = uniqid('', true);
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:n, :e, :p, :t, :s, :k)'
        );
        $stmt->execute([
            'n' => 'Welcome ' . $suffix,
            'e' => 'welcome_' . $suffix . '@nexus-test.local',
            'p' => '',
            't' => 'personal',
            's' => 'ACTIVE',
            'k' => 'none',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }
}
