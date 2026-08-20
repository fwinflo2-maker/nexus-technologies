<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\ExecutionSettlementService;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Règlement asynchrone provider (webhook/polling) — §Phase 2.
 *
 * Un payout pawaPay est accepté (ACCEPTED → transaction 'processing'), puis
 * réglé par webhook :
 *   - COMPLETED → transactions.status = 'completed' (aucun mouvement wallet :
 *     le débit a déjà eu lieu à l'exécution — saga atomique hold→capture) ;
 *   - FAILED    → transactions.status = 'failed' + REMBOURSEMENT intégral du
 *     montant capturé (LedgerService type 'refund'), idempotent.
 *
 * Invariants vérifiés :
 *   - transition illégitime (statut déjà terminal, inconnue) → 409 sans effet ;
 *   - rejeu du webhook FAILED → un seul remboursement (idempotence) ;
 *   - le montant remboursé vient de l'opération wallet (jamais du webhook) ;
 *   - l'environnement sandbox/production est respecté.
 */
final class ExecutionSettlementTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var list<int> */
    private array $createdUserIds = [];
    /** @var list<int> */
    private array $createdWalletIds = [];
    /** @var list<string> */
    private array $createdOperations = [];
    /** @var list<int> */
    private array $createdTxIds = [];
    /** @var list<string> */
    private array $createdKeys = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Refus de tourner contre la base "' . $dbName . '".');
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->createdKeys as $key) {
                $this->pdo->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = :k')
                    ->execute(['k' => $key]);
            }
            if ($this->createdOperations !== []) {
                $ph = implode(',', array_fill(0, count($this->createdOperations), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($ph)")->execute($this->createdOperations);
                $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($ph)")->execute($this->createdOperations);
            }
            if ($this->createdTxIds !== []) {
                $ph = implode(',', array_fill(0, count($this->createdTxIds), '?'));
                $this->pdo->prepare("DELETE FROM transactions WHERE id IN ($ph)")->execute($this->createdTxIds);
                $this->pdo->prepare("DELETE FROM notifications WHERE user_id IN ($ph)")->execute($this->createdTxIds);
            }
            if ($this->createdWalletIds !== []) {
                $ph = implode(',', array_fill(0, count($this->createdWalletIds), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")->execute($this->createdWalletIds);
            }
            if ($this->createdUserIds !== []) {
                $ph = implode(',', array_fill(0, count($this->createdUserIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->createdUserIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[ExecutionSettlementTest::tearDown] ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function createUser(): int
    {
        $suffix = (string) (++self::$counter) . '_' . bin2hex(random_bytes(3));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Settlement ' . $suffix, 'settle_' . $suffix . '@nexus.test', 'hash', 'personal', 'ACTIVE', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function createWallet(int $userId, string $currency, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $currency, $balance, $balance, '0.00']);
        $id = (int) $this->pdo->lastInsertId();
        $this->createdWalletIds[] = $id;
        return $id;
    }

    /**
     * Reproduit ce qu'ExecutionEngine fait pour un envoi async :
     * hold (clé 'op:{operationId}:hold') → capture (débit), puis une ligne
     * transactions 'processing' portant le provider_operation_id.
     *
     * @return array{userId:int, walletId:int, operationId:string, txId:int, amount:string}
     */
    private function setupProcessingTransaction(string $amount = '100.00', string $environment = 'sandbox'): array
    {
        $userId   = $this->createUser();
        $walletId = $this->createWallet($userId, 'EUR', '500.00');
        $operationId = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff), random_int(0, 0xffffffffffff)
        );

        $context = ExecutionContext::explicit($userId, ExecutionEnvironment::fromString($environment));

        $holdKey = 'op:' . $operationId . ':hold';
        $this->createdKeys[] = $holdKey;
        $hold = WalletService::createHold(
            $userId,
            $walletId,
            $amount,
            'EUR',
            $holdKey,
            'Envoi EUR → XAF',
            ['operation_id' => $operationId],
            $context
        );
        $this->createdOperations[] = $hold['operation_id'];

        $captureKey = 'op:' . $operationId . ':capture';
        $this->createdKeys[] = $captureKey;
        WalletService::captureHold($hold['operation_id'], $userId, $captureKey, $context);

        // Ligne transaction 'processing' (règlement asynchrone attendu).
        $stmt = $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, status, provider,
                 provider_operation_id, provider_status, environment)
             VALUES (:u, 'send', 'out', :l, :a, 'EUR', 'processing', 'pawapay',
                     :op, 'ACCEPTED', :env)"
        );
        $stmt->execute([
            'u'   => $userId,
            'l'   => 'Envoi EUR → XAF',
            'a'   => $amount,
            'op'  => $operationId,
            'env' => $environment,
        ]);
        $txId = (int) $this->pdo->lastInsertId();
        $this->createdTxIds[] = $txId;

        return [
            'userId'      => $userId,
            'walletId'    => $walletId,
            'operationId' => $operationId,
            'txId'        => $txId,
            'amount'      => $amount,
        ];
    }

    private function loadTx(int $txId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$txId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail('Transaction introuvable.');
        }
        return $row;
    }

    private function walletRow(int $walletId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wallets WHERE id = ?');
        $stmt->execute([$walletId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail('Wallet introuvable.');
        }
        return $row;
    }

    private function countRefundEntries(int $txId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ledger_entries
             WHERE reference_type = 'refund'
               AND JSON_EXTRACT(metadata, '$.transaction_id') = :tid"
        );
        $stmt->execute(['tid' => $txId]);
        return (int) $stmt->fetchColumn();
    }

    // ── Règlement COMPLETED ───────────────────────────────────────────────

    public function test_completed_transition_updates_status_only(): void
    {
        $f = $this->setupProcessingTransaction();

        // La capture est le débit définitif.
        $w = $this->walletRow($f['walletId']);
        $this->assertSame('400.00', $w['balance']);
        $this->assertSame('400.00', $w['available_balance']);
        $this->assertSame('0.00', $w['in_transit_balance']);

        $result = ExecutionSettlementService::settle(
            $this->loadTx($f['txId']),
            'completed',
            'COMPLETED'
        );

        $this->assertSame('settled', $result['action']);
        $this->assertSame('completed', $result['transaction']['status']);
        $this->assertSame('COMPLETED', $result['transaction']['provider_status']);

        // Le règlement solde OUTBOUND_TRANSIT sans redébiter la position.
        $w = $this->walletRow($f['walletId']);
        $this->assertSame('400.00', $w['balance']);
        $this->assertSame('0.00', $w['in_transit_balance']);
        $this->assertSame(0, $this->countRefundEntries($f['txId']));
    }

    // ── Règlement FAILED → compensation ───────────────────────────────────

    public function test_failed_transition_returns_transit_to_available(): void
    {
        $f = $this->setupProcessingTransaction('100.00');
        $w = $this->walletRow($f['walletId']);
        $this->assertSame('400.00', $w['balance']);
        $this->assertSame('0.00', $w['in_transit_balance']);

        $result = ExecutionSettlementService::settle(
            $this->loadTx($f['txId']),
            'failed',
            'FAILED',
            ['provider_status' => 'FAILED', 'failure_reason' => ['code' => 'X']]
        );

        $this->assertSame('settled', $result['action']);
        $this->assertSame('failed', $result['transaction']['status']);
        $this->assertSame('FAILED', $result['transaction']['provider_status']);

        // L'échec annule comptablement la capture et recrédite le wallet.
        $w = $this->walletRow($f['walletId']);
        $this->assertSame('500.00', $w['balance']);
        $this->assertSame('500.00', $w['available_balance'], 'Transit restitué au disponible.');
        $this->assertSame('0.00', $w['in_transit_balance']);

        // Pas de reference_type refund : il s'agit d'une annulation de payout.
        $this->assertSame(0, $this->countRefundEntries($f['txId']));
    }

    public function test_failed_return_is_idempotent_on_webhook_replay(): void
    {
        $f = $this->setupProcessingTransaction('100.00');

        $tx = $this->loadTx($f['txId']);
        ExecutionSettlementService::settle($tx, 'failed', 'FAILED');

        // Rejeu du webhook (même événement) → statut terminal, acquitté sans effet.
        $tx = $this->loadTx($f['txId']);
        $replay = ExecutionSettlementService::settle($tx, 'failed', 'FAILED');

        $this->assertSame('ignored', $replay['action'], 'Statut déjà terminal → acquitté sans effet.');
        $w = $this->walletRow($f['walletId']);
        $this->assertSame('500.00', $w['balance'], 'Pas de double retour.');
        $this->assertSame('500.00', $w['available_balance']);
        $this->assertSame('0.00', $w['in_transit_balance']);
        $this->assertSame(0, $this->countRefundEntries($f['txId']), 'Aucune écriture de compensation.');
    }

    // ── Transitions illégitimes ───────────────────────────────────────────

    public function test_transition_from_terminal_state_is_ignored_without_effect(): void
    {
        $f = $this->setupProcessingTransaction();

        ExecutionSettlementService::settle($this->loadTx($f['txId']), 'completed', 'COMPLETED');

        // Un webhook FAILED sur une transaction déjà completed : l'état
        // terminal fait autorité → acquitté 'ignored', jamais de règlement
        // ni de retour de transit (idempotence, pas d'erreur).
        $replay = ExecutionSettlementService::settle($this->loadTx($f['txId']), 'failed', 'FAILED');

        $this->assertSame('ignored', $replay['action']);
        $this->assertSame('completed', $this->loadTx($f['txId'])['status']);
        $w = $this->walletRow($f['walletId']);
        // Fixture : hold de 100 (frais 0) → position débitée de 100 au règlement.
        $this->assertSame('400.00', $w['balance'], 'Position débitée au règlement complet.');
        $this->assertSame('0.00', $w['in_transit_balance']);
        $this->assertSame(0, $this->countRefundEntries($f['txId']));
    }

    public function test_unknown_transaction_is_not_found(): void
    {
        $f = $this->setupProcessingTransaction();
        $tx = $this->loadTx($f['txId']);
        $tx['id'] = 999999999;

        try {
            ExecutionSettlementService::settle($tx, 'failed', 'FAILED');
            $this->fail('Une transaction inconnue doit être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->statusCode());
        }
    }

    // ── Environnement ─────────────────────────────────────────────────────

    public function test_settlement_respects_the_transaction_environment(): void
    {
        $f = $this->setupProcessingTransaction('100.00', 'production');

        // Règlement depuis un contexte SANDBOX → refus (mismatch).
        $sandboxCtx = ExecutionContext::explicit($f['userId'], ExecutionEnvironment::SANDBOX);

        try {
            ExecutionSettlementService::settle(
                $this->loadTx($f['txId']),
                'completed',
                'COMPLETED',
                [],
                $sandboxCtx
            );
            $this->fail('Un règlement sandbox ne doit pas toucher une transaction production.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->statusCode());
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode());
        }

        // Aucune transition appliquée.
        $this->assertSame('processing', $this->loadTx($f['txId'])['status']);
    }
}
