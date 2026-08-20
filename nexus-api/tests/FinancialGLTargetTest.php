<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\FundingService;
use Nexus\Services\IdempotencyService;
use Nexus\Services\LedgerService;
use Nexus\Services\ProviderAccountService;
use Nexus\Services\ProviderReconciliationService;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * PHASE 9 — TESTS OBLIGATOIRES DU MODÈLE CIBLE (General Ledger + provider).
 *
 * Vérifie les invariants du modèle financier cible, indépendamment des
 * services métier :
 *
 *   1. création de provider account — isolation environnement + unicité
 *      (provider_slug, environment, currency) ;
 *   2. double entrée — Σ(debit) == Σ(credit) PAR DEVISE pour toute
 *      opération ; un posting déséquilibré est REFUSÉ (RuntimeException) ;
 *   3. migration d'un wallet existant — l'ouverture comptable
 *      (SUSPENSE debit / USER_POSITION credit) ne crée ni ne détruit
 *      d'argent : la position reste identique avant/après ;
 *   4. suspense — un wallet sans contrepartie externe apparaît en SUSPENSE,
 *      jamais en PROVIDER (aucun mock provider présenté comme réel) ;
 *   5. réconciliation — provider annonce 1000, ledger attend 900 →
 *      DISCREPANCY, aucune correction automatique de montant ;
 *   6. invariant des buckets — balance == available + hold + pending +
 *      in_transit + settlement (un même montant jamais compté deux fois) ;
 *   7. funding réel — le chemin de dépôt (webhook → idempotence → posting →
 *      wallet pending) crédite via le ledger, jamais par UPDATE direct,
 *      et un webhook dupliqué ne crédite qu'une fois.
 *
 * Base utilisée : `nexus_test` uniquement (garde-fou en setUp).
 */
final class FinancialGLTargetTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $walletIds = [];
    /** @var list<string> */
    private array $operationIds = [];
    /** @var list<string> */
    private array $keys = [];
    /** @var list<int> */
    private array $providerAccountIds = [];

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
            foreach ($this->keys as $k) {
                $this->pdo->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = ?')->execute([$k]);
            }
            if ($this->operationIds !== []) {
                $ph = implode(',', array_fill(0, count($this->operationIds), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($ph)")->execute($this->operationIds);
                $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($ph)")->execute($this->operationIds);
            }
            if ($this->walletIds !== []) {
                $ph = implode(',', array_fill(0, count($this->walletIds), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")->execute($this->walletIds);
            }
            if ($this->providerAccountIds !== []) {
                $ph = implode(',', array_fill(0, count($this->providerAccountIds), '?'));
                $this->pdo->prepare("DELETE FROM provider_balances WHERE provider_account_id IN ($ph)")->execute($this->providerAccountIds);
                $this->pdo->prepare("DELETE FROM reconciliation_runs WHERE provider_account_id IN ($ph)")->execute($this->providerAccountIds);
                $this->pdo->prepare("DELETE FROM provider_accounts WHERE id IN ($ph)")->execute($this->providerAccountIds);
            }
            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[FinancialGLTargetTest::tearDown] ' . $e->getMessage() . PHP_EOL);
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
        $stmt->execute(['GL ' . $suffix, 'gl_' . $suffix . '@nexus.test', 'hash', 'personal', 'ACTIVE', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function createWallet(int $userId, string $currency, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance,
                                  pending_balance, in_transit_balance, settlement_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $currency, $balance, $balance, '0.00', '0.00', '0.00', '0.00']);
        $id = (int) $this->pdo->lastInsertId();
        $this->walletIds[] = $id;
        return $id;
    }

    private function walletRow(int $walletId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT balance, available_balance, hold_balance, pending_balance,
                    in_transit_balance, settlement_balance
             FROM wallets WHERE id = ?'
        );
        $stmt->execute([$walletId]);
        return $stmt->fetch() ?: [];
    }

    private function ledgerEntries(string $operationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT entry_type, account_code, wallet_currency, amount, environment
             FROM ledger_entries WHERE operation_id = ? ORDER BY sequence'
        );
        $stmt->execute([$operationId]);
        return $stmt->fetchAll();
    }

    private function trackKey(string $key): void
    {
        $this->keys[] = $key;
    }

    private function trackOperation(string $operationId): void
    {
        $this->operationIds[] = $operationId;
    }

    private function insertOperation(
        int $userId,
        ?int $walletId,
        string $type,
        string $currency,
        string $amount,
        string $idempotencyKey,
        string $status = 'completed'
    ): string {
        $operationId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallet_operations
                (id, user_id, type, status, environment, source_wallet_id, source_currency,
                 source_amount, idempotency_key, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$operationId, $userId, $type, $status, 'sandbox', $walletId, $currency, $amount, $idempotencyKey]);
        $this->operationIds[] = $operationId;
        return $operationId;
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 1 — Provider account : isolation environnement + unicité
    // ══════════════════════════════════════════════════════════════════════

    public function test_provider_account_env_isolation_and_uniqueness(): void
    {
        // Unicité : deux résolutions identiques → le MÊME compte.
        $a1 = ProviderAccountService::resolve('pawapay', 'sandbox', 'EUR', 'safeguarding');
        $a2 = ProviderAccountService::resolve('pawapay', 'sandbox', 'EUR', 'safeguarding');
        $this->assertSame($a1, $a2, 'Un seul compte par (slug, env, devise).');
        $this->providerAccountIds[] = $a1;

        // Isolation environnement : sandbox ≠ production.
        $prod = ProviderAccountService::resolve('pawapay', 'production', 'EUR', 'safeguarding');
        $this->assertNotSame($a1, $prod, 'Un compte sandbox n\'est jamais réutilisé en production.');
        $this->providerAccountIds[] = $prod;

        // Isolation devise : EUR ≠ XAF.
        $xaf = ProviderAccountService::resolve('pawapay', 'sandbox', 'XAF', 'safeguarding');
        $this->assertNotSame($a1, $xaf);
        $this->providerAccountIds[] = $xaf;

        // Type de compte invalide → refusé.
        $this->expectException(HttpException::class);
        ProviderAccountService::resolve('pawapay', 'sandbox', 'USD', 'not_a_type');
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 2 — Double entrée : Σ(debit) == Σ(credit) par devise
    // ══════════════════════════════════════════════════════════════════════

    public function test_double_entry_balance_per_operation_and_currency(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '1000.00');
        $key = 'gl-de-' . bin2hex(random_bytes(4));
        $this->trackKey($key);
        $opId = $this->insertOperation($u, $wid, 'send', 'EUR', '102.00000000', $key, 'completed');

        LedgerService::post(
            $opId,
            'sandbox',
            [
                ['account_code' => 'USER_POSITION.EUR', 'entry_type' => 'debit',  'currency' => 'EUR', 'amount' => '102.00000000', 'wallet_id' => $wid],
                ['account_code' => 'PROVIDER_SETTLEMENT.pawapay.EUR', 'entry_type' => 'credit', 'currency' => 'EUR', 'amount' => '100.00000000'],
                ['account_code' => 'NEXUS_REVENUE.fee', 'entry_type' => 'credit', 'currency' => 'EUR', 'amount' => '2.00000000'],
            ],
            'Envoi réglé — double entrée',
            'send',
            $opId
        );

        $entries = $this->ledgerEntries($opId);
        $this->assertCount(3, $entries);
        $sumDebit  = '0.00000000';
        $sumCredit = '0.00000000';
        foreach ($entries as $e) {
            if ($e['entry_type'] === 'debit') {
                $sumDebit = bcadd($sumDebit, (string) $e['amount'], 8);
            } else {
                $sumCredit = bcadd($sumCredit, (string) $e['amount'], 8);
            }
        }
        $this->assertSame('102.00000000', $sumDebit);
        $this->assertSame('102.00000000', $sumCredit);
        $this->assertTrue(LedgerService::verifyOperation($opId), 'verifyOperation confirme l\'équilibre.');

        // Posting déséquilibré → REFUSÉ avant écriture.
        $keyBad = 'gl-de-bad-' . bin2hex(random_bytes(4));
        $this->trackKey($keyBad);
        $opBad = $this->insertOperation($u, $wid, 'send', 'EUR', '100.00000000', $keyBad, 'completed');
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
        try {
            LedgerService::post(
                $opBad,
                'sandbox',
                [
                    ['account_code' => 'USER_POSITION.EUR', 'entry_type' => 'debit',  'currency' => 'EUR', 'amount' => '100.00000000', 'wallet_id' => $wid],
                    ['account_code' => 'NEXUS_REVENUE.fee', 'entry_type' => 'credit', 'currency' => 'EUR', 'amount' => '2.00000000'],
                ],
                'Posting déséquilibré',
                'send',
                $opBad
            );
            $this->fail('Un posting déséquilibré doit être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('déséquilibré', $e->getMessage());
        }
        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
        $this->assertSame($before, $after, 'Aucune écriture partielle en cas de posting déséquilibré.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 3 — Migration : ouverture comptable sans création d'argent
    // ══════════════════════════════════════════════════════════════════════

    public function test_opening_posting_preserves_total_money(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '1000.00');
        $key = 'gl-open-' . bin2hex(random_bytes(4));
        $this->trackKey($key);

        // Total AVANT : la position de CE wallet (seul argent introduit par ce test).
        $totalBefore = $this->walletRow($wid)['balance'];

        // Posting d'ouverture (même logique que scripts/ledger_migrate.php) :
        // DEBIT SUSPENSE.EUR 1000 / CREDIT USER_POSITION.EUR 1000.
        $opId = $this->insertOperation($u, $wid, 'opening_balance', 'EUR', '1000.00000000', $key);
        LedgerService::post(
            $opId,
            'sandbox',
            [
                ['account_code' => 'SUSPENSE.EUR', 'entry_type' => 'debit', 'currency' => 'EUR', 'amount' => '1000.00000000'],
                ['account_code' => 'USER_POSITION.EUR', 'entry_type' => 'credit', 'currency' => 'EUR', 'amount' => '1000.00000000', 'wallet_id' => $wid],
            ],
            'Ouverture comptable — contrepartie externe à identifier',
            'opening_balance',
            (string) $wid
        );

        // La position utilisateur au ledger (CE wallet) == le solde du wallet.
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END), 0)
             FROM ledger_entries
            WHERE account_code = 'USER_POSITION.EUR' AND is_legacy = 0 AND wallet_id = :wid"
        );
        $stmt->execute(['wid' => $wid]);
        $this->assertSame('1000.00000000', bcadd((string) $stmt->fetchColumn(), '0', 8), 'USER_POSITION == solde wallet.');

        // Total APRÈS : aucune création ni destruction d'argent pour ce wallet.
        $totalAfter = $this->walletRow($wid)['balance'];
        $this->assertSame($totalBefore, $totalAfter, 'La migration ne crée ni ne détruit d\'argent.');

        // Double entrée équilibrée.
        $this->assertTrue(LedgerService::verifyOperation($opId));
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 4 — Suspense : jamais de « provider interne » présenté comme réel
    // ══════════════════════════════════════════════════════════════════════

    public function test_unbacked_wallet_is_suspense_never_provider(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '1000.00');
        $key = 'gl-susp-' . bin2hex(random_bytes(4));
        $this->trackKey($key);
        $opId = $this->insertOperation($u, $wid, 'opening_balance', 'EUR', '1000.00000000', $key);
        LedgerService::post(
            $opId,
            'sandbox',
            [
                ['account_code' => 'SUSPENSE.EUR', 'entry_type' => 'debit', 'currency' => 'EUR', 'amount' => '1000.00000000'],
                ['account_code' => 'USER_POSITION.EUR', 'entry_type' => 'credit', 'currency' => 'EUR', 'amount' => '1000.00000000', 'wallet_id' => $wid],
            ],
            'Ouverture comptable',
            'opening_balance',
            (string) $wid
        );

        $entries = $this->ledgerEntries($opId);
        $accounts = array_column($entries, 'account_code');
        $this->assertContains('SUSPENSE.EUR', $accounts, 'Le suspense porte la contrepartie non identifiée.');
        foreach ($accounts as $acc) {
            $this->assertStringNotContainsString('PROVIDER_ASSET', (string) $acc, 'Aucun faux provider interne.');
            $this->assertStringNotContainsString('INTERNAL', (string) $acc, 'Aucun compte INTERNAL_MIGRATION_PROVIDER.');
        }

        // Un compte SUSPENSE sans rattachement provider est visible dans le plan de comptes.
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM chart_of_accounts WHERE code = 'SUSPENSE.EUR'");
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'SUSPENSE.EUR existe au plan de comptes.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 5 — Réconciliation : écart → DISCREPANCY, jamais de correction auto
    // ══════════════════════════════════════════════════════════════════════

    public function test_balance_reconciliation_discrepancy_no_auto_correction(): void
    {
        $u      = $this->createUser();
        $wid    = $this->createWallet($u, 'EUR', '0.00');
        // Slug UNIQUE par exécution : le compte provider est hermétique au
        // test (aucun résidu d'autres tests ne peut polluer l'attendu).
        $slug = 'glrec' . bin2hex(random_bytes(3));
        $accId  = ProviderAccountService::resolve($slug, 'sandbox', 'EUR', 'safeguarding');
        $this->providerAccountIds[] = $accId;

        // Le ledger attend 900 EUR (funding de 900).
        $key = 'gl-fund-recon-' . bin2hex(random_bytes(4));
        $this->trackKey($key);
        $dep = FundingService::recordDeposit(
            $u, $wid, 'EUR', '900.00000000', $slug, $key, 'prov-ref-900', null,
            ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX)
        );
        $this->operationIds[] = $dep['operation_id'];

        // Le provider annonce 1000 EUR.
        $stmt = $this->pdo->prepare(
            'INSERT INTO provider_balances (provider_account_id, currency, available_balance, pending_balance, reported_at, source)
             VALUES (?, ?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([$accId, 'EUR', '1000.00000000', '0.00000000', 'api']);

        $report = ProviderReconciliationService::reconcileBalances('sandbox', true);

        $this->assertSame(1, $report['discrepancy'], 'Un écart doit être signalé.');
        $account = null;
        foreach ($report['accounts'] as $entry) {
            if ((int) $entry['provider_account_id'] === $accId) {
                $account = $entry;
                break;
            }
        }
        $this->assertNotNull($account, 'Le compte du test doit figurer dans le rapport.');
        $this->assertSame('discrepancy', $account['status']);
        $this->assertSame('900.00000000', $account['expected']);
        $this->assertSame('1000.00000000', $account['reported']);
        $this->assertSame('-100.00000000', bcadd((string) $account['difference'], '0', 8));

        // Aucune correction automatique : le ledger attend toujours 900.
        $expectedAfter = ProviderAccountService::expectedAssetBalance($accId, $slug, 'EUR');
        $this->assertSame('900.00000000', bcadd($expectedAfter, '0', 8), 'Aucun ajustement automatique du ledger.');

        // Traçabilité : un reconciliation_run enregistré avec l'écart.
        $stmt = $this->pdo->prepare(
            "SELECT status, expected_balance, provider_balance, difference
             FROM reconciliation_runs WHERE provider_account_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$accId]);
        $run = $stmt->fetch();
        $this->assertSame('discrepancy', $run['status']);
        $this->assertSame('-100.00000000', bcadd((string) $run['difference'], '0', 8));
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 6 — Invariant des buckets : jamais compté deux fois
    // ══════════════════════════════════════════════════════════════════════

    public function test_bucket_invariant_balance_equals_sum_of_buckets(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '1000.00');
        $ctx = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);

        // 1) Hold de 102 (montant 100 + frais 2) : available → hold.
        $holdKey = 'gl-bkt-hold-' . bin2hex(random_bytes(4));
        $this->trackKey($holdKey);
        $hold = WalletService::createHold($u, $wid, '102.00000000', 'EUR', $holdKey, 'Envoi', null, $ctx);
        $this->operationIds[] = $hold['operation_id'];

        $b = $this->walletRow($wid);
        $this->assertSame('1000.00', $b['balance']);
        $this->assertSame('898.00', $b['available_balance']);
        $this->assertSame('102.00', $b['hold_balance']);
        $this->assertInvariant($b);

        // 2) Capture : hold → in_transit (la position ne bouge pas).
        $capKey = 'gl-bkt-cap-' . bin2hex(random_bytes(4));
        $this->trackKey($capKey);
        WalletService::captureHold($hold['operation_id'], $u, $capKey, $ctx);

        $b = $this->walletRow($wid);
        $this->assertSame('102.00', $b['in_transit_balance']);
        $this->assertSame('0.00', $b['hold_balance']);
        $this->assertInvariant($b);

        // 3) Funding de 50 : entre en pending (slug unique → compte hermétique).
        $slug = 'glbkt' . bin2hex(random_bytes(3));
        $this->providerAccountIds[] = ProviderAccountService::resolve($slug, 'sandbox', 'EUR', 'safeguarding');
        $fundKey = 'gl-bkt-fund-' . bin2hex(random_bytes(4));
        $this->trackKey($fundKey);
        $deposit = FundingService::recordDeposit($u, $wid, 'EUR', '50.00000000', $slug, $fundKey, 'prov-ref-bkt', null, $ctx);
        $this->operationIds[] = $deposit['operation_id'];

        $b = $this->walletRow($wid);
        $this->assertSame('50.00', $b['pending_balance']);
        $this->assertInvariant($b);

        // 4) Settlement du dépôt : pending → available.
        $stmt = $this->pdo->prepare(
            "SELECT id FROM wallet_operations WHERE type = 'deposit' AND source_wallet_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$wid]);
        $depositOpId = (string) $stmt->fetchColumn();
        FundingService::settleDeposit($depositOpId, $u, $ctx);

        $b = $this->walletRow($wid);
        $this->assertSame('0.00', $b['pending_balance']);
        $this->assertSame('948.00', $b['available_balance']);
        $this->assertInvariant($b);
    }

    private function assertInvariant(array $b): void
    {
        $sum = bcadd(
            bcadd((string) $b['available_balance'], (string) $b['hold_balance'], 8),
            bcadd(bcadd((string) $b['pending_balance'], (string) $b['in_transit_balance'], 8), (string) $b['settlement_balance'], 8),
            8
        );
        $this->assertSame(
            bcadd((string) $b['balance'], '0', 2),
            bcadd($sum, '0', 2),
            'balance == available + hold + pending + in_transit + settlement.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 7 — Funding réel : posting ledger, jamais d'UPDATE direct,
    //          webhook dupliqué crédité une seule fois
    // ══════════════════════════════════════════════════════════════════════

    public function test_funding_ledger_posting_and_webhook_idempotence(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '0.00');
        $ctx = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);

        // Slug UNIQUE : ce test crée et nettoie son propre compte provider.
        $slug = 'glfund' . bin2hex(random_bytes(3));
        $accId = ProviderAccountService::resolve($slug, 'sandbox', 'EUR', 'safeguarding');
        $this->providerAccountIds[] = $accId;

        $key = 'gl-fund-' . bin2hex(random_bytes(4));
        $this->trackKey($key);

        $first = FundingService::recordDeposit(
            $u, $wid, 'EUR', '100.00000000', $slug, $key, 'prov-ref-100', null, $ctx
        );
        $this->operationIds[] = $first['operation_id'];

        // Posting : DEBIT PROVIDER_ASSET / CREDIT USER_POSITION — jamais
        // un UPDATE wallets SET balance = balance + X sans écriture.
        $entries = $this->ledgerEntries($first['operation_id']);
        $this->assertCount(2, $entries);
        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame('PROVIDER_ASSET.' . $slug . '.EUR', (string) $entries[0]['account_code']);
        $this->assertSame('100.00000000', (string) $entries[0]['amount']);
        $this->assertSame('credit', $entries[1]['entry_type']);
        $this->assertSame('USER_POSITION.EUR', (string) $entries[1]['account_code']);
        $this->assertSame('100.00000000', (string) $entries[1]['amount']);
        $this->assertTrue(LedgerService::verifyOperation($first['operation_id']));

        // Le wallet est crédité dans la même transaction (bucket pending).
        $b = $this->walletRow($wid);
        $this->assertSame('100.00', $b['balance']);
        $this->assertSame('100.00', $b['pending_balance']);
        $this->assertSame('0.00', $b['available_balance']);

        // Webhook dupliqué → aucune double comptabilisation.
        $dup = FundingService::recordDeposit(
            $u, $wid, 'EUR', '100.00000000', $slug, $key, 'prov-ref-100', null, $ctx
        );
        $this->assertSame($first['operation_id'], $dup['operation_id'], 'Rejeu → même opération.');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE operation_id = ?');
        $stmt->execute([$first['operation_id']]);
        $this->assertSame(2, (int) $stmt->fetchColumn(), 'Une seule paire d\'écritures malgré le rejeu.');

        $b = $this->walletRow($wid);
        $this->assertSame('100.00', $b['balance'], 'Le webhook dupliqué ne crédite pas deux fois.');

        // Montant invalide → refusé.
        try {
            $badKey = 'gl-fund-bad-' . bin2hex(random_bytes(4));
            $this->trackKey($badKey);
            FundingService::recordDeposit($u, $wid, 'EUR', '-5.00000000', $slug, $badKey, 'neg', null, $ctx);
            $this->fail('Un dépôt négatif doit être refusé.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->statusCode());
        }

        // Settlement du dépôt : pending → available (idempotent).
        FundingService::settleDeposit($first['operation_id'], $u, $ctx);
        FundingService::settleDeposit($first['operation_id'], $u, $ctx); // rejeu

        $b = $this->walletRow($wid);
        $this->assertSame('0.00', $b['pending_balance']);
        $this->assertSame('100.00', $b['available_balance']);
        $this->assertSame('100.00', $b['balance']);
    }
}
