<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Models\TransferRequest;
use Nexus\Models\TransferResult;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Tests de WalletService::transferMultiCurrency (Phase D).
 *
 * Base utilisée : `nexus_test` UNIQUEMENT (garde-fou en setUp).
 * Stratégie d'isolation : chaque test crée ses fixtures (user + wallets +
 * clés idempotence + lignes cache FX) avec des IDs/suffixes uniques, puis
 * supprime EXACTEMENT ces lignes en tearDown. Aucun DROP, aucun TRUNCATE,
 * aucune modification de la base `nexus`.
 *
 * Couverture exigée (checklist Phase D) :
 *   - transfert multi-devise réussi ;
 *   - fx_rate / fx_source persistés dans wallet_operations ;
 *   - UNE seule wallet_operations et UN seul operation_id ;
 *   - exactement DEUX ledger_entries (debit source, credit destination) ;
 *   - idempotence (replay identique, aucune double écriture) ;
 *   - rollback complet en cas d'échec ;
 *   - précision 8 décimales HALF_UP ;
 *   - montant source invalide (négatif / mal formaté) ;
 *   - mauvaise devise source / destination ;
 *   - wallets identiques ;
 *   - solde disponible insuffisant ;
 *   - spread ignoré ;
 *   - concurrence : clé déjà en processing.
 */
final class WalletServiceTransferTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds:list<int>, walletIds:list<int>, operationIds:list<string>, keys:list<array{key:string,userId:int}>, cacheRowIds:list<int>} */
    private array $created = [
        'userIds'      => [],
        'walletIds'    => [],
        'operationIds' => [],
        'keys'         => [],
        'cacheRowIds'  => [],
    ];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail(
                'Refus de tourner contre la base "' . $dbName . '". '
                . 'Les tests de transfert doivent utiliser nexus_test uniquement.'
            );
        }

        $this->created = [
            'userIds'      => [],
            'walletIds'    => [],
            'operationIds' => [],
            'keys'         => [],
            'cacheRowIds'  => [],
        ];
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

            // Lignes fx_rates_cache insérées par le test.
            if (!empty($this->created['cacheRowIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['cacheRowIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM fx_rates_cache WHERE id IN ($ph)");
                $stmt->execute($this->created['cacheRowIds']);
            }

            // Écritures comptables (ledger_entries puis wallet_operations).
            if (!empty($this->created['operationIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['operationIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($ph)");
                $stmt->execute($this->created['operationIds']);
                $stmt = $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($ph)");
                $stmt->execute($this->created['operationIds']);
            }

            // Wallets puis users.
            if (!empty($this->created['walletIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)");
                $stmt->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)");
                $stmt->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[WalletServiceTransferTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers de fixtures (IDs uniques + nettoyage ciblé)
    // ──────────────────────────────────────────────────────────────────────

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
            'name'   => 'TransferTest ' . $suffix,
            'email'  => 'tt_' . $suffix . '@nexus-test.local',
            'pwd'    => '',
            'type'   => 'personal',
            'status' => 'ACTIVE',
            'kyc'    => 'none',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    private function createWallet(int $userId, string $currency, string $balance, string $hold = '0.00'): int
    {
        $available = bcsub($balance, $hold, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance,
                                 pending_balance, in_transit_balance, settlement_balance)
             VALUES (:uid, :cur, :bal, :avail, :hold, :pend, :intrans, :settle)'
        );
        $stmt->execute([
            'uid'     => $userId,
            'cur'     => $currency,
            'bal'     => $balance,
            'avail'   => $available,
            'hold'    => $hold,
            'pend'    => '0.00',
            'intrans' => '0.00',
            'settle'  => '0.00',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    private function insertCacheRow(
        string $base,
        string $quote,
        string $rate,
        string $source,
        string $spread = '0.0000',
        int $ttlSeconds = 86400
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at)
             VALUES (:b, :q, :r, :s, :src, :f, :e)'
        );
        $stmt->execute([
            'b'   => $base,
            'q'   => $quote,
            'r'   => $rate,
            's'   => $spread,
            'src' => $source,
            'f'   => gmdate('Y-m-d H:i:s'),
            'e'   => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['cacheRowIds'][] = $id;
        return $id;
    }

    private function deleteCachePair(string $base, string $quote): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE base_currency = :b AND quote_currency = :q');
        $stmt->execute(['b' => $base, 'q' => $quote]);
    }

    private function insertKeyDirectly(string $key, int $userId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO idempotency_keys (idempotency_key, user_id, operation_id, response_json, status, expires_at, environment)
             VALUES (:key, :uid, NULL, NULL, :status, :exp, :env)'
        );
        $stmt->execute([
            'key'    => $key,
            'uid'    => $userId,
            'status' => $status,
            'exp'    => gmdate('Y-m-d H:i:s', time() + 86400),
            'env'    => \Nexus\Providers\ProviderConfig::defaultEnvironment(),
        ]);
        $this->created['keys'][] = ['key' => $key, 'userId' => $userId];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers de lecture / assertions
    // ──────────────────────────────────────────────────────────────────────

    private function getBalance(int $walletId): string
    {
        $stmt = $this->pdo->prepare('SELECT balance FROM wallets WHERE id = :id');
        $stmt->execute(['id' => $walletId]);
        $val = $stmt->fetchColumn();
        return $val === false ? '' : (string) $val;
    }

    private function countWalletOperations(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM wallet_operations WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private function countLedgerEntries(string $operationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE operation_id = :id');
        $stmt->execute(['id' => $operationId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|false */
    private function getOperation(string $operationId): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, type, status, source_wallet_id, source_currency, source_amount,
                    dest_wallet_id, dest_currency, dest_amount, fx_rate, fx_source, idempotency_key
             FROM wallet_operations WHERE id = :id'
        );
        $stmt->execute(['id' => $operationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function getEntries(string $operationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sequence, entry_type, wallet_id, wallet_currency, amount, balance_after
             FROM ledger_entries WHERE operation_id = :id ORDER BY sequence ASC'
        );
        $stmt->execute(['id' => $operationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertUuid(string $value): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $value,
            'operation_id doit être un UUID v4'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Transfert multi-devise réussi + fx_rate/fx_source persistés
    //    + une seule wallet_operations + deux ledger_entries
    // ──────────────────────────────────────────────────────────────────────

    public function test_transfert_multidevise_reussi_avec_fx_persiste(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '1000.00');
        $destWallet   = $this->createWallet($userId, 'XAF', '0.00');
        $key          = 'ok_' . $suffix;
        $this->created['keys'][] = ['key' => $key, 'userId' => $userId];

        // Source FX réelle (table fx_rates_cache) : plus aucun repli manuel.
        $this->deleteCachePair('EUR', 'XAF');
        $this->insertCacheRow('EUR', 'XAF', '655.95700000', 'fx_provider_test');

        $req = new TransferRequest(
            $userId,
            $sourceWallet,
            $destWallet,
            '100.00',
            'EUR',
            'XAF',
            'convert',
            $key,
            'Test transfert multi-devise',
            ['phase' => 'D']
        );

        $result = WalletService::transferMultiCurrency($req);
        $this->created['operationIds'][] = $result->getOperationId();

        // Résultat
        $this->assertInstanceOf(TransferResult::class, $result);
        $this->assertUuid($result->getOperationId());
        $this->assertSame('100.00', $result->getSourceAmount());
        $this->assertSame('655.95700000', $result->getFxRate());
        $this->assertSame('fx_provider_test', $result->getFxSource());
        $this->assertSame('completed', $result->getStatus());
        $expectedDest = bcadd(bcmul('100.00', '655.95700000', 10), '0.000000005', 8);
        $this->assertSame($expectedDest, $result->getDestAmount());

        // UNE seule wallet_operations, avec fx_rate / fx_source persistés.
        $this->assertSame(1, $this->countWalletOperations($userId));
        $op = $this->getOperation($result->getOperationId());
        $this->assertIsArray($op);
        $this->assertSame('convert', $op['type']);
        $this->assertSame('completed', $op['status']);
        $this->assertSame('655.95700000', (string) $op['fx_rate']);
        $this->assertSame('fx_provider_test', $op['fx_source']);
        $this->assertSame('XAF', $op['dest_currency']);
        $this->assertSame($expectedDest, (string) $op['dest_amount']);

        // Exactement QUATRE legs liés au même operation_id, équilibrés par devise.
        $this->assertSame(4, $this->countLedgerEntries($result->getOperationId()));
        $entries = $this->getEntries($result->getOperationId());
        $this->assertCount(4, $entries);
        $this->assertSame(1, (int) $entries[0]['sequence']);
        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame($sourceWallet, (int) $entries[0]['wallet_id']);
        $this->assertSame('EUR', $entries[0]['wallet_currency']);
        $this->assertSame('100.00000000', (string) $entries[0]['amount']);
        $this->assertSame(2, (int) $entries[1]['sequence']);
        $this->assertSame('credit', $entries[1]['entry_type']);
        $this->assertNull($entries[1]['wallet_id']);
        $this->assertSame('EUR', $entries[1]['wallet_currency']);
        $this->assertSame('100.00000000', (string) $entries[1]['amount']);
        $this->assertSame(3, (int) $entries[2]['sequence']);
        $this->assertSame('debit', $entries[2]['entry_type']);
        $this->assertNull($entries[2]['wallet_id']);
        $this->assertSame('XAF', $entries[2]['wallet_currency']);
        $this->assertSame($expectedDest, (string) $entries[2]['amount']);
        $this->assertSame(4, (int) $entries[3]['sequence']);
        $this->assertSame('credit', $entries[3]['entry_type']);
        $this->assertSame($destWallet, (int) $entries[3]['wallet_id']);
        $this->assertSame('XAF', $entries[3]['wallet_currency']);
        $this->assertSame($expectedDest, (string) $entries[3]['amount']);

        // Les DEUX wallets sont mis à jour.
        $this->assertSame('900.00', $this->getBalance($sourceWallet));
        $destBalance2dp = bcadd($expectedDest, '0', 2);
        $this->assertSame($destBalance2dp, $this->getBalance($destWallet));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Idempotence : même clé → même operation_id, aucune double écriture
    // ──────────────────────────────────────────────────────────────────────

    public function test_idempotence_retourne_meme_operation_sans_double_ecriture(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '500.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');
        $key          = 'idem_' . $suffix;
        $this->created['keys'][] = ['key' => $key, 'userId' => $userId];

        // Source FX réelle : 50 EUR → 54.35 USD @ 1.087.
        $this->deleteCachePair('EUR', 'USD');
        $this->insertCacheRow('EUR', 'USD', '1.08700000', 'fx_provider_test');

        $makeRequest = function () use ($userId, $sourceWallet, $destWallet, $key): TransferRequest {
            return new TransferRequest(
                $userId,
                $sourceWallet,
                $destWallet,
                '50.00',
                'EUR',
                'USD',
                'convert',
                $key
            );
        };

        // 1re exécution : écriture comptable réelle.
        $first = WalletService::transferMultiCurrency($makeRequest());
        $this->created['operationIds'][] = $first->getOperationId();

        // 2e exécution (même clé) : replay de la réponse, aucune écriture.
        $second = WalletService::transferMultiCurrency($makeRequest());

        // Même operation_id et même résultat.
        $this->assertSame($first->getOperationId(), $second->getOperationId());
        $this->assertSame($first->getSourceAmount(), $second->getSourceAmount());
        $this->assertSame($first->getDestAmount(), $second->getDestAmount());
        $this->assertSame($first->getFxRate(), $second->getFxRate());
        $this->assertSame($first->getFxSource(), $second->getFxSource());
        $this->assertSame($first->getStatus(), $second->getStatus());

        // Toujours UNE seule wallet_operations et QUATRE legs FX.
        $this->assertSame(1, $this->countWalletOperations($userId));
        $this->assertSame(4, $this->countLedgerEntries($first->getOperationId()));

        // Soldes débités UNE seule fois (50 EUR → 54.35 USD @ 1.087).
        $this->assertSame('450.00', $this->getBalance($sourceWallet));
        $this->assertSame('54.35', $this->getBalance($destWallet));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3/4. Devise source / destination incorrecte
    // ──────────────────────────────────────────────────────────────────────

    public function test_devise_source_incorrecte(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '500.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '10.00', 'USD', 'USD', 'convert');

        try {
            WalletService::transferMultiCurrency($req);
            $this->fail('Une devise source incohérente aurait dû être rejetée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Devise incohérente', $e->getMessage());
            $this->assertStringContainsString('source', $e->getMessage());
        }

        // Aucune écriture comptable, soldes intacts.
        $this->assertSame(0, $this->countWalletOperations($userId));
        $this->assertSame('500.00', $this->getBalance($sourceWallet));
        $this->assertSame('0.00', $this->getBalance($destWallet));
    }

    public function test_devise_destination_incorrecte(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '500.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '10.00', 'EUR', 'XAF', 'convert');

        try {
            WalletService::transferMultiCurrency($req);
            $this->fail('Une devise destination incohérente aurait dû être rejetée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Devise incohérente', $e->getMessage());
            $this->assertStringContainsString('destination', $e->getMessage());
        }

        $this->assertSame(0, $this->countWalletOperations($userId));
        $this->assertSame('500.00', $this->getBalance($sourceWallet));
        $this->assertSame('0.00', $this->getBalance($destWallet));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5/6. Montant négatif / mal formaté
    // ──────────────────────────────────────────────────────────────────────

    public function test_montant_negatif(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '500.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '-10.00', 'EUR', 'USD', 'convert');

        try {
            WalletService::transferMultiCurrency($req);
            $this->fail('Un montant négatif aurait dû être rejeté.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Montant invalide', $e->getMessage());
        }

        $this->assertSame(0, $this->countWalletOperations($userId));
        $this->assertSame('500.00', $this->getBalance($sourceWallet));
    }

    public function test_montant_mal_formate(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '500.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '12abc', 'EUR', 'USD', 'convert');

        try {
            WalletService::transferMultiCurrency($req);
            $this->fail('Un montant mal formaté aurait dû être rejeté.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Montant invalide', $e->getMessage());
        }

        $this->assertSame(0, $this->countWalletOperations($userId));
        $this->assertSame('500.00', $this->getBalance($sourceWallet));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Wallets identiques
    // ──────────────────────────────────────────────────────────────────────

    public function test_wallets_identiques(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '500.00');

        $req = new TransferRequest($userId, $sourceWallet, $sourceWallet, '10.00', 'EUR', 'EUR', 'send');

        try {
            WalletService::transferMultiCurrency($req);
            $this->fail('Un transfert vers le même wallet aurait dû être rejeté.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('distincts', $e->getMessage());
        }

        $this->assertSame(0, $this->countWalletOperations($userId));
        $this->assertSame('500.00', $this->getBalance($sourceWallet));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Solde disponible insuffisant (balance - hold_balance)
    // ──────────────────────────────────────────────────────────────────────

    public function test_solde_disponible_insuffisant(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        // balance 100.00, hold 80.00 → disponible 20.00
        $sourceWallet = $this->createWallet($userId, 'EUR', '100.00', '80.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');

        // Le taux est résolu AVANT le contrôle de solde : la source FX doit
        // exister pour que le test atteigne le refus attendu.
        $this->deleteCachePair('EUR', 'USD');
        $this->insertCacheRow('EUR', 'USD', '1.08700000', 'fx_provider_test');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '30.00', 'EUR', 'USD', 'convert');

        try {
            WalletService::transferMultiCurrency($req);
            $this->fail('Un débit supérieur au solde disponible aurait dû être rejeté.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Solde disponible insuffisant', $e->getMessage());
        }

        // Aucune écriture comptable, aucun solde modifié.
        $this->assertSame(0, $this->countWalletOperations($userId));
        $this->assertSame('100.00', $this->getBalance($sourceWallet));
        $this->assertSame('0.00', $this->getBalance($destWallet));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. Rollback complet après échec
    //    (échec DANS la transaction : violation UNIQUE uq_op_idempotency sur
    //    wallet_operations.idempotency_key → rollback de tout)
    // ──────────────────────────────────────────────────────────────────────

    public function test_rollback_complet_apres_echec(): void
    {
        $suffix = $this->uniqueSuffix();
        $key    = 'rb_' . $suffix;

        // Utilisateur A : premier transfert avec la clé K.
        $userA = $this->createUser($suffix . '_a');
        $srcA  = $this->createWallet($userA, 'EUR', '500.00');
        $dstA  = $this->createWallet($userA, 'USD', '0.00');
        $this->created['keys'][] = ['key' => $key, 'userId' => $userA];

        // Source FX réelle requise pour le transfert nominal de A.
        $this->deleteCachePair('EUR', 'USD');
        $this->insertCacheRow('EUR', 'USD', '1.08700000', 'fx_provider_test');

        $reqA = new TransferRequest($userA, $srcA, $dstA, '50.00', 'EUR', 'USD', 'convert', $key);
        $okA  = WalletService::transferMultiCurrency($reqA);
        $this->created['operationIds'][] = $okA->getOperationId();

        // Utilisateur B : MÊME clé K → l'INSERT de wallet_operations viole la
        // contrainte globale uq_op_idempotency DANS la transaction.
        $userB = $this->createUser($suffix . '_b');
        $srcB  = $this->createWallet($userB, 'EUR', '500.00');
        $dstB  = $this->createWallet($userB, 'USD', '0.00');
        $this->created['keys'][] = ['key' => $key, 'userId' => $userB];

        $reqB = new TransferRequest($userB, $srcB, $dstB, '25.00', 'EUR', 'USD', 'convert', $key);

        try {
            WalletService::transferMultiCurrency($reqB);
            $this->fail('Le transfert B aurait dû échouer (clé d\'idempotence déjà utilisée).');
        } catch (RuntimeException $e) {
            // PDOException (duplicate key sur uq_op_idempotency) étend RuntimeException.
            $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        }

        // Rollback complet pour B : aucune écriture comptable, soldes intacts.
        $this->assertSame(1, $this->countWalletOperations($userA));
        $this->assertSame(0, $this->countWalletOperations($userB));
        $this->assertSame('500.00', $this->getBalance($srcB));
        $this->assertSame('0.00', $this->getBalance($dstB));

        // La clé de B est mémorisée en erreur (fail() appelé après rollback).
        $stmt = $this->pdo->prepare(
            'SELECT status FROM idempotency_keys WHERE idempotency_key = :k AND user_id = :u'
        );
        $stmt->execute(['k' => $key, 'u' => $userB]);
        $this->assertSame('error', $stmt->fetchColumn());

        // L'opération de A reste intacte.
        $this->assertSame(4, $this->countLedgerEntries($okA->getOperationId()));
        $this->assertSame('450.00', $this->getBalance($srcA));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. Précision : arrondi HALF_UP à 8 décimales
    // ──────────────────────────────────────────────────────────────────────

    public function test_precision_arrondi_half_up_8_decimales(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '1000.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');

        // Taux et montant provoquant un produit avec un 9ème chiffre significatif >= 5.
        // 0.00000001 * 5.50000000 = 0.000000055
        // Arrondi HALF_UP à 8 dp -> 0.00000006
        $this->deleteCachePair('EUR', 'USD');
        $this->insertCacheRow('EUR', 'USD', '5.50000000', 'manual');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '0.00000001', 'EUR', 'USD', 'convert');
        $result = WalletService::transferMultiCurrency($req);
        $this->created['operationIds'][] = $result->getOperationId();

        $this->assertSame('0.00000006', $result->getDestAmount());

        // La valeur persistée en ledger (DECIMAL(20,8)) est identique.
        $entries = $this->getEntries($result->getOperationId());
        $this->assertCount(4, $entries);
        $this->assertSame('0.00000006', (string) $entries[3]['amount']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 11. Spread stocké mais ignoré
    // ──────────────────────────────────────────────────────────────────────

    public function test_spread_pct_stocke_mais_ignore(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '1000.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');

        // Cache avec spread non nul : le taux appliqué reste le taux brut.
        $this->deleteCachePair('EUR', 'USD');
        $this->insertCacheRow('EUR', 'USD', '1.50000000', 'manual', '0.5000');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '100.00', 'EUR', 'USD', 'convert');
        $result = WalletService::transferMultiCurrency($req);
        $this->created['operationIds'][] = $result->getOperationId();

        // dest = 100 × 1.5 = 150 (le spread 0.5 % n'altère RIEN).
        $this->assertSame('1.50000000', $result->getFxRate());
        $this->assertSame('150.00000000', $result->getDestAmount());

        $op = $this->getOperation($result->getOperationId());
        $this->assertSame('1.50000000', (string) $op['fx_rate']);
        $this->assertSame('150.00000000', (string) $op['dest_amount']);
        $this->assertSame('150.00', $this->getBalance($destWallet));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 12. Concurrence : clé déjà en processing → refus, aucune écriture
    // ──────────────────────────────────────────────────────────────────────

    public function test_concurrence_operation_deja_processing(): void
    {
        $suffix       = $this->uniqueSuffix();
        $userId       = $this->createUser($suffix);
        $sourceWallet = $this->createWallet($userId, 'EUR', '500.00');
        $destWallet   = $this->createWallet($userId, 'USD', '0.00');
        $key          = 'busy_' . $suffix;

        // Simule une requête concurrente qui a déjà réservé la clé.
        $this->insertKeyDirectly($key, $userId, 'processing');

        $req = new TransferRequest($userId, $sourceWallet, $destWallet, '50.00', 'EUR', 'USD', 'convert', $key);

        try {
            WalletService::transferMultiCurrency($req);
            $this->fail('Une opération déjà en cours aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Opération déjà en cours', $e->getMessage());
        }

        // Aucune écriture comptable, soldes intacts.
        $this->assertSame(0, $this->countWalletOperations($userId));
        $this->assertSame('500.00', $this->getBalance($sourceWallet));
        $this->assertSame('0.00', $this->getBalance($destWallet));
    }
}
