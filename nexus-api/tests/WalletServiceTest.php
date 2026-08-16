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
 * Tests du WalletService - Phase B4.
 *
 * Base utilisee : nexus_test (isolee, JAMAIS nexus).
 * Strategie d isolation : chaque test cree ses propres fixtures (user +
 * wallets) identifies par un suffixe unique (timestamp + compteur +
 * aleatoire). Le tearDown supprime exactement les IDs crees par chaque test,
 * sans TRUNCATE ni DROP.
 *
 * Invariant metier verifie : available_balance = balance - hold_balance.
 * WalletService est une couche de lecture/projection uniquement :
 *   - il ne modifie jamais balance via le ledger,
 *   - ensureWallet() ne fait qu un INSERT ON DUPLICATE KEY,
 *   - aucune ecriture dans ledger_entries ni wallet_operations.
 *
 * PHP     : 8.2.12
 * PHPUnit : ^10.0
 */
final class WalletServiceTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds: list<int>, walletIds: list<int>} */
    private array $created = [
        'userIds'   => [],
        'walletIds' => [],
    ];

    // ---- setUp / tearDown --------------------------------------------------

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail(
                'Refus de tourner contre la base "' . $dbName . '". '
                . 'Les tests WalletServiceTest doivent utiliser nexus_test uniquement.'
            );
        }

        $this->created = ['userIds' => [], 'walletIds' => []];
    }

    protected function tearDown(): void
    {
        try {
            if (!empty($this->created['walletIds'])) {
                $ph   = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)");
                $stmt->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $ph   = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)");
                $stmt->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[WalletServiceTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ---- Helpers fixtures --------------------------------------------------

    private function uniqueSuffix(): string
    {
        self::$counter++;
        return sprintf('%d_%d_%s', time(), self::$counter, bin2hex(random_bytes(3)));
    }

    private function createUser(string $suffix): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:name, :email, :pwd, :type, :status, :kyc)'
        );
        $stmt->execute([
            'name'   => 'WalletTest ' . $suffix,
            'email'  => 'wt_' . $suffix . '@nexus-test.local',
            'pwd'    => '',
            'type'   => 'personal',
            'status' => 'ACTIVE',
            'kyc'    => 'none',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    private function createWallet(
        int    $userId,
        string $currency,
        string $balance,
        string $hold        = '0.00',
        string $pending     = '0.00',
        string $inTransit   = '0.00',
        string $settlement  = '0.00'
    ): int {
        $available = bcsub($balance, $hold, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets
                (user_id, currency, balance, available_balance, hold_balance,
                 pending_balance, in_transit_balance, settlement_balance)
             VALUES (:uid, :cur, :bal, :avail, :hold, :pend, :intrans, :settle)'
        );
        $stmt->execute([
            'uid'     => $userId,
            'cur'     => $currency,
            'bal'     => $balance,
            'avail'   => $available,
            'hold'    => $hold,
            'pend'    => $pending,
            'intrans' => $inTransit,
            'settle'  => $settlement,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    private function countLedgerEntries(int $walletId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE wallet_id = :id');
        $stmt->execute(['id' => $walletId]);
        return (int) $stmt->fetchColumn();
    }

    private function countWalletOperations(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM wallet_operations WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // ---- Tests : getWallet() -----------------------------------------------

    public function test_getWallet_retourne_le_wallet_existant(): void
    {
        $s  = $this->uniqueSuffix();
        $u  = $this->createUser($s);
        $id = $this->createWallet($u, 'EUR', '250.00', '50.00');

        $w = WalletService::getWallet($u, 'EUR');

        $this->assertIsArray($w, 'getWallet() doit retourner un tableau.');
        $this->assertSame($id, $w['id'],                'id correct.');
        $this->assertSame($u,  $w['user_id'],           'user_id correct.');
        $this->assertSame('EUR',   $w['currency'],      'currency EUR.');
        $this->assertSame('250.00', $w['balance'],      'balance 250.00.');
        $this->assertSame('200.00', $w['available_balance'], 'available = 250 - 50.');
        $this->assertSame('50.00',  $w['hold_balance'], 'hold 50.00.');
        $this->assertSame('0.00',   $w['pending_balance']);
        $this->assertSame('0.00',   $w['in_transit_balance']);
        $this->assertSame('0.00',   $w['settlement_balance']);
        $this->assertArrayHasKey('created_at', $w);
        $this->assertArrayHasKey('updated_at', $w);
    }

    public function test_getWallet_retourne_null_pour_wallet_inexistant(): void
    {
        $u = $this->createUser($this->uniqueSuffix());

        $this->assertNull(WalletService::getWallet($u, 'USD'));
    }

    public function test_getWallet_leve_exception_pour_userId_invalide(): void
    {
        $this->expectException(RuntimeException::class);
        WalletService::getWallet(0, 'EUR');
    }

    public function test_getWallet_leve_exception_pour_devise_non_supportee(): void
    {
        $this->expectException(RuntimeException::class);
        WalletService::getWallet(1, 'ZZZ');
    }

    // ---- Tests : getAllWallets() / getAllBalances() -------------------------

    public function test_getAllWallets_retourne_uniquement_les_wallets_du_bon_utilisateur(): void
    {
        $uA = $this->createUser($this->uniqueSuffix());
        $uB = $this->createUser($this->uniqueSuffix());

        $this->createWallet($uA, 'EUR', '100.00');
        $this->createWallet($uA, 'USD', '200.00');
        $this->createWallet($uB, 'GBP', '300.00');

        $wA = WalletService::getAllWallets($uA);
        $wB = WalletService::getAllWallets($uB);

        $this->assertCount(2, $wA, 'userA doit avoir 2 wallets.');
        $this->assertCount(1, $wB, 'userB doit avoir 1 wallet.');

        foreach ($wA as $w) {
            $this->assertSame($uA, $w['user_id'], 'Tous les wallets de A appartiennent a A.');
        }

        $this->assertNotContains('GBP', array_column($wA, 'currency'));
    }

    public function test_getAllWallets_retourne_tableau_vide_si_aucun_wallet(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->assertSame([], WalletService::getAllWallets($u));
    }

    public function test_getAllBalances_est_un_alias_de_getAllWallets(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR',  '75.00');
        $this->createWallet($u, 'XAF', '10000.00');

        $this->assertEquals(
            WalletService::getAllWallets($u),
            WalletService::getAllBalances($u)
        );
    }

    public function test_getAllWallets_respecte_les_devises(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR',   '100.00');
        $this->createWallet($u, 'USD',   '200.50');
        $this->createWallet($u, 'USDT', '5000.00');

        $wallets = WalletService::getAllWallets($u);
        $this->assertCount(3, $wallets);

        $byC = [];
        foreach ($wallets as $w) {
            $byC[$w['currency']] = $w;
        }

        $this->assertSame('100.00',  $byC['EUR']['balance']);
        $this->assertSame('200.50',  $byC['USD']['balance']);
        $this->assertSame('5000.00', $byC['USDT']['balance']);
    }

    // ---- Tests : getAvailable() --------------------------------------------

    public function test_getAvailable_retourne_balance_moins_hold(): void
    {
        $u  = $this->createUser($this->uniqueSuffix());
        $id = $this->createWallet($u, 'EUR', '500.00', '120.00');

        $r = WalletService::getAvailable($id);

        $this->assertSame($id,      $r['wallet_id']);
        $this->assertSame('EUR',    $r['currency']);
        $this->assertSame('500.00', $r['balance']);
        $this->assertSame('120.00', $r['hold_balance']);
        $this->assertSame('380.00', $r['available_balance'], 'available = 500 - 120.');
    }

    public function test_getAvailable_avec_hold_a_zero(): void
    {
        $u  = $this->createUser($this->uniqueSuffix());
        $id = $this->createWallet($u, 'USD', '1000.00');

        $r = WalletService::getAvailable($id);

        $this->assertSame('1000.00', $r['balance']);
        $this->assertSame('0.00',    $r['hold_balance']);
        $this->assertSame('1000.00', $r['available_balance'], 'Sans hold, available = balance.');
    }

    public function test_getAvailable_leve_exception_pour_wallet_inexistant(): void
    {
        $this->expectException(RuntimeException::class);
        WalletService::getAvailable(999999999);
    }

    public function test_getAvailable_leve_exception_pour_wallet_id_invalide(): void
    {
        $this->expectException(RuntimeException::class);
        WalletService::getAvailable(0);
    }

    public function test_getAvailable_retourne_les_cles_attendues(): void
    {
        $u  = $this->createUser($this->uniqueSuffix());
        $id = $this->createWallet($u, 'GBP', '300.00', '50.00');

        $r = WalletService::getAvailable($id);

        $this->assertArrayHasKey('wallet_id',         $r);
        $this->assertArrayHasKey('currency',          $r);
        $this->assertArrayHasKey('balance',           $r);
        $this->assertArrayHasKey('hold_balance',      $r);
        $this->assertArrayHasKey('available_balance', $r);
    }

    // ---- Tests : ensureWallet() --------------------------------------------

    public function test_ensureWallet_cree_un_wallet_absent(): void
    {
        $u = $this->createUser($this->uniqueSuffix());

        $w = WalletService::ensureWallet($u, 'EUR');
        $this->created['walletIds'][] = $w['id'];

        $this->assertIsArray($w);
        $this->assertSame($u,    $w['user_id']);
        $this->assertSame('EUR', $w['currency']);
        $this->assertSame('0.00', $w['balance'],           'Solde initial 0.00.');
        $this->assertSame('0.00', $w['available_balance'], 'Available initial 0.00.');
        $this->assertSame('0.00', $w['hold_balance'],      'Hold initial 0.00.');
        $this->assertGreaterThan(0, $w['id']);
    }

    public function test_ensureWallet_idempotent_ne_cree_qu_un_seul_wallet(): void
    {
        $u = $this->createUser($this->uniqueSuffix());

        $w1 = WalletService::ensureWallet($u, 'USD');
        $this->created['walletIds'][] = $w1['id'];

        $w2 = WalletService::ensureWallet($u, 'USD');

        $this->assertSame($w1['id'], $w2['id'], 'Meme wallet retourne les deux fois.');

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM wallets WHERE user_id = :uid AND currency = :cur'
        );
        $stmt->execute(['uid' => $u, 'cur' => 'USD']);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Un seul wallet en base.');
    }

    public function test_ensureWallet_retourne_wallet_existant_si_present(): void
    {
        $u  = $this->createUser($this->uniqueSuffix());
        $id = $this->createWallet($u, 'GBP', '999.00');

        $r = WalletService::ensureWallet($u, 'GBP');

        $this->assertSame($id,      $r['id'],      'Retourne le wallet existant.');
        $this->assertSame('999.00', $r['balance'], 'Solde inchange.');
    }

    public function test_ensureWallet_leve_exception_pour_devise_non_supportee(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->expectException(RuntimeException::class);
        WalletService::ensureWallet($u, 'XYZ');
    }

    // ---- Tests : isolation entre utilisateurs ------------------------------

    public function test_deux_utilisateurs_sont_isoles(): void
    {
        $uA = $this->createUser($this->uniqueSuffix());
        $uB = $this->createUser($this->uniqueSuffix());

        $this->createWallet($uA, 'EUR', '100.00');
        $this->createWallet($uB, 'EUR', '200.00');

        $wA = WalletService::getWallet($uA, 'EUR');
        $wB = WalletService::getWallet($uB, 'EUR');

        $this->assertNotNull($wA);
        $this->assertNotNull($wB);
        $this->assertNotSame($wA['id'], $wB['id'],   'IDs distincts.');
        $this->assertSame('100.00', $wA['balance'],  'Solde A = 100.');
        $this->assertSame('200.00', $wB['balance'],  'Solde B = 200.');
        $this->assertSame($uA, $wA['user_id'],       'wallet A -> userA.');
        $this->assertSame($uB, $wB['user_id'],       'wallet B -> userB.');
    }

    public function test_getWallet_ne_renvoie_pas_le_wallet_d_un_autre_utilisateur(): void
    {
        $uA = $this->createUser($this->uniqueSuffix());
        $uB = $this->createUser($this->uniqueSuffix());

        $this->createWallet($uB, 'USD', '500.00');

        $this->assertNull(WalletService::getWallet($uA, 'USD'));
    }

    // ---- Tests : coherence des devises ------------------------------------

    public function test_getWallet_ne_melange_pas_les_devises(): void
    {
        $u    = $this->createUser($this->uniqueSuffix());
        $idE  = $this->createWallet($u, 'EUR', '100.00');
        $idUS = $this->createWallet($u, 'USD', '200.00');

        $wE = WalletService::getWallet($u, 'EUR');
        $wU = WalletService::getWallet($u, 'USD');

        $this->assertSame($idE,   $wE['id']);
        $this->assertSame('EUR',  $wE['currency']);
        $this->assertSame('100.00', $wE['balance']);

        $this->assertSame($idUS,  $wU['id']);
        $this->assertSame('USD',  $wU['currency']);
        $this->assertSame('200.00', $wU['balance']);
    }

    public function test_ensureWallet_cree_wallets_distincts_par_devise(): void
    {
        $u   = $this->createUser($this->uniqueSuffix());

        $wE = WalletService::ensureWallet($u, 'EUR');
        $wU = WalletService::ensureWallet($u, 'USD');

        $this->created['walletIds'][] = $wE['id'];
        $this->created['walletIds'][] = $wU['id'];

        $this->assertNotSame($wE['id'], $wU['id'], 'EUR et USD ont des IDs distincts.');
        $this->assertSame('EUR', $wE['currency']);
        $this->assertSame('USD', $wU['currency']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM wallets WHERE user_id = :uid');
        $stmt->execute(['uid' => $u]);
        $this->assertSame(2, (int) $stmt->fetchColumn(), '2 wallets en base.');
    }

    // ---- Test : WalletService ne modifie jamais le ledger -----------------

    public function test_aucune_modification_du_ledger_par_walletservice(): void
    {
        $u   = $this->createUser($this->uniqueSuffix());
        $id  = $this->createWallet($u, 'EUR', '300.00', '100.00');

        $ledgerBefore = $this->countLedgerEntries($id);
        $opsBefore    = $this->countWalletOperations($u);

        WalletService::getWallet($u, 'EUR');
        WalletService::getAllWallets($u);
        WalletService::getAllBalances($u);
        WalletService::getAvailable($id);
        WalletService::ensureWallet($u, 'EUR');

        $nw = WalletService::ensureWallet($u, 'USD');
        $this->created['walletIds'][] = $nw['id'];

        $this->assertSame($ledgerBefore, $this->countLedgerEntries($id),
            'WalletService ne cree aucune entree ledger_entries.');
        $this->assertSame($opsBefore,    $this->countWalletOperations($u),
            'WalletService ne cree aucune entree wallet_operations.');
    }

    // ---- Test : confirmation base nexus_test -------------------------------

    public function test_confirmation_base_nexus_test(): void
    {
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->assertSame('nexus_test', $dbName, 'La base active doit etre nexus_test.');
        $this->assertNotSame('nexus',   $dbName, 'La base ne doit jamais etre nexus (prod/dev).');
    }
}
