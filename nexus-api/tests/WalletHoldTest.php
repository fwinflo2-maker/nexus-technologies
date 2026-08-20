<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\LedgerService;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Tests du Hold Lifecycle - Phase F.
 *
 * Base utilisée : `nexus_test` (isolée, JAMAIS `nexus`).
 * Couvre : createHold / captureHold / releaseHold, la machine d'états,
 * l'idempotence, la concurrence, l'atomicité et la précision décimale.
 *
 * Convention d'échelle :
 *   - `wallets`            → projection DECIMAL(20,2) (assertions à 2 dp)
 *   - `ledger_entries`     → source de vérité DECIMAL(20,8) (assertions à 8 dp)
 *   - `wallet_operations`  → DECIMAL(20,8) (assertions à 8 dp)
 */
final class WalletHoldTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds:list<int>, walletIds:list<int>, operationIds:list<string>, keys:list<array{key:string,userId:int}>} */
    private array $created = [
        'userIds'      => [],
        'walletIds'    => [],
        'operationIds' => [],
        'keys'         => [],
    ];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Refus de tourner contre la base "' . $dbName . '".');
        }
        $this->created = ['userIds' => [], 'walletIds' => [], 'operationIds' => [], 'keys' => []];
    }

    protected function tearDown(): void
    {
        try {
            // Clés d'idempotence créées par le test (aucune FK).
            foreach ($this->created['keys'] as $entry) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM idempotency_keys WHERE idempotency_key = :key AND user_id = :uid'
                );
                $stmt->execute(['key' => $entry['key'], 'uid' => $entry['userId']]);
            }

            if (!empty($this->created['operationIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['operationIds']), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($ph)")->execute($this->created['operationIds']);
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
            fwrite(STDERR, '[WalletHoldTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ---- Fixtures ------------------------------------------------------------

    private function createUser(): int
    {
        $suffix = (string) (++self::$counter) . '_' . bin2hex(random_bytes(3));
        $email  = 'hold_' . $suffix . '@nexus.test';
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Hold Test ' . $suffix, $email, 'hash', 'personal', 'ACTIVE', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    /**
     * Enregistre une clé d'idempotence pour nettoyage en tearDown.
     */
    private function trackKey(string $key, int $userId): void
    {
        $this->created['keys'][] = ['key' => $key, 'userId' => $userId];
    }

    private function createWallet(int $userId, string $currency, string $balance, string $hold = '0.00'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (?, ?, ?, ?, ?)'
        );
        $available = bcsub($balance, $hold, 2);
        $stmt->execute([$userId, $currency, $balance, $available, $hold]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
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

    private function ledgerCount(string $operationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE operation_id = ?');
        $stmt->execute([$operationId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertBalancesConsistent(int $walletId): void
    {
        $w = $this->walletRow($walletId);
        // Modèle GL : balance = available + hold + pending + in_transit + settlement
        $pending = (string) ($w['pending_balance'] ?? '0');
        $transit = (string) ($w['in_transit_balance'] ?? '0');
        $settlement = (string) ($w['settlement_balance'] ?? '0');
        $sum = bcadd(
            bcadd((string) $w['available_balance'], (string) $w['hold_balance'], 8),
            bcadd(bcadd($pending, $transit, 8), $settlement, 8),
            8
        );
        $this->assertSame(
            bcadd((string) $w['balance'], '0', 2),
            bcadd($sum, '0', 2),
            'Invariant balance = available + hold + pending + in_transit + settlement violé.'
        );
    }

    // ---- Create Hold ----------------------------------------------------------

    public function testCreateHoldSuccessfully(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];
        $this->assertSame('pending', $res['status']);

        $w = $this->walletRow($wid);
        $this->assertSame('100.00', $w['balance'], 'balance inchangé.');
        $this->assertSame('30.00', $w['hold_balance'], 'hold augmenté.');
        $this->assertSame('70.00', $w['available_balance'], 'available diminué.');
        $this->assertBalancesConsistent($wid);

        $op = $this->operationRow($res['operation_id']);
        $this->assertSame('hold', $op['type']);
        $this->assertSame('pending', $op['status']);
        $this->assertSame($u, (int) $op['user_id']);
        $this->assertSame('30.00000000', $op['source_amount']);
        // Aucune écriture ledger pour une simple réservation.
        $this->assertSame(0, $this->ledgerCount($res['operation_id']));
    }

    public function testCreateHoldFailsWhenAvailableInsufficient(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $this->expectException(RuntimeException::class);
        WalletService::createHold($u, $wid, '150.00000000', 'EUR');
    }

    public function testCreateHoldFailsWithInvalidAmount(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $this->expectException(RuntimeException::class);
        WalletService::createHold($u, $wid, '0.00000000', 'EUR');
    }

    public function testCreateHoldFailsWithWrongCurrency(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $this->expectException(RuntimeException::class);
        WalletService::createHold($u, $wid, '30.00000000', 'USD');
    }

    public function testCreateHoldIsIdempotent(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $key = 'create-hold-idem-' . bin2hex(random_bytes(4));
        $this->trackKey($key, $u);
        $r1  = WalletService::createHold($u, $wid, '30.00000000', 'EUR', $key);
        $this->created['operationIds'][] = $r1['operation_id'];

        $r2 = WalletService::createHold($u, $wid, '30.00000000', 'EUR', $key);
        $this->assertSame($r1['operation_id'], $r2['operation_id']);

        $w = $this->walletRow($wid);
        $this->assertSame('30.00', $w['hold_balance'], 'Une seule réservation.');
        $this->assertSame('70.00', $w['available_balance']);
        $this->assertSame(1, $this->countHoldOperations($wid, 'pending'));
    }

    public function testCreateHoldRollbackOnFailure(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '10.00');

        try {
            WalletService::createHold($u, $wid, '50.00000000', 'EUR');
            $this->fail('Un hold > available doit échouer.');
        } catch (RuntimeException $e) {
            // attendu
        }

        $w = $this->walletRow($wid);
        $this->assertSame('10.00', $w['balance']);
        $this->assertSame('0.00', $w['hold_balance']);
        $this->assertSame('10.00', $w['available_balance']);
    }

    // ---- Capture Hold ---------------------------------------------------------

    public function testCaptureHoldSuccessfully(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $cap = WalletService::captureHold($res['operation_id'], $u);
        $this->assertSame('completed', $cap['status']);

        $w = $this->walletRow($wid);
        // Capture = débit définitif ; create/release restent sans ledger.
        $this->assertSame('70.00', $w['balance'], 'balance débitée à la capture.');
        $this->assertSame('0.00', $w['hold_balance'], 'hold libéré.');
        $this->assertSame('70.00', $w['available_balance'], 'available inchangé.');
        $this->assertSame('0.00', $w['in_transit_balance']);
        $this->assertBalancesConsistent($wid);

        $op = $this->operationRow($res['operation_id']);
        $this->assertSame('completed', $op['status']);
        $this->assertSame(2, $this->ledgerCount($res['operation_id']), 'Capture équilibrée en deux jambes.');
    }

    public function testCaptureHoldIsIdempotent(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $key = 'capture-idem-' . bin2hex(random_bytes(4));
        $this->trackKey($key, $u);
        $c1  = WalletService::captureHold($res['operation_id'], $u, $key);
        $c2  = WalletService::captureHold($res['operation_id'], $u, $key);
        $this->assertSame($c1['status'], $c2['status']);

        $w = $this->walletRow($wid);
        $this->assertSame('70.00', $w['balance'], 'Une seule capture définitive.');
        $this->assertSame('0.00', $w['hold_balance']);
        $this->assertSame('0.00', $w['in_transit_balance']);
        $this->assertSame(2, $this->ledgerCount($res['operation_id']));
    }

    public function testCannotCaptureAlreadyCapturedHold(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        WalletService::captureHold($res['operation_id'], $u);

        $this->expectException(RuntimeException::class);
        WalletService::captureHold($res['operation_id'], $u);
    }

    public function testCannotCaptureReleasedHold(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        WalletService::releaseHold($res['operation_id'], $u);

        $this->expectException(RuntimeException::class);
        WalletService::captureHold($res['operation_id'], $u);
    }

    public function testCannotCaptureNonHoldOperation(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $opId = LedgerService::debit($u, $wid, '10.00000000', 'EUR', 'withdrawal');
        $this->created['operationIds'][] = $opId;

        $this->expectException(RuntimeException::class);
        WalletService::captureHold($opId, $u);
    }

    public function testCaptureFailsWhenHoldBalanceInsufficient(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        // Corruption contrôlée : hold_balance inférieur au montant de l'opération.
        $this->pdo->prepare('UPDATE wallets SET hold_balance = ? WHERE id = ?')
            ->execute(['20.00', $wid]);

        $this->expectException(RuntimeException::class);
        WalletService::captureHold($res['operation_id'], $u);
    }

    public function testCaptureRollbackOnFailure(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        // hold_balance corrompu trop faible → la capture échoue sans aucune écriture.
        $this->pdo->prepare('UPDATE wallets SET hold_balance = ? WHERE id = ?')
            ->execute(['10.00', $wid]);

        try {
            WalletService::captureHold($res['operation_id'], $u);
            $this->fail('Capture avec hold insuffisant doit échouer.');
        } catch (RuntimeException $e) {
            // attendu
        }

        $w = $this->walletRow($wid);
        $this->assertSame('100.00', $w['balance'], 'balance inchangé après rollback.');
        $this->assertSame('10.00', $w['hold_balance']);
        $this->assertSame('70.00', $w['available_balance'], 'available inchangé (corruption contrôlée).');
        $this->assertSame(0, $this->ledgerCount($res['operation_id']), 'aucune écriture ledger.');

        $op = $this->operationRow($res['operation_id']);
        $this->assertSame('pending', $op['status'], 'L\'opération reste pending.');
    }

    // ---- Release Hold ---------------------------------------------------------

    public function testReleaseHoldSuccessfully(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $rel = WalletService::releaseHold($res['operation_id'], $u);
        $this->assertSame('cancelled', $rel['status']);

        $w = $this->walletRow($wid);
        $this->assertSame('100.00', $w['balance'], 'balance inchangé.');
        $this->assertSame('0.00', $w['hold_balance'], 'hold libéré.');
        $this->assertSame('100.00', $w['available_balance'], 'available restauré.');
        $this->assertBalancesConsistent($wid);

        $op = $this->operationRow($res['operation_id']);
        $this->assertSame('cancelled', $op['status']);
        $this->assertSame(0, $this->ledgerCount($res['operation_id']), 'Aucune écriture ledger.');
    }

    public function testReleaseHoldIsIdempotent(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $key = 'release-idem-' . bin2hex(random_bytes(4));
        $this->trackKey($key, $u);
        $r1  = WalletService::releaseHold($res['operation_id'], $u, $key);
        $r2  = WalletService::releaseHold($res['operation_id'], $u, $key);
        $this->assertSame($r1['status'], $r2['status']);

        $w = $this->walletRow($wid);
        $this->assertSame('100.00', $w['available_balance'], 'Une seule libération.');
        $this->assertSame('0.00', $w['hold_balance']);
    }

    public function testCannotReleaseAlreadyReleasedHold(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        WalletService::releaseHold($res['operation_id'], $u);

        $this->expectException(RuntimeException::class);
        WalletService::releaseHold($res['operation_id'], $u);
    }

    public function testCannotReleaseCapturedHold(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        WalletService::captureHold($res['operation_id'], $u);

        $this->expectException(RuntimeException::class);
        WalletService::releaseHold($res['operation_id'], $u);
    }

    public function testCannotReleaseNonHoldOperation(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $opId = LedgerService::debit($u, $wid, '10.00000000', 'EUR', 'withdrawal');
        $this->created['operationIds'][] = $opId;

        $this->expectException(RuntimeException::class);
        WalletService::releaseHold($opId, $u);
    }

    public function testReleaseRollbackOnFailure(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        // hold_balance corrompu trop faible.
        $this->pdo->prepare('UPDATE wallets SET hold_balance = ? WHERE id = ?')
            ->execute(['10.00', $wid]);

        try {
            WalletService::releaseHold($res['operation_id'], $u);
            $this->fail('Release avec hold insuffisant doit échouer.');
        } catch (RuntimeException $e) {
            // attendu
        }

        $w = $this->walletRow($wid);
        $this->assertSame('100.00', $w['balance']);
        $this->assertSame('10.00', $w['hold_balance']);
        $this->assertSame('70.00', $w['available_balance'], 'available inchangé (corruption contrôlée).');
    }

    // ---- Ownership ------------------------------------------------------------

    public function testCannotCaptureAnotherUsersHold(): void
    {
        $u1  = $this->createUser();
        $u2  = $this->createUser();
        $wid = $this->createWallet($u1, 'EUR', '100.00');
        $res = WalletService::createHold($u1, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $this->expectException(RuntimeException::class);
        WalletService::captureHold($res['operation_id'], $u2);
    }

    public function testCannotReleaseAnotherUsersHold(): void
    {
        $u1  = $this->createUser();
        $u2  = $this->createUser();
        $wid = $this->createWallet($u1, 'EUR', '100.00');
        $res = WalletService::createHold($u1, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $this->expectException(RuntimeException::class);
        WalletService::releaseHold($res['operation_id'], $u2);
    }

    // ---- Précision ------------------------------------------------------------

    public function testHoldLifecyclePreservesEightDecimalPrecision(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $res = WalletService::createHold($u, $wid, '45.67000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        // wallet_operations conserve la précision 8 dp.
        $op = $this->operationRow($res['operation_id']);
        $this->assertSame('45.67000000', $op['source_amount']);

        // La projection wallets est arrondie à 2 dp (schema DECIMAL(20,2)).
        $w = $this->walletRow($wid);
        $this->assertSame('45.67', $w['hold_balance']);
        $this->assertSame('54.33', $w['available_balance']);

        WalletService::captureHold($res['operation_id'], $u);

        // Capture définitive ; précision 8 dp au ledger, 2 dp sur wallets.
        $this->assertSame(2, $this->ledgerCount($res['operation_id']));
        $op = $this->operationRow($res['operation_id']);
        $this->assertSame('45.67000000', $op['source_amount']);

        $w = $this->walletRow($wid);
        $this->assertSame('54.33', $w['balance'], 'balance débitée à la capture.');
        $this->assertSame('0.00', $w['hold_balance']);
        $this->assertSame('54.33', $w['available_balance']);
        $this->assertSame('0.00', $w['in_transit_balance']);
    }

    // ---- Concurrence (via idempotency : même clé, appel séquentiel équivalent) --

    public function testConcurrentCaptureWithSameKeyDoesNotDoubleDebit(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $key = 'concurrent-capture-' . bin2hex(random_bytes(4));
        $this->trackKey($key, $u);

        // Simulation de deux requêtes concurrentes partageant la même clé :
        // la première capture, la seconde doit être traitée comme un replay
        // (la clé est déjà completed) sans nouvelle écriture.
        WalletService::captureHold($res['operation_id'], $u, $key);
        WalletService::captureHold($res['operation_id'], $u, $key);

        $w = $this->walletRow($wid);
        $this->assertSame('70.00', $w['balance'], 'capture débitée une seule fois.');
        $this->assertSame('0.00', $w['hold_balance']);
        $this->assertSame('0.00', $w['in_transit_balance']);
        $this->assertSame(2, $this->ledgerCount($res['operation_id']));
    }

    public function testConcurrentReleaseWithSameKeyDoesNotDoubleRelease(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $res = WalletService::createHold($u, $wid, '30.00000000', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];

        $key = 'concurrent-release-' . bin2hex(random_bytes(4));
        $this->trackKey($key, $u);

        WalletService::releaseHold($res['operation_id'], $u, $key);
        WalletService::releaseHold($res['operation_id'], $u, $key);

        $w = $this->walletRow($wid);
        $this->assertSame('100.00', $w['available_balance']);
        $this->assertSame('0.00', $w['hold_balance']);
        $this->assertSame(0, $this->ledgerCount($res['operation_id']));
    }

    // ---- Helpers --------------------------------------------------------------

    private function countHoldOperations(int $walletId, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM wallet_operations
             WHERE source_wallet_id = ? AND type = \'hold\' AND status = ?'
        );
        $stmt->execute([$walletId, $status]);
        return (int) $stmt->fetchColumn();
    }
}
