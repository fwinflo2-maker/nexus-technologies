<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\EnvironmentGuard;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderConfig;
use Nexus\Services\ExecutionEngine;
use Nexus\Services\LedgerService;
use Nexus\Services\WalletService;
use Nexus\Tests\Fixtures\UsesScriptedProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PROPAGATION DE L'ENVIRONNEMENT SUR TOUT LE CYCLE FINANCIER.
 *
 * Invariant démontré ici :
 *
 *     quote.environment
 *       == wallet_operation.environment
 *       == transaction.environment
 *       == ledger_entry.environment
 *
 * pour les objets d'un MÊME cycle financier.
 *
 * Et sa contrepartie négative : aucune opération ne franchit la frontière
 * d'environnement sans être explicitement rejetée.
 */
final class EnvironmentPropagationTest extends TestCase
{
    use UsesScriptedProvider;

    private PDO $pdo;
    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $this->clearEnv();
        // Chemin nominal : provider réellement configuré, API scriptée.
        $this->scriptStripe();
    }

    protected function tearDown(): void
    {
        $this->unscriptStripe();
        foreach ($this->users as $uid) {
            $this->pdo->prepare('DELETE FROM ledger_entries WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = ?)')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM wallet_operations WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM idempotency_keys WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM quotes WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        $this->users = [];
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        putenv('APP_ENV');
        putenv('PROVIDERS_ENV');
        putenv('NEXUS_PRODUCTION_ALLOWED');
        putenv('NEXUS_PRODUCTION_ALLOWED_ACCOUNTS');
    }

    private function createUser(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Propagation Test',
            'e' => 'prop_' . $suffix . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->users[] = $id;

        return $id;
    }

    private function fundWallet(int $userId, string $currency, string $amount): int
    {
        WalletService::ensureWallet($userId, $currency);
        $stmt = $this->pdo->prepare(
            'UPDATE wallets SET balance = :a1, available_balance = :a2 WHERE user_id = :u AND currency = :c'
        );
        $stmt->execute(['a1' => $amount, 'a2' => $amount, 'u' => $userId, 'c' => $currency]);

        $sel = $this->pdo->prepare('SELECT id FROM wallets WHERE user_id = :u AND currency = :c');
        $sel->execute(['u' => $userId, 'c' => $currency]);

        return (int) $sel->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function spec(): array
    {
        return [
            'source_currency' => 'EUR',
            'dest_currency'   => 'EUR',
            'amount'          => '10.00',
            'fee'             => '0.00',
            'dest_amount'     => 10.0,
            'fx_rate'         => 1.0,
            'provider'        => 'stripe',
            'route_id'        => 'r-prop',
            'destination'     => 'test',
            'label'           => 'Propagation',
            'type'            => 'send',
        ];
    }

    // ══ §16 — Propagation end-to-end, scénario SANDBOX ═════════════════════

    public function test_end_to_end_propagation_sandbox(): void
    {
        $userId = $this->createUser();
        $this->fundWallet($userId, 'EUR', '1000.00');

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);
        $tx = ExecutionEngine::executeTransfer(
            $userId,
            $this->spec(),
            'prop-sandbox-' . bin2hex(random_bytes(4)),
            $context
        );

        $this->assertPropagation((int) $tx['id'], $userId, 'sandbox');
    }

    // ══ §16 — Propagation end-to-end, scénario PRODUCTION ══════════════════

    /**
     * Aucun mouvement réel : les soldes sont fictifs et aucun adaptateur
     * provider n'est appelé (opérations toujours non implémentées).
     */
    public function test_end_to_end_propagation_production(): void
    {
        $userId = $this->createUser();
        $this->fundWallet($userId, 'EUR', '1000.00');

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::PRODUCTION);
        $tx = ExecutionEngine::executeTransfer(
            $userId,
            $this->spec(),
            'prop-prod-' . bin2hex(random_bytes(4)),
            $context
        );

        $this->assertPropagation((int) $tx['id'], $userId, 'production');
    }

    /**
     * §14 — invariant : tous les objets du cycle portent le même environnement.
     */
    private function assertPropagation(int $txId, int $userId, string $expected): void
    {
        $txEnv = $this->pdo->query('SELECT environment FROM transactions WHERE id = ' . $txId)->fetchColumn();
        $this->assertSame($expected, $txEnv, 'transaction.environment');

        $ops = $this->pdo->prepare('SELECT id, environment FROM wallet_operations WHERE user_id = :u');
        $ops->execute(['u' => $userId]);
        $rows = $ops->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows, 'Au moins une wallet_operation doit exister.');

        foreach ($rows as $row) {
            $this->assertSame($expected, $row['environment'], 'wallet_operation.environment');

            $led = $this->pdo->prepare('SELECT environment FROM ledger_entries WHERE operation_id = :id');
            $led->execute(['id' => $row['id']]);
            foreach ($led->fetchAll(PDO::FETCH_COLUMN) as $ledgerEnv) {
                $this->assertSame($expected, $ledgerEnv, 'ledger_entry.environment');
            }
        }

        // L'invariant complet : une seule valeur sur tout le cycle.
        $distinct = $this->pdo->prepare(
            'SELECT DISTINCT l.environment
               FROM ledger_entries l
               JOIN wallet_operations o ON o.id = l.operation_id
              WHERE o.user_id = :u'
        );
        $distinct->execute(['u' => $userId]);
        $this->assertSame([$expected], $distinct->fetchAll(PDO::FETCH_COLUMN));
    }

    // ══ §15 Test A — quote sandbox + contexte production → 409 ═════════════

    public function test_sandbox_quote_cannot_be_executed_in_production(): void
    {
        $userId  = $this->createUser();
        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::PRODUCTION);

        try {
            EnvironmentGuard::assertMatches('sandbox', $context, 'Cette quote');
            $this->fail('Une quote sandbox a été acceptée par une exécution production.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }
    }

    public function test_production_quote_cannot_be_executed_in_sandbox(): void
    {
        $userId  = $this->createUser();
        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);

        $this->expectException(HttpException::class);
        EnvironmentGuard::assertMatches('production', $context, 'Cette quote');
    }

    // ══ §15 Tests B & C — opération ↔ écriture liée ════════════════════════

    public function test_sandbox_operation_and_production_transaction_are_rejected(): void
    {
        try {
            EnvironmentGuard::assertSameEnvironment(
                'sandbox',
                'production',
                'l\'opération de wallet',
                'la transaction'
            );
            $this->fail('Un cycle sandbox → production a été accepté.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }
    }

    public function test_production_payment_with_sandbox_ledger_is_rejected(): void
    {
        try {
            EnvironmentGuard::assertSameEnvironment(
                'production',
                'sandbox',
                'le paiement',
                'l\'écriture ledger'
            );
            $this->fail('Une écriture ledger sandbox a été acceptée pour un paiement production.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }
    }

    // ══ §15 Test D — la configuration serveur ne réécrit pas l'histoire ════

    public function test_production_transaction_survives_server_switch_to_sandbox(): void
    {
        $userId = $this->createUser();
        $this->fundWallet($userId, 'EUR', '1000.00');

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::PRODUCTION);
        $tx = ExecutionEngine::executeTransfer(
            $userId,
            $this->spec(),
            'switch-prod-' . bin2hex(random_bytes(4)),
            $context
        );

        // Le serveur bascule APRÈS coup.
        putenv('PROVIDERS_ENV=sandbox');
        $this->assertSame('sandbox', ProviderConfig::defaultEnvironment());

        $env = $this->pdo->query('SELECT environment FROM transactions WHERE id = ' . (int) $tx['id'])->fetchColumn();
        $this->assertSame('production', $env, 'Une transaction historique ne se recalcule pas.');
    }

    // ══ §15 Test E — symétrique ════════════════════════════════════════════

    public function test_sandbox_transaction_survives_server_switch_to_production(): void
    {
        $userId = $this->createUser();
        $this->fundWallet($userId, 'EUR', '1000.00');

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);
        $tx = ExecutionEngine::executeTransfer(
            $userId,
            $this->spec(),
            'switch-sbx-' . bin2hex(random_bytes(4)),
            $context
        );

        putenv('PROVIDERS_ENV=production');
        $this->assertSame('production', ProviderConfig::defaultEnvironment());

        $env = $this->pdo->query('SELECT environment FROM transactions WHERE id = ' . (int) $tx['id'])->fetchColumn();
        $this->assertSame('sandbox', $env, 'Une transaction sandbox ne devient jamais production.');
    }

    // ══ §10 — la capture d'un hold hérite de l'opération, pas du serveur ═══

    public function test_hold_capture_inherits_environment_from_the_operation(): void
    {
        $userId   = $this->createUser();
        $walletId = $this->fundWallet($userId, 'EUR', '500.00');

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);
        $hold = WalletService::createHold(
            $userId,
            $walletId,
            '25.00',
            'EUR',
            'hold-' . bin2hex(random_bytes(4)),
            'Hold sandbox',
            null,
            $context
        );

        // Bascule du serveur AVANT la capture.
        putenv('PROVIDERS_ENV=production');

        WalletService::captureHold((string) $hold['operation_id'], $userId, 'cap-' . bin2hex(random_bytes(4)));

        $stmt = $this->pdo->prepare('SELECT environment FROM ledger_entries WHERE operation_id = :id');
        $stmt->execute(['id' => (string) $hold['operation_id']]);
        $envs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotEmpty($envs, 'La capture doit produire une écriture ledger.');
        foreach ($envs as $env) {
            $this->assertSame('sandbox', $env, 'La capture hérite de l\'environnement du hold.');
        }
    }

    // ══ §22 — la clé d'idempotence ne franchit pas la frontière ════════════

    /**
     * Le scénario cassé avant cette phase : une clé consommée en sandbox
     * renvoyait sa réponse en cache à un appel de production, qui n'était donc
     * jamais exécuté.
     */
    public function test_same_idempotency_key_is_independent_per_environment(): void
    {
        $userId = $this->createUser();
        $this->fundWallet($userId, 'EUR', '1000.00');
        $key = 'shared-key-' . bin2hex(random_bytes(4));

        $sandbox = ExecutionEngine::executeTransfer(
            $userId,
            $this->spec(),
            $key,
            ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX)
        );

        $production = ExecutionEngine::executeTransfer(
            $userId,
            $this->spec(),
            $key,
            ExecutionContext::explicit($userId, ExecutionEnvironment::PRODUCTION)
        );

        $this->assertNotSame(
            (int) $sandbox['id'],
            (int) $production['id'],
            'La réponse sandbox ne doit jamais être rejouée pour un appel de production.'
        );

        $this->assertSame('sandbox', $this->txEnv((int) $sandbox['id']));
        $this->assertSame('production', $this->txEnv((int) $production['id']));
    }

    /**
     * §9 — la garde est exercée par le VRAI chemin d'exécution.
     *
     * Une quote sandbox persistée, consommée par ExecutionEngine::execute()
     * avec un contexte production, doit être rejetée en 409 — et non pas
     * exécutée, ni « corrigée » en réalignant la quote.
     */
    public function test_execute_rejects_a_quote_from_another_environment(): void
    {
        $userId = $this->createUser();
        $this->fundWallet($userId, 'EUR', '1000.00');

        $quoteId = 'QT-' . bin2hex(random_bytes(6));
        $routeId = 'r1';
        $routes  = [[
            'id'          => $routeId,
            'provider'    => 'stripe',
            'amountSent'  => 10.0,
            'received'    => 10.0,
            'fees'        => 0.0,
            'feesNum'     => 0.0,
            'rate'        => 1.0,
            'receivedNum' => 10.0,
        ]];

        // Quote créée en SANDBOX.
        $stmt = $this->pdo->prepare(
            'INSERT INTO quotes
                (id, user_id, source_currency, origin_country, dest_country, dest_currency,
                 receiving_method, amount_sent, objective, routes_json, status, environment, expires_at)
             VALUES
                (:id, :uid, :sc, :oc, :dc, :dcur, :m, :amt, :obj, :routes, :st, :env, :exp)'
        );
        $stmt->execute([
            'id'     => $quoteId,
            'uid'    => $userId,
            'sc'     => 'EUR',
            'oc'     => 'CG',
            'dc'     => 'CG',
            'dcur'   => 'EUR',
            'm'      => 'mobile_money',
            'amt'    => '10.00',
            'obj'    => 'optimized',
            'routes' => json_encode($routes),
            'st'     => 'QUOTED',
            'env'    => 'sandbox',
            'exp'    => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        // … mais exécutée avec un contexte PRODUCTION.
        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::PRODUCTION);

        try {
            ExecutionEngine::execute($userId, $quoteId, $routeId, null, $context);
            $this->fail('Une quote sandbox a été exécutée en production.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }

        // Aucune correction silencieuse : la quote reste sandbox et non exécutée.
        $row = $this->pdo->query(
            "SELECT environment, status FROM quotes WHERE id = " . $this->pdo->quote($quoteId)
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('sandbox', $row['environment'], 'La quote ne doit pas être réalignée.');
        $this->assertSame('QUOTED', $row['status'], 'La quote ne doit pas être marquée exécutée.');

        // Et aucune transaction n'a été créée.
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u');
        $count->execute(['u' => $userId]);
        $this->assertSame(0, (int) $count->fetchColumn(), 'Aucun mouvement ne doit avoir eu lieu.');
    }

    private function txEnv(int $id): string
    {
        return (string) $this->pdo->query('SELECT environment FROM transactions WHERE id = ' . $id)->fetchColumn();
    }

    // ══ §13 — une écriture ledger ne prend jamais le défaut du serveur ═════

    public function test_ledger_entry_uses_context_not_server_default(): void
    {
        $userId   = $this->createUser();
        $walletId = $this->fundWallet($userId, 'EUR', '100.00');

        // Le serveur est en production…
        putenv('PROVIDERS_ENV=production');

        // … mais l'opération est explicitement sandbox.
        LedgerService::credit(
            $userId,
            $walletId,
            '5.00',
            'EUR',
            'deposit',
            'credit-' . bin2hex(random_bytes(4)),
            'Crédit sandbox',
            null,
            ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX)
        );

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT l.environment
               FROM ledger_entries l
               JOIN wallet_operations o ON o.id = l.operation_id
              WHERE o.user_id = :u'
        );
        $stmt->execute(['u' => $userId]);

        $this->assertSame(['sandbox'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
