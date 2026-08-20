<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests de l'expiration automatique des holds — Phase G+.
 *
 * Base utilisée : `nexus_test` (isolée, JAMAIS `nexus`).
 *
 * Ces tests répliquent EXACTEMENT la sémantique du worker
 * `scripts/expire_holds.php` :
 *   1. sélection des opérations type='hold', status='pending',
 *      expires_at <= NOW() ;
 *   2. pour chaque hold : WalletService::releaseHold() avec la clé
 *      d'idempotence déterministe `expire-hold-{operation_id}`.
 *
 * Le worker CLI lui-même est vérifié manuellement (sortie "Expired/Skipped/
 * Errors" + état comptable final) ; ici on teste l'effet comptable.
 */
final class WalletHoldExpirationTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds:list<int>, walletIds:list<int>, operationIds:list<string>} */
    private array $created = ['userIds' => [], 'walletIds' => [], 'operationIds' => []];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Refus de tourner contre la base "' . $dbName . '".');
        }
        $this->created = ['userIds' => [], 'walletIds' => [], 'operationIds' => []];
    }

    protected function tearDown(): void
    {
        try {
            if (!empty($this->created['operationIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['operationIds']), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($ph)")->execute($this->created['operationIds']);
                $this->pdo->prepare("DELETE FROM idempotency_keys WHERE idempotency_key LIKE 'expire-hold-%'")->execute();
                $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($ph)")->execute($this->created['operationIds']);
            }
            if (!empty($this->created['walletIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[WalletHoldExpirationTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ---- Fixtures ------------------------------------------------------------

    private function createUser(): int
    {
        $suffix = (string) (++self::$counter) . '_' . bin2hex(random_bytes(3));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Expiration ' . $suffix, 'exp_' . $suffix . '@nexus.test', 'hash', 'personal', 'ACTIVE', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    private function createWallet(int $userId, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (?, \'EUR\', ?, ?, ?)'
        );
        $stmt->execute([$userId, $balance, $balance, '0.00']);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    private function createHold(int $userId, int $walletId, string $amount): string
    {
        $res = WalletService::createHold($userId, $walletId, $amount, 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];
        return $res['operation_id'];
    }

    private function setExpiresAt(string $operationId, string $expression): void
    {
        $stmt = $this->pdo->prepare("UPDATE wallet_operations SET expires_at = $expression WHERE id = ?");
        $stmt->execute([$operationId]);
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

    private function operationRow(string $operationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wallet_operations WHERE id = ?');
        $stmt->execute([$operationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail('Opération introuvable.');
        }
        return $row;
    }

    private function pendingExpiredCandidates(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, user_id
             FROM wallet_operations
             WHERE type = 'hold'
               AND status = 'pending'
               AND expires_at IS NOT NULL
               AND expires_at <= NOW()"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Exécute un cycle d'expiration (même logique que le worker CLI). */
    private function runExpirationCycle(): array
    {
        $expired = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($this->pendingExpiredCandidates() as $candidate) {
            $operationId = (string) $candidate['id'];
            $userId      = (int) $candidate['user_id'];
            $idemKey     = 'expire-hold-' . $operationId;

            try {
                $result = WalletService::releaseHold($operationId, $userId, $idemKey);
                if (($result['status'] ?? '') === 'cancelled') {
                    $expired++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors++;
            }
        }

        return ['expired' => $expired, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ---- Tests ---------------------------------------------------------------

    public function testHoldNotExpiredStaysPending(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, '100.00');
        $op  = $this->createHold($u, $wid, '30.00000000');

        // expires_at dans le futur → non candidat à l'expiration.
        $this->setExpiresAt($op, "DATE_ADD(NOW(), INTERVAL 1 HOUR)");

        $stats = $this->runExpirationCycle();

        $this->assertSame(0, $stats['expired']);
        $this->assertSame(0, $stats['errors']);

        $row = $this->operationRow($op);
        $this->assertSame('pending', $row['status'], 'Le hold non expiré reste pending.');
        $w = $this->walletRow($wid);
        $this->assertSame('30.00', (string) $w['hold_balance']);
        $this->assertSame('70.00', (string) $w['available_balance']);
    }

    public function testExpiredHoldIsAutoReleased(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, '100.00');
        $op  = $this->createHold($u, $wid, '30.00000000');

        $this->setExpiresAt($op, "DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        $stats = $this->runExpirationCycle();

        $this->assertSame(1, $stats['expired']);
        $this->assertSame(0, $stats['errors']);

        // Comptable : status = cancelled, available restauré, hold diminué.
        $row = $this->operationRow($op);
        $this->assertSame('cancelled', $row['status']);
        $w = $this->walletRow($wid);
        $this->assertSame('0.00', (string) $w['hold_balance']);
        $this->assertSame('100.00', (string) $w['available_balance']);
        $this->assertSame('100.00', (string) $w['balance']);
    }

    public function testAlreadyCapturedHoldHasNoWorkerEffect(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, '100.00');
        $op  = $this->createHold($u, $wid, '30.00000000');

        WalletService::captureHold($op, $u);
        $this->setExpiresAt($op, "DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        $stats = $this->runExpirationCycle();

        $this->assertSame(0, $stats['expired']);
        $this->assertSame(0, $stats['errors']);

        $row = $this->operationRow($op);
        $this->assertSame('completed', $row['status'], 'Un hold capturé ne doit pas être expiré.');
        $w = $this->walletRow($wid);
        $this->assertSame('0.00', (string) $w['hold_balance']);
        $this->assertSame('70.00', (string) $w['available_balance']);
        $this->assertSame('70.00', (string) $w['balance'], 'La capture reste le débit définitif.');
        $this->assertSame('0.00', (string) ($w['in_transit_balance'] ?? '0'));
    }

    public function testAlreadyReleasedHoldHasNoWorkerEffect(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, '100.00');
        $op  = $this->createHold($u, $wid, '30.00000000');

        WalletService::releaseHold($op, $u);
        $this->setExpiresAt($op, "DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        $stats = $this->runExpirationCycle();

        $this->assertSame(0, $stats['expired']);
        $this->assertSame(0, $stats['errors']);

        $row = $this->operationRow($op);
        $this->assertSame('cancelled', $row['status']);
        $w = $this->walletRow($wid);
        $this->assertSame('0.00', (string) $w['hold_balance']);
        $this->assertSame('100.00', (string) $w['available_balance']);
    }

    public function testDoubleExpirationHasSingleAccountingEffect(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, '100.00');
        $op  = $this->createHold($u, $wid, '30.00000000');

        $this->setExpiresAt($op, "DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        // Premier cycle → expiration effective.
        $first = $this->runExpirationCycle();
        $this->assertSame(1, $first['expired']);

        // Deuxième cycle (le worker se relance) → aucun nouvel effet comptable.
        $second = $this->runExpirationCycle();
        $this->assertSame(0, $second['expired']);
        $this->assertSame(0, $second['errors']);

        $row = $this->operationRow($op);
        $this->assertSame('cancelled', $row['status']);

        $w = $this->walletRow($wid);
        $this->assertSame('0.00', (string) $w['hold_balance']);
        $this->assertSame('100.00', (string) $w['available_balance']);
        $this->assertSame('100.00', (string) $w['balance']);

        // Une seule écriture ledger (la release n'écrit pas au ledger ; une
        // capture si elle existait écrirait une ligne unique — ici aucune).
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE operation_id = ?');
        $stmt->execute([$op]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
