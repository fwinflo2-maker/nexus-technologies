<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Models\TransferRequest;
use Nexus\Providers\ProviderConfig;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PHASE 1 — COMPLÉTUDE DU CONTEXTE D'EXÉCUTION.
 *
 * `transferMultiCurrency()` était le dernier chemin financier à fonctionner
 * avec un environnement IMPLICITE : il retombait sur la configuration du
 * serveur au moment de l'exécution.
 *
 * Ce que ces tests établissent :
 *   1. l'environnement voyage AVEC la requête (TransferRequest) ;
 *   2. une bascule de `PROVIDERS_ENV` après création n'a AUCUN effet sur une
 *      opération déjà persistée ;
 *   3. l'environnement persisté fait autorité, pas la configuration courante.
 */
final class ContextCompletenessTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $uid) {
            $this->pdo->prepare('DELETE FROM ledger_entries WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = ?)')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM wallet_operations WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM idempotency_keys WHERE user_id = ?')->execute([$uid]);
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
            'n' => 'Context Completeness',
            'e' => 'ctx_' . $suffix . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->users[] = $id;

        return $id;
    }

    private function wallet(int $userId, string $currency, string $amount): int
    {
        WalletService::ensureWallet($userId, $currency);
        $upd = $this->pdo->prepare(
            'UPDATE wallets SET balance = :a1, available_balance = :a2 WHERE user_id = :u AND currency = :c'
        );
        $upd->execute(['a1' => $amount, 'a2' => $amount, 'u' => $userId, 'c' => $currency]);

        $sel = $this->pdo->prepare('SELECT id FROM wallets WHERE user_id = :u AND currency = :c');
        $sel->execute(['u' => $userId, 'c' => $currency]);

        return (int) $sel->fetchColumn();
    }

    private function request(
        int $userId,
        int $src,
        int $dst,
        ?ExecutionContext $context,
        ?string $key = null
    ): TransferRequest {
        return new TransferRequest(
            $userId,
            $src,
            $dst,
            '10.00000000',
            'EUR',
            'USD',
            'send',
            $key ?? ('ctx-' . bin2hex(random_bytes(5))),
            'Transfert contexte',
            null,
            null,
            $context
        );
    }

    /** @return list<string> */
    private function ledgerEnvs(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT l.environment
               FROM ledger_entries l
               JOIN wallet_operations o ON o.id = l.operation_id
              WHERE o.user_id = :u'
        );
        $stmt->execute(['u' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ══ 1. L'environnement vient de la requête, pas du serveur ═════════════

    public function test_transfer_uses_request_context_not_server_configuration(): void
    {
        $userId = $this->createUser();
        $src = $this->wallet($userId, 'EUR', '500.00');
        $dst = $this->wallet($userId, 'USD', '0.00');

        // Le serveur est configuré en production…
        putenv('PROVIDERS_ENV=production');
        $this->assertSame('production', ProviderConfig::defaultEnvironment());

        // … mais la requête porte explicitement un contexte sandbox.
        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);
        WalletService::transferMultiCurrency($this->request($userId, $src, $dst, $context));

        $ops = $this->pdo->prepare('SELECT DISTINCT environment FROM wallet_operations WHERE user_id = :u');
        $ops->execute(['u' => $userId]);

        $this->assertSame(['sandbox'], $ops->fetchAll(PDO::FETCH_COLUMN), 'wallet_operation');
        $this->assertSame(['sandbox'], $this->ledgerEnvs($userId), 'ledger_entries');
    }

    // ══ 2. Une bascule postérieure n'a aucun effet ═════════════════════════

    public function test_switching_providers_env_after_execution_has_no_effect(): void
    {
        $userId = $this->createUser();
        $src = $this->wallet($userId, 'EUR', '500.00');
        $dst = $this->wallet($userId, 'USD', '0.00');

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);
        WalletService::transferMultiCurrency($this->request($userId, $src, $dst, $context));

        // Bascule APRÈS coup.
        putenv('PROVIDERS_ENV=production');
        $this->assertSame('production', ProviderConfig::defaultEnvironment());

        // L'opération persistée ne bouge pas.
        $this->assertSame(['sandbox'], $this->ledgerEnvs($userId));
    }

    // ══ 3. Production explicite : même règle, sens inverse ═════════════════

    public function test_production_context_is_not_downgraded_by_sandbox_server(): void
    {
        $userId = $this->createUser();
        $src = $this->wallet($userId, 'EUR', '500.00');
        $dst = $this->wallet($userId, 'USD', '0.00');

        putenv('PROVIDERS_ENV=sandbox');

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::PRODUCTION);
        WalletService::transferMultiCurrency($this->request($userId, $src, $dst, $context));

        $this->assertSame(['production'], $this->ledgerEnvs($userId));
    }

    // ══ 4. Idempotence scopée jusque dans transferMultiCurrency ════════════

    /**
     * La même clé, dans deux environnements, décrit deux opérations
     * distinctes. Sans scope, la réponse sandbox serait rejouée telle quelle
     * pour l'appel de production.
     */
    public function test_same_key_in_two_environments_yields_two_operations(): void
    {
        $userId = $this->createUser();
        $src = $this->wallet($userId, 'EUR', '500.00');
        $dst = $this->wallet($userId, 'USD', '0.00');
        $key = 'dual-' . bin2hex(random_bytes(5));

        $sandbox = WalletService::transferMultiCurrency(
            $this->request($userId, $src, $dst, ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX), $key)
        );
        $production = WalletService::transferMultiCurrency(
            $this->request($userId, $src, $dst, ExecutionContext::explicit($userId, ExecutionEnvironment::PRODUCTION), $key)
        );

        $this->assertNotSame(
            $sandbox->getOperationId(),
            $production->getOperationId(),
            'Une réponse sandbox ne doit jamais être rejouée pour une requête production.'
        );

        $envs = $this->ledgerEnvs($userId);
        sort($envs);
        $this->assertSame(['production', 'sandbox'], $envs);
    }

    // ══ 5. Le rejeu dans le MÊME environnement reste idempotent ════════════

    public function test_same_key_in_same_environment_is_replayed(): void
    {
        $userId = $this->createUser();
        $src = $this->wallet($userId, 'EUR', '500.00');
        $dst = $this->wallet($userId, 'USD', '0.00');
        $key = 'replay-' . bin2hex(random_bytes(5));
        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::SANDBOX);

        $first  = WalletService::transferMultiCurrency($this->request($userId, $src, $dst, $context, $key));
        $second = WalletService::transferMultiCurrency($this->request($userId, $src, $dst, $context, $key));

        $this->assertSame(
            $first->getOperationId(),
            $second->getOperationId(),
            'Le rejeu d\'une clé dans le même environnement doit renvoyer la même opération.'
        );
    }
}
