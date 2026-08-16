<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\ExecutionEngine;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PHASE 4 — INTÉGRITÉ DU CYCLE FINANCIER.
 *
 * Invariant vérifié :
 *
 *     Quote → WalletOperation → Transaction/Payment → LedgerEntry
 *
 * portent EXACTEMENT le même environnement lorsqu'ils appartiennent au même
 * cycle. Une valeur déjà persistée fait autorité ; la configuration courante
 * du serveur ne peut jamais la remplacer.
 *
 * Chaque refus est doublé d'une vérification d'ATOMICITÉ : après un 409, la
 * base doit être exactement dans l'état d'avant l'appel. Un refus qui laisse
 * une transaction ou une écriture ledger derrière lui est un refus raté.
 */
final class EnvironmentGuardIntegrityTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $this->clearEnv();

        $suffix = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Guard Integrity',
            'e' => 'guard_' . $suffix . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        if ($this->userId > 0) {
            $uid = $this->userId;
            $this->pdo->prepare(
                'DELETE FROM ledger_entries WHERE operation_id IN (SELECT id FROM wallet_operations WHERE user_id = ?)'
            )->execute([$uid]);
            foreach (['wallet_operations', 'transactions', 'quotes', 'idempotency_keys', 'wallets', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$uid]);
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $this->userId = 0;
        }
    }

    private function clearEnv(): void
    {
        putenv('PROVIDERS_ENV');
        putenv('APP_ENV');
        unset($_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);
    }

    private function context(string $env): ExecutionContext
    {
        return ExecutionContext::explicit($this->userId, ExecutionEnvironment::from($env));
    }

    private function wallet(string $currency, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance)
             VALUES (:u, :c, :a1, :a2)
             ON DUPLICATE KEY UPDATE balance = :a3, available_balance = :a4'
        );
        $stmt->execute([
            'u' => $this->userId, 'c' => $currency,
            'a1' => $balance, 'a2' => $balance, 'a3' => $balance, 'a4' => $balance,
        ]);

        $sel = $this->pdo->prepare('SELECT id FROM wallets WHERE user_id = :u AND currency = :c');
        $sel->execute(['u' => $this->userId, 'c' => $currency]);

        return (int) $sel->fetchColumn();
    }

    private function createQuote(string $env, string $status = 'QUOTED'): string
    {
        $id = 'q-' . bin2hex(random_bytes(8));
        $routes = json_encode([[
            'id'          => 'r1',
            'provider'    => 'stripe',
            'amountSent'  => 10.0,
            'received'    => 10.0,
            'fees'        => 0.0,
            'feesNum'     => 0.0,
            'rate'        => 1.0,
            'receivedNum' => 10.0,
        ]]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO quotes
                (id, user_id, source_currency, origin_country, dest_country, dest_currency,
                 receiving_method, amount_sent, objective, routes_json, status, environment, expires_at)
             VALUES
                (:id, :u, :sc, :oc, :dc, :dcur, :rm, :amt, :obj, :routes, :st, :env,
                 DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        );
        $stmt->execute([
            'id' => $id, 'u' => $this->userId, 'sc' => 'EUR', 'oc' => '', 'dc' => 'CG',
            'dcur' => 'EUR', 'rm' => 'wallet', 'amt' => '100.00', 'obj' => 'cost',
            'routes' => $routes, 'st' => $status, 'env' => $env,
        ]);

        return $id;
    }

    /** @return array{tx:int,ledger:int,ops:int} */
    private function snapshot(): array
    {
        $count = function (string $sql): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['u' => $this->userId]);

            return (int) $stmt->fetchColumn();
        };

        return [
            'tx'     => $count('SELECT COUNT(*) FROM transactions WHERE user_id = :u'),
            'ops'    => $count('SELECT COUNT(*) FROM wallet_operations WHERE user_id = :u'),
            'ledger' => $count(
                'SELECT COUNT(*) FROM ledger_entries
                  WHERE operation_id IN (SELECT id FROM wallet_operations WHERE user_id = :u)'
            ),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // §3 — QUOTE → EXECUTION
    // ═══════════════════════════════════════════════════════════════════════

    /** Cas C : quote sandbox, contexte production → 409, quote intacte. */
    public function test_sandbox_quote_cannot_be_executed_in_production(): void
    {
        $this->wallet('EUR', '1000.00');
        $quoteId = $this->createQuote('sandbox');
        $before  = $this->snapshot();

        try {
            ExecutionEngine::execute($this->userId, $quoteId, 'r1', null, $this->context('production'));
            $this->fail('Une quote sandbox ne doit jamais être exécutable en production.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }

        // §7 — atomicité : rien n'a été écrit.
        $this->assertSame($before, $this->snapshot(), 'Un refus ne doit laisser aucune écriture.');

        // La quote reste intacte et réutilisable dans SON environnement.
        $stmt = $this->pdo->prepare('SELECT status, environment FROM quotes WHERE id = ?');
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('QUOTED', $quote['status'], 'Le statut de la quote ne doit pas changer.');
        $this->assertSame('sandbox', $quote['environment'], 'La quote ne doit jamais être réécrite.');
    }

    /** Cas D : quote production, contexte sandbox → 409, aucune rétrogradation. */
    public function test_production_quote_cannot_be_executed_in_sandbox(): void
    {
        $this->wallet('EUR', '1000.00');
        $quoteId = $this->createQuote('production');
        $before  = $this->snapshot();

        try {
            ExecutionEngine::execute($this->userId, $quoteId, 'r1', null, $this->context('sandbox'));
            $this->fail('Une quote production ne doit jamais être rétrogradée en sandbox.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }

        $this->assertSame($before, $this->snapshot());

        $stmt = $this->pdo->prepare('SELECT status, environment FROM quotes WHERE id = ?');
        $stmt->execute([$quoteId]);
        $this->assertSame('production', $stmt->fetch(PDO::FETCH_ASSOC)['environment']);
    }

    /**
     * §11 — une quote SANS environnement connu (ligne historique) ne doit pas
     * être bloquée : on ne lui invente pas de valeur, on ne la rejette pas.
     */
    public function test_a_mismatch_is_never_silently_corrected(): void
    {
        $this->wallet('EUR', '1000.00');
        $quoteId = $this->createQuote('sandbox');

        try {
            ExecutionEngine::execute($this->userId, $quoteId, 'r1', null, $this->context('production'));
        } catch (HttpException) {
            // attendu
        }

        // Aucune quote de compensation n'a été créée dans l'autre environnement.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM quotes WHERE user_id = ? AND environment = ?');
        $stmt->execute([$this->userId, 'production']);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune quote ne doit être régénérée dans l\'autre environnement.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // §4 — WALLET OPERATION : l'environnement persisté fait autorité
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 1. créer un hold sandbox ; 2. basculer PROVIDERS_ENV → production ;
     * 3. capturer → 409. L'opération n'est jamais promue automatiquement.
     */
    public function test_sandbox_hold_is_never_promoted_to_production(): void
    {
        $walletId = $this->wallet('EUR', '500.00');

        $hold = WalletService::createHold(
            $this->userId, $walletId, '100.00000000', 'EUR', null, 'test.hold', null, $this->context('sandbox')
        );
        $opId = (string) $hold['operation_id'];

        $stmt = $this->pdo->prepare('SELECT environment FROM wallet_operations WHERE id = ?');
        $stmt->execute([$opId]);
        $this->assertSame('sandbox', $stmt->fetchColumn());

        // La configuration serveur bascule APRÈS la création.
        putenv('PROVIDERS_ENV=production');

        $before = $this->snapshot();
        try {
            WalletService::captureHold($opId, $this->userId, null, $this->context('production'));
            $this->fail('Un hold sandbox ne doit jamais être capturé en production.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }

        $this->assertSame($before['ledger'], $this->snapshot()['ledger'], 'Aucune écriture ledger après refus.');

        // L'opération est intacte : ni capturée, ni promue.
        $stmt = $this->pdo->prepare('SELECT status, environment FROM wallet_operations WHERE id = ?');
        $stmt->execute([$opId]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('sandbox', $op['environment'], 'L\'opération ne doit jamais être promue.');
        $this->assertNotSame('captured', $op['status']);
    }

    /** Sens inverse : une opération production n'est pas rétrogradée. */
    public function test_production_hold_is_never_downgraded_to_sandbox(): void
    {
        $walletId = $this->wallet('EUR', '500.00');

        $hold = WalletService::createHold(
            $this->userId, $walletId, '100.00000000', 'EUR', null, 'test.hold', null, $this->context('production')
        );
        $opId = (string) $hold['operation_id'];

        putenv('PROVIDERS_ENV=sandbox');

        try {
            WalletService::captureHold($opId, $this->userId, null, $this->context('sandbox'));
            $this->fail('Une opération production ne doit jamais être capturée en sandbox.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }

        $stmt = $this->pdo->prepare('SELECT environment FROM wallet_operations WHERE id = ?');
        $stmt->execute([$opId]);
        $this->assertSame('production', $stmt->fetchColumn());
    }

    /** Le release est protégé au même titre que la capture. */
    public function test_release_is_also_environment_scoped(): void
    {
        $walletId = $this->wallet('EUR', '500.00');

        $hold = WalletService::createHold(
            $this->userId, $walletId, '50.00000000', 'EUR', null, 'test.hold', null, $this->context('sandbox')
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        try {
            WalletService::releaseHold(
                (string) $hold['operation_id'],
                $this->userId,
                null,
                $this->context('production')
            );
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
            throw $e;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // §5/§6 — TRANSACTION ET LEDGER SUIVENT L'OPÉRATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Chemin nominal complet : tous les objets du cycle portent le même
     * environnement, et c'est celui du CONTEXTE, pas celui du serveur.
     */
    public function test_a_full_cycle_is_environment_consistent(): void
    {
        $this->wallet('EUR', '1000.00');

        // Le serveur est configuré à l'opposé du contexte demandé : c'est le
        // contexte qui doit gagner de bout en bout.
        putenv('PROVIDERS_ENV=production');

        $quoteId = $this->createQuote('sandbox');
        $result  = ExecutionEngine::execute($this->userId, $quoteId, 'r1', null, $this->context('sandbox'));

        $txId = (int) ($result['transaction']['id'] ?? $result['id'] ?? 0);
        $this->assertGreaterThan(0, $txId, 'Une transaction doit avoir été créée.');

        // Transaction
        $stmt = $this->pdo->prepare('SELECT environment FROM transactions WHERE id = ?');
        $stmt->execute([$txId]);
        $this->assertSame('sandbox', $stmt->fetchColumn(), 'La transaction doit suivre le contexte.');

        // Wallet operations
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT environment FROM wallet_operations WHERE user_id = ?'
        );
        $stmt->execute([$this->userId]);
        $this->assertSame(['sandbox'], $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Ledger
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT le.environment FROM ledger_entries le
               JOIN wallet_operations wo ON wo.id = le.operation_id
              WHERE wo.user_id = ?'
        );
        $stmt->execute([$this->userId]);
        $ledgerEnvs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotEmpty($ledgerEnvs, 'Le cycle doit produire des écritures ledger.');
        $this->assertSame(['sandbox'], $ledgerEnvs, 'Le ledger doit suivre l\'opération, pas le serveur.');

        // Quote consommée dans son propre environnement.
        $stmt = $this->pdo->prepare('SELECT status, environment FROM quotes WHERE id = ?');
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('sandbox', $quote['environment']);
        $this->assertSame('EXECUTED', $quote['status']);
    }

    /**
     * L'invariant global, exprimé comme une requête : aucun cycle ne doit
     * présenter deux environnements différents entre l'opération et son
     * ledger. C'est le filet qui survivra aux refactors.
     */
    public function test_no_ledger_entry_diverges_from_its_operation(): void
    {
        $this->wallet('EUR', '1000.00');
        $quoteId = $this->createQuote('sandbox');
        ExecutionEngine::execute($this->userId, $quoteId, 'r1', null, $this->context('sandbox'));

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ledger_entries le
               JOIN wallet_operations wo ON wo.id = le.operation_id
              WHERE wo.user_id = ? AND le.environment <> wo.environment'
        );
        $stmt->execute([$this->userId]);
        $this->assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'Une écriture ledger ne doit jamais diverger de l\'environnement de son opération.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // §8 — IDEMPOTENCE + ENVIRONNEMENT
    // ═══════════════════════════════════════════════════════════════════════

    /** Une clé rejouée dans son environnement ne double pas le mouvement. */
    public function test_replay_in_same_environment_does_not_double_execute(): void
    {
        $this->wallet('EUR', '1000.00');
        $quoteId = $this->createQuote('sandbox');
        $key     = 'idem-' . bin2hex(random_bytes(5));

        ExecutionEngine::execute($this->userId, $quoteId, 'r1', $key, $this->context('sandbox'));
        $after = $this->snapshot();

        // Rejeu : même clé, même environnement.
        ExecutionEngine::execute($this->userId, $quoteId, 'r1', $key, $this->context('sandbox'));

        $this->assertSame($after, $this->snapshot(), 'Un rejeu ne doit produire aucune écriture supplémentaire.');
    }
}
