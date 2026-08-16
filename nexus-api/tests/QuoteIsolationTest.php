<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\ExecutionEngine;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 4 — INTÉGRITÉ DES QUOTES (phase 5 de la feuille de route).
 *
 * Une quote porte un prix, un provider et un environnement. Si elle pouvait
 * être exécutée par un autre compte, rejouée, ou consommée après expiration,
 * elle deviendrait un droit de tirage transférable.
 *
 * Ces tests verrouillent les refus attendus et — tout aussi important — le
 * fait que le chemin nominal reste ouvert.
 */
final class QuoteIsolationTest extends TestCase
{
    private PDO $pdo;
    private int $ownerId = 0;
    private int $strangerId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        putenv('PROVIDERS_ENV');

        $this->ownerId    = $this->createUser('owner');
        $this->strangerId = $this->createUser('stranger');
        $this->fund($this->ownerId, 'EUR', '1000.00');
    }

    protected function tearDown(): void
    {
        putenv('PROVIDERS_ENV');
        foreach ([$this->ownerId, $this->strangerId] as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $this->pdo->prepare(
                'DELETE FROM ledger_entries WHERE operation_id IN (SELECT id FROM wallet_operations WHERE user_id = ?)'
            )->execute([$uid]);
            foreach (['wallet_operations', 'transactions', 'quotes', 'idempotency_keys', 'wallets', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$uid]);
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        $this->ownerId = $this->strangerId = 0;
    }

    private function createUser(string $tag): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => ucfirst($tag),
            'e' => $tag . '_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function fund(int $userId, string $currency, string $balance): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance) VALUES (:u, :c, :a1, :a2)'
        );
        $stmt->execute(['u' => $userId, 'c' => $currency, 'a1' => $balance, 'a2' => $balance]);
    }

    private function context(int $userId, string $env = 'sandbox'): ExecutionContext
    {
        return ExecutionContext::explicit($userId, ExecutionEnvironment::from($env));
    }

    private function createQuote(
        int $userId,
        string $env = 'sandbox',
        string $status = 'QUOTED',
        string $expiresIn = '+1 HOUR'
    ): string {
        $id = 'q-' . bin2hex(random_bytes(8));
        $routes = json_encode([[
            'id' => 'r1', 'provider' => 'stripe', 'amountSent' => 10.0, 'received' => 10.0,
            'fees' => 0.0, 'feesNum' => 0.0, 'rate' => 1.0, 'receivedNum' => 10.0,
        ]]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO quotes
                (id, user_id, source_currency, origin_country, dest_country, dest_currency,
                 receiving_method, amount_sent, objective, routes_json, status, environment, expires_at)
             VALUES
                (:id, :u, :sc, :oc, :dc, :dcur, :rm, :amt, :obj, :routes, :st, :env,
                 DATE_ADD(NOW(), INTERVAL ' . $expiresIn . '))'
        );
        $stmt->execute([
            'id' => $id, 'u' => $userId, 'sc' => 'EUR', 'oc' => '', 'dc' => 'CG', 'dcur' => 'EUR',
            'rm' => 'wallet', 'amt' => '100.00', 'obj' => 'cost',
            'routes' => $routes, 'st' => $status, 'env' => $env,
        ]);

        return $id;
    }

    private function countTransactions(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    // ══ 1. La quote d'autrui est invisible ═════════════════════════════════

    /**
     * Un étranger ne doit ni exécuter, ni même distinguer l'existence de la
     * quote : 404, jamais 403 — sinon l'endpoint devient un oracle
     * d'énumération des quotes existantes.
     */
    public function test_a_quote_cannot_be_executed_by_another_account(): void
    {
        $quoteId = $this->createQuote($this->ownerId);
        $this->fund($this->strangerId, 'EUR', '1000.00');

        try {
            ExecutionEngine::execute($this->strangerId, $quoteId, 'r1', null, $this->context($this->strangerId));
            $this->fail('La quote d\'un autre compte ne doit jamais être exécutable.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->statusCode());
            $this->assertSame('QUOTE_NOT_FOUND', $e->errorCode());
        }

        $this->assertSame(0, $this->countTransactions($this->strangerId));
        $this->assertSame(0, $this->countTransactions($this->ownerId));

        // La quote du propriétaire est intacte.
        $stmt = $this->pdo->prepare('SELECT status FROM quotes WHERE id = ?');
        $stmt->execute([$quoteId]);
        $this->assertSame('QUOTED', (string) $stmt->fetchColumn());
    }

    // ══ 2. Rejeu ══════════════════════════════════════════════════════════

    public function test_an_executed_quote_cannot_be_replayed(): void
    {
        $quoteId = $this->createQuote($this->ownerId);

        ExecutionEngine::execute($this->ownerId, $quoteId, 'r1', null, $this->context($this->ownerId));
        $after = $this->countTransactions($this->ownerId);
        $this->assertSame(1, $after, 'La première exécution doit produire une transaction.');

        try {
            ExecutionEngine::execute($this->ownerId, $quoteId, 'r1', null, $this->context($this->ownerId));
            $this->fail('Une quote déjà exécutée ne doit pas être rejouable.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('QUOTE_ALREADY_EXECUTED', $e->errorCode());
        }

        $this->assertSame($after, $this->countTransactions($this->ownerId), 'Aucune seconde transaction.');
    }

    // ══ 3. Expiration ══════════════════════════════════════════════════════

    public function test_an_expired_quote_is_refused(): void
    {
        $quoteId = $this->createQuote($this->ownerId, 'sandbox', 'QUOTED', '-1 HOUR');

        try {
            ExecutionEngine::execute($this->ownerId, $quoteId, 'r1', null, $this->context($this->ownerId));
            $this->fail('Une quote expirée ne doit pas être exécutable.');
        } catch (HttpException $e) {
            $this->assertSame(410, $e->statusCode());
            $this->assertSame('QUOTE_EXPIRED', $e->errorCode());
        }

        $this->assertSame(0, $this->countTransactions($this->ownerId));
    }

    // ══ 4. Statuts non exécutables ═════════════════════════════════════════

    public function test_a_cancelled_quote_is_refused(): void
    {
        $quoteId = $this->createQuote($this->ownerId, 'sandbox', 'EXPIRED');

        try {
            ExecutionEngine::execute($this->ownerId, $quoteId, 'r1', null, $this->context($this->ownerId));
            $this->fail('Une quote non exécutable ne doit pas passer.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('QUOTE_NOT_EXECUTABLE', $e->errorCode());
        }

        $this->assertSame(0, $this->countTransactions($this->ownerId));
    }

    // ══ 5. Route inexistante ═══════════════════════════════════════════════

    /**
     * La route est choisie par le client : une route absente de la quote ne
     * doit pas permettre d'exécuter à des conditions non cotées.
     */
    public function test_a_route_absent_from_the_quote_is_refused(): void
    {
        $quoteId = $this->createQuote($this->ownerId);

        try {
            ExecutionEngine::execute($this->ownerId, $quoteId, 'route-inventee', null, $this->context($this->ownerId));
            $this->fail('Une route absente de la quote ne doit pas être exécutable.');
        } catch (HttpException $e) {
            // 422 : la route demandée n'existe pas dans cette quote.
            $this->assertContains($e->statusCode(), [400, 404, 409, 422]);
        }

        $this->assertSame(0, $this->countTransactions($this->ownerId));
    }

    // ══ 6. Le chemin nominal reste ouvert ══════════════════════════════════

    public function test_the_owner_can_execute_their_own_quote(): void
    {
        $quoteId = $this->createQuote($this->ownerId);

        ExecutionEngine::execute($this->ownerId, $quoteId, 'r1', null, $this->context($this->ownerId));

        $stmt = $this->pdo->prepare('SELECT status FROM quotes WHERE id = ?');
        $stmt->execute([$quoteId]);
        $this->assertSame('EXECUTED', (string) $stmt->fetchColumn());
        $this->assertSame(1, $this->countTransactions($this->ownerId));
    }
}
