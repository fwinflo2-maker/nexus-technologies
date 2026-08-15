<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 15 — L'INVARIANT INTER-TABLES EST DÉSORMAIS PORTÉ PAR LA BASE.
 *
 * LE DÉFAUT
 * ─────────
 * `ledger_entries.environment` et `wallet_operations.environment` existaient
 * tous les deux, mais rien ne les liait. Vérifié par insertion réelle avant
 * correctif : la base acceptait une écriture comptable de PRODUCTION
 * rattachée à une opération de SANDBOX, et la persistait.
 *
 * Les garanties applicatives existaient. Elles ne protégeaient pas contre un
 * script de maintenance, une correction manuelle en base, ou une future
 * méthode oublieuse — c'est-à-dire précisément les situations où l'on
 * découvre les incohérences comptables.
 *
 * LA CORRECTION (migration 0.18)
 * ──────────────────────────────
 *     UNIQUE (id, environment) sur wallet_operations
 *     FK ledger_entries (operation_id, environment)
 *        → wallet_operations (id, environment)
 *
 * L'état interdit devient INREPRÉSENTABLE. C'est la seule catégorie de
 * garantie qui survit aux erreurs humaines.
 *
 * POURQUOI CE TEST EXISTE ALORS QUE LA BASE GARANTIT DÉJÀ
 * ───────────────────────────────────────────────────────
 * Parce qu'une contrainte peut disparaître : migration incomplète, base
 * reconstruite depuis un dump partiel, ou garde de migration codée sur le
 * mauvais schéma. C'est exactement ce qui s'est produit pendant cette
 * boucle : la migration s'appliquait à `nexus` mais pas à `nexus_test`, où
 * ses gardes `TABLE_SCHEMA = 'nexus'` la déclaraient déjà installée. Elle
 * annonçait 12 instructions exécutées et ne protégeait rien.
 *
 * Ce test échoue si la contrainte n'est pas là où les tests s'exécutent.
 */
final class LedgerEnvironmentInvariantTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;
    private int $walletId = 0;
    private string $operationId = '';

    protected function setUp(): void
    {
        $this->pdo         = Database::getConnection();
        $this->operationId = 'op-inv-' . bin2hex(random_bytes(6));

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, status)
             VALUES (:n, :e, :p, :t, :c, :s)'
        );
        $stmt->execute([
            'n' => 'Invariant Probe',
            'e' => 'inv_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (:u, :c, :b, :a, 0)'
        );
        $stmt->execute(['u' => $this->userId, 'c' => 'EUR', 'b' => '100.00', 'a' => '100.00']);
        $this->walletId = (int) $this->pdo->lastInsertId();

        // Une opération SANDBOX.
        $stmt = $this->pdo->prepare(
            "INSERT INTO wallet_operations
                (id, user_id, type, status, source_wallet_id, source_currency,
                 source_amount, idempotency_key, environment)
             VALUES (:id, :u, 'hold', 'pending', :w, 'EUR', 10.00, :k, 'sandbox')"
        );
        $stmt->execute([
            'id' => $this->operationId,
            'u'  => $this->userId,
            'w'  => $this->walletId,
            'k'  => 'idem-' . bin2hex(random_bytes(6)),
        ]);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM ledger_entries WHERE operation_id = ?')->execute([$this->operationId]);
        $this->pdo->prepare('DELETE FROM wallet_operations WHERE id = ?')->execute([$this->operationId]);
        $this->pdo->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
    }

    private function insertLedgerEntry(string $environment): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ledger_entries
                (operation_id, sequence, entry_type, wallet_id, wallet_currency,
                 environment, amount, balance_after)
             VALUES (:op, 1, 'debit', :w, 'EUR', :env, 10.00, 90.00)"
        );
        $stmt->execute([
            'op'  => $this->operationId,
            'w'   => $this->walletId,
            'env' => $environment,
        ]);
    }

    /**
     * LE TEST CENTRAL — la base doit REFUSER l'écriture divergente.
     *
     * Une écriture comptable d'argent réel rattachée à une opération de test
     * fausse toute reconstruction du grand livre : les totaux par
     * environnement ne correspondent plus aux opérations qui les ont produits.
     */
    public function test_the_database_refuses_a_ledger_entry_from_another_environment(): void
    {
        $refused = false;

        try {
            $this->insertLedgerEntry('production'); // l'opération est en sandbox
        } catch (PDOException $e) {
            $refused = true;
            $this->assertSame(
                '23000',
                $e->getCode(),
                'Le refus doit venir d\'une violation d\'intégrité référentielle.'
            );
        }

        $this->assertTrue(
            $refused,
            'La base doit refuser une écriture comptable dont l\'environnement diverge de son opération.'
        );

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE operation_id = ?');
        $stmt->execute([$this->operationId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune ligne ne doit subsister.');
    }

    /** Le chemin légitime reste possible : même environnement, écriture acceptée. */
    public function test_a_ledger_entry_in_the_same_environment_is_accepted(): void
    {
        $this->insertLedgerEntry('sandbox');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE operation_id = ?');
        $stmt->execute([$this->operationId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * La contrainte doit exister DANS LA BASE OÙ LES TESTS TOURNENT.
     *
     * Garde-fou contre le piège rencontré pendant cette boucle : une
     * migration dont les gardes interrogeaient `TABLE_SCHEMA = 'nexus'` en
     * dur s'appliquait à la base de dev et se croyait déjà installée sur
     * `nexus_test`. Les deux tests ci-dessus auraient alors validé une
     * protection absente de l'environnement de test.
     */
    public function test_the_composite_foreign_key_exists_in_the_current_schema(): void
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME   = 'fk_ledger_operation_env'"
        );

        $this->assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'fk_ledger_operation_env doit exister dans la base courante.'
        );
    }

    /**
     * L'invariant global : aucune divergence résiduelle dans la base.
     *
     * Utile après une reprise de données ou une migration : la contrainte
     * empêche les NOUVELLES divergences, ce test constate qu'il n'en reste
     * aucune ancienne.
     */
    public function test_no_divergent_ledger_entry_remains_anywhere(): void
    {
        $count = (int) $this->pdo->query(
            'SELECT COUNT(*)
               FROM ledger_entries l
               JOIN wallet_operations o ON o.id = l.operation_id
              WHERE l.environment <> o.environment'
        )->fetchColumn();

        $this->assertSame(0, $count, 'Aucune écriture comptable ne doit diverger de son opération.');
    }
}
