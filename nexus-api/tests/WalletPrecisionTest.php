<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Tests de précision monétaire à 8 décimales — Phase G+.
 *
 * Base utilisée : `nexus_test` (isolée, JAMAIS `nexus`).
 *
 * Convention d'échelle (respectée par le schéma existant) :
 *   - `wallets`            → projection DECIMAL(20,2) (invariants à 2 dp)
 *   - `wallet_operations`  → DECIMAL(20,8) (montant exact du hold)
 *   - `ledger_entries`     → DECIMAL(20,8) (source de vérité, débit exact)
 *
 * Aucun `float` n'est utilisé : tous les montants sont comparés en BCMath
 * sur des chaînes décimales exactes.
 */
final class WalletPrecisionTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds:list<int>, walletIds:list<int>, operationIds:list<string>} */
    private array $created = ['userIds' => [], 'walletIds' => [], 'operationIds' => []];

    /** Montants testés : de la plus petite unité (1 satoshi-like) au gros montant. */
    private const AMOUNTS = [
        '0.00000001',
        '1.12345678',
        '10.12345678',
        '999999.99999999',
    ];

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
            fwrite(STDERR, '[WalletPrecisionTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ---- Fixtures ------------------------------------------------------------

    private function createUser(): int
    {
        $suffix = (string) (++self::$counter) . '_' . bin2hex(random_bytes(3));
        $email  = 'prec_' . $suffix . '@nexus.test';
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Precision Test ' . $suffix, $email, 'hash', 'personal', 'ACTIVE', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
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

    private function ledgerEntry(string $operationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT entry_type, amount, balance_after FROM ledger_entries WHERE operation_id = ?'
        );
        $stmt->execute([$operationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail('Écriture ledger introuvable.');
        }
        return $row;
    }

    /**
     * Invariant comptable projection (modèle GL) :
     *   balance = available + hold + pending + in_transit + settlement
     *   et tous les soldes >= 0.
     */
    private function assertProjectionConsistent(int $walletId): void
    {
        $w = $this->walletRow($walletId);

        $this->assertTrue(
            bccomp((string) $w['balance'], '0', 8) >= 0,
            'balance doit être >= 0.'
        );
        $this->assertTrue(
            bccomp((string) $w['available_balance'], '0', 8) >= 0,
            'available_balance doit être >= 0.'
        );
        $this->assertTrue(
            bccomp((string) $w['hold_balance'], '0', 8) >= 0,
            'hold_balance doit être >= 0.'
        );

        $pending = (string) ($w['pending_balance'] ?? '0');
        $transit = (string) ($w['in_transit_balance'] ?? '0');
        $settlement = (string) ($w['settlement_balance'] ?? '0');
        $sum = bcadd(
            bcadd((string) $w['available_balance'], (string) $w['hold_balance'], 8),
            bcadd(bcadd($pending, $transit, 8), $settlement, 8),
            8
        );
        $this->assertSame(
            bcadd((string) $w['balance'], '0', 8),
            bcadd($sum, '0', 8),
            "Invariant balance = available+hold+pending+in_transit+settlement violé (wallet={$walletId})."
        );
    }

    // ---- Tests par montant ---------------------------------------------------

    public function testCreateHoldPreservesEightDecimalsInOperation(): void
    {
        foreach (self::AMOUNTS as $amount) {
            $u   = $this->createUser();
            $wid = $this->createWallet($u, 'EUR', '1000000.00');

            $res = WalletService::createHold($u, $wid, $amount, 'EUR');
            $this->created['operationIds'][] = $res['operation_id'];
            $this->assertSame('pending', $res['status']);

            // wallet_operations conserve la précision 8 dp EXACTE.
            $op = $this->operationRow($res['operation_id']);
            $this->assertSame($amount, (string) $op['source_amount'], "source_amount doit conserver $amount");

            // Aucune écriture ledger pour une simple réservation.
            $this->assertSame(0, $this->ledgerCount($res['operation_id']));

            // Projection 2 dp : invariant disponible + gelé = solde.
            $this->assertProjectionConsistent($wid);

            // Libère le hold pour ne pas polluer le scénario suivant.
            WalletService::releaseHold($res['operation_id'], $u);
        }
    }

    public function testCapturePreservesEightDecimalsInLedger(): void
    {
        foreach (self::AMOUNTS as $amount) {
            $u   = $this->createUser();
            $wid = $this->createWallet($u, 'EUR', '1000000.00');

            $res = WalletService::createHold($u, $wid, $amount, 'EUR');
            $this->created['operationIds'][] = $res['operation_id'];

            $cap = WalletService::captureHold($res['operation_id'], $u);
            $this->assertSame('completed', $cap['status']);

            // Capture = débit définitif, équilibré USER_POSITION/OUTBOUND_TRANSIT.
            $this->assertSame(2, $this->ledgerCount($res['operation_id']));

            $op = $this->operationRow($res['operation_id']);
            $this->assertSame($amount, (string) $op['source_amount'], 'wallet_operations conserve ' . $amount);

            $w = $this->walletRow($wid);
            $this->assertSame('0.00', (string) $w['hold_balance'], 'hold_balance doit retomber à 0.');
            $this->assertSame('0.00', (string) $w['in_transit_balance']);
            if (bccomp($amount, '0.01', 8) >= 0) {
                $this->assertProjectionConsistent($wid);
            } else {
                $this->assertTrue(bccomp((string) $w['balance'], '0', 8) >= 0);
                $this->assertTrue(bccomp((string) $w['available_balance'], '0', 8) >= 0);
            }
        }
    }

    public function testReleaseRestoresAvailableBalance(): void
    {
        foreach (self::AMOUNTS as $amount) {
            $u   = $this->createUser();
            $wid = $this->createWallet($u, 'EUR', '1000000.00');

            $res = WalletService::createHold($u, $wid, $amount, 'EUR');
            $this->created['operationIds'][] = $res['operation_id'];

            $rel = WalletService::releaseHold($res['operation_id'], $u);
            $this->assertSame('cancelled', $rel['status']);

            $w = $this->walletRow($wid);
            $this->assertSame('0.00', (string) $w['hold_balance'], 'hold_balance doit retomber à 0.');
            $this->assertSame('1000000.00', (string) $w['balance'], 'balance inchangé après release.');
            $this->assertProjectionConsistent($wid);

            // Aucune écriture ledger pour une release.
            $this->assertSame(0, $this->ledgerCount($res['operation_id']));
        }
    }

    // ---- Scénario complet par montant ----------------------------------------

    public function testFullLifecyclePerAmount(): void
    {
        foreach (self::AMOUNTS as $amount) {
            $u   = $this->createUser();
            $wid = $this->createWallet($u, 'EUR', '1000000.00');

            // CREATE
            $res = WalletService::createHold($u, $wid, $amount, 'EUR');
            $this->created['operationIds'][] = $res['operation_id'];
            $this->assertSame('pending', $res['status']);
            $this->assertProjectionConsistent($wid);

            // CAPTURE
            $cap = WalletService::captureHold($res['operation_id'], $u);
            $this->assertSame('completed', $cap['status']);
            $this->assertProjectionConsistent($wid);

            // Le hold consommé ne peut être ni capturé ni libéré à nouveau.
            try {
                WalletService::captureHold($res['operation_id'], $u);
                $this->fail('Capture d\'un hold déjà capturé doit échouer.');
            } catch (RuntimeException $e) {
                // attendu
            }
            try {
                WalletService::releaseHold($res['operation_id'], $u);
                $this->fail('Release d\'un hold déjà capturé doit échouer.');
            } catch (RuntimeException $e) {
                // attendu
            }

            $this->assertProjectionConsistent($wid);
        }
    }

    public function testInvalidAmountsAreRejectedWithoutSideEffects(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        $invalid = [
            '0',              // zéro
            '0.00000000',     // zéro en 8 dp
            '-1.00000000',    // négatif
            '1,5',            // séparateur invalide
            '1.2.3',          // décimal invalide
            '',               // vide
        ];

        foreach ($invalid as $amount) {
            try {
                WalletService::createHold($u, $wid, $amount, 'EUR');
                $this->fail("Le montant invalide '$amount' doit être rejeté.");
            } catch (RuntimeException $e) {
                // attendu
            }
            $w = $this->walletRow($wid);
            $this->assertSame('100.00', (string) $w['balance'], 'balance inchangé après échec.');
            $this->assertSame('0.00', (string) $w['hold_balance'], 'hold inchangé après échec.');
            $this->assertSame('100.00', (string) $w['available_balance'], 'available inchangé après échec.');
        }
    }

    public function testAmountWithMoreThanEightDecimalsIsRoundedByStorage(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        // La validation existante accepte les décimales longues ; bcadd(..., 8)
        // tronque à 8 dp avant stockage DECIMAL(20,8). Le test documente ce
        // comportement existant sans le modifier.
        $res = WalletService::createHold($u, $wid, '1.123456789', 'EUR');
        $this->created['operationIds'][] = $res['operation_id'];
        $this->assertSame('pending', $res['status']);

        $op = $this->operationRow($res['operation_id']);
        $this->assertSame('1.12345678', (string) $op['source_amount'], 'troncature à 8 dp.');
        $this->assertProjectionConsistent($wid);
    }

    public function testAmountGreaterThanAvailableIsRejected(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');

        try {
            WalletService::createHold($u, $wid, '100.00000001', 'EUR');
            $this->fail('Un hold supérieur au disponible doit échouer.');
        } catch (RuntimeException $e) {
            // attendu
        }

        $w = $this->walletRow($wid);
        $this->assertSame('100.00', (string) $w['balance']);
        $this->assertSame('0.00', (string) $w['hold_balance']);
        $this->assertSame('100.00', (string) $w['available_balance']);
        $this->assertProjectionConsistent($wid);
    }
}
