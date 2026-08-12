<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\LedgerService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Tests du LedgerService.
 *
 * Base utilisée : `nexus_test` (isolée, JAMAIS `nexus`).
 * Stratégie d'isolation : chaque test crée un utilisateur et des wallets
 * avec des identifiants uniques (basés sur le timestamp + un compteur),
 * puis supprime tout en `tearDown`. Aucun `DROP` massif, aucune
 * suppression croisée.
 *
 * PHPUnit : 10.5.64 — PHP : 8.2.12
 */
final class LedgerServiceTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /**
     * IDs créés par le test courant (pour nettoyage ciblé en tearDown).
     * @var array{userIds:list<int>, walletIds:list<int>, operationIds:list<string>}
     */
    private array $created = [
        'userIds'      => [],
        'walletIds'    => [],
        'operationIds' => [],
    ];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        // Garde-fou supplémentaire : refuse de tourner contre la base de dev.
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail(
                'Refus de tourner contre la base "' . $dbName . '". ' .
                'Les tests doivent utiliser nexus_test uniquement.'
            );
        }

        $this->created = [
            'userIds'      => [],
            'walletIds'    => [],
            'operationIds' => [],
        ];
    }

    protected function tearDown(): void
    {
        // Nettoyage ciblé : suppression par ID, jamais de TRUNCATE.
        try {
            if (!empty($this->created['operationIds'])) {
                $placeholders = implode(',', array_fill(0, count($this->created['operationIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($placeholders)");
                $stmt->execute($this->created['operationIds']);

                $stmt = $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($placeholders)");
                $stmt->execute($this->created['operationIds']);
            }
            if (!empty($this->created['walletIds'])) {
                $placeholders = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($placeholders)");
                $stmt->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $placeholders = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                $stmt->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            // Le nettoyage ne doit pas masquer un échec de test, mais on log.
            fwrite(STDERR, '[tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers de création de fixtures
    // ─────────────────────────────────────────────────────────────────────

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
            'name'   => 'Test User ' . $suffix,
            'email'  => 'test_' . $suffix . '@example.com',
            'pwd'    => '',
            'type'   => 'personal',
            'status' => 'ACTIVE',
            'kyc'    => 'none',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    private function createWallet(int $userId, string $currency, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (:uid, :cur, :bal, :avail, 0)'
        );
        $stmt->execute([
            'uid'   => $userId,
            'cur'   => $currency,
            'bal'   => $balance,
            'avail' => $balance,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    private function getBalance(int $walletId): string
    {
        $stmt = $this->pdo->prepare('SELECT balance FROM wallets WHERE id = :id');
        $stmt->execute(['id' => $walletId]);
        $val = $stmt->fetchColumn();
        return $val === false ? '0.00' : (string) $val;
    }

    private function getAvailable(int $walletId): string
    {
        $stmt = $this->pdo->prepare('SELECT available_balance FROM wallets WHERE id = :id');
        $stmt->execute(['id' => $walletId]);
        $val = $stmt->fetchColumn();
        return $val === false ? '0.00' : (string) $val;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests obligatoires
    // ─────────────────────────────────────────────────────────────────────

    public function test_credit_produit_une_ecriture_credit(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $walletId = $this->createWallet($userId, 'EUR', '100.00');

        $operationId = LedgerService::credit(
            userId: $userId,
            walletId: $walletId,
            amount: '50.00',
            currency: 'EUR',
            type: 'deposit',
            description: 'Test credit'
        );
        $this->created['operationIds'][] = $operationId;

        // operation_id retourné
        $this->assertNotEmpty($operationId, 'credit() doit retourner un operation_id.');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $operationId,
            'operation_id doit être un UUID v4.'
        );

        // 1 wallet_operation créée
        $stmt = $this->pdo->prepare(
            'SELECT user_id, type, status, source_wallet_id, dest_wallet_id,
                    source_amount, dest_amount, idempotency_key
             FROM wallet_operations WHERE id = :id'
        );
        $stmt->execute(['id' => $operationId]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($op, 'Une wallet_operation doit être créée.');
        $this->assertSame($userId, (int) $op['user_id']);
        $this->assertSame('deposit', $op['type']);
        $this->assertSame('completed', $op['status'], 'L\'opération doit être en status=completed.');
        $this->assertNull($op['source_wallet_id']);
        $this->assertSame($walletId, (int) $op['dest_wallet_id']);
        $this->assertSame('50.00000000', (string) $op['dest_amount']);

        // exactement 1 ledger_entry
        $stmt = $this->pdo->prepare(
            'SELECT entry_type, amount, balance_after, sequence, wallet_id, wallet_currency
             FROM ledger_entries WHERE operation_id = :id'
        );
        $stmt->execute(['id' => $operationId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $entries, 'Exactement 1 ledger_entry doit être créée.');
        $this->assertSame('credit', $entries[0]['entry_type']);
        $this->assertSame('50.00000000', (string) $entries[0]['amount']);
        $this->assertSame('150.00000000', (string) $entries[0]['balance_after']);
        $this->assertSame(1, (int) $entries[0]['sequence']);
        $this->assertSame($walletId, (int) $entries[0]['wallet_id']);
        $this->assertSame('EUR', $entries[0]['wallet_currency']);

        // wallets.balance et available_balance augmentés
        $this->assertSame('150.00', $this->getBalance($walletId));
        $this->assertSame('150.00', $this->getAvailable($walletId));
    }

    public function test_debit_produit_une_ecriture_debit(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $walletId = $this->createWallet($userId, 'EUR', '200.00');

        $operationId = LedgerService::debit(
            userId: $userId,
            walletId: $walletId,
            amount: '75.00',
            currency: 'EUR',
            type: 'withdrawal'
        );
        $this->created['operationIds'][] = $operationId;

        $stmt = $this->pdo->prepare(
            'SELECT type, status, source_wallet_id, dest_wallet_id, source_amount
             FROM wallet_operations WHERE id = :id'
        );
        $stmt->execute(['id' => $operationId]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('withdrawal', $op['type']);
        $this->assertSame('completed', $op['status']);
        $this->assertSame($walletId, (int) $op['source_wallet_id']);
        $this->assertNull($op['dest_wallet_id']);
        $this->assertSame('75.00000000', (string) $op['source_amount']);

        $stmt = $this->pdo->prepare(
            'SELECT entry_type, amount, balance_after FROM ledger_entries WHERE operation_id = :id'
        );
        $stmt->execute(['id' => $operationId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $entries);
        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame('75.00000000', (string) $entries[0]['amount']);
        $this->assertSame('125.00000000', (string) $entries[0]['balance_after']);

        $this->assertSame('125.00', $this->getBalance($walletId));
        $this->assertSame('125.00', $this->getAvailable($walletId));
    }

    public function test_debit_refuse_solde_insuffisant(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $walletId = $this->createWallet($userId, 'EUR', '50.00');

        try {
            LedgerService::debit(
                userId: $userId,
                walletId: $walletId,
                amount: '100.00',
                currency: 'EUR',
                type: 'withdrawal'
            );
            $this->fail('Un débit supérieur au solde aurait dû lever une exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Solde insuffisant', $e->getMessage());
        }

        // Rollback : aucune wallet_operation, aucune ledger_entry, solde inchangé.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM wallet_operations')->fetchColumn();
        $this->assertSame(0, $count, 'Aucune wallet_operation ne doit être créée après rollback.');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
        $this->assertSame(0, $count, 'Aucune ledger_entry ne doit être créée après rollback.');

        $this->assertSame('50.00', $this->getBalance($walletId), 'Le solde doit être inchangé.');
    }

    public function test_transfer_produit_debit_et_credit(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        // Un même user ne peut pas avoir deux wallets dans la même devise
        // (uq_wallets_user_currency) : la destination appartient à un 2e user.
        $destUserId = $this->createUser($suffix . '_dest');
        $sourceId = $this->createWallet($userId, 'EUR', '500.00');
        $destId   = $this->createWallet($destUserId, 'EUR', '100.00');

        $operationId = LedgerService::transfer(
            userId: $userId,
            sourceWalletId: $sourceId,
            destWalletId:   $destId,
            sourceAmount:   '150.00',
            sourceCurrency: 'EUR',
            destAmount:     '150.00',
            destCurrency:   'EUR',
            type:           'send'
        );
        $this->created['operationIds'][] = $operationId;

        // Une seule wallet_operation
        $stmt = $this->pdo->prepare(
            'SELECT type, status, source_wallet_id, dest_wallet_id,
                    source_amount, dest_amount, source_currency, dest_currency
             FROM wallet_operations WHERE id = :id'
        );
        $stmt->execute(['id' => $operationId]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($op);
        $this->assertSame('send', $op['type']);
        $this->assertSame('completed', $op['status']);
        $this->assertSame($sourceId, (int) $op['source_wallet_id']);
        $this->assertSame($destId, (int) $op['dest_wallet_id']);
        $this->assertSame('EUR', $op['source_currency']);
        $this->assertSame('EUR', $op['dest_currency']);
        $this->assertSame('150.00000000', (string) $op['source_amount']);
        $this->assertSame('150.00000000', (string) $op['dest_amount']);

        // Exactement 2 ledger_entries
        $stmt = $this->pdo->prepare(
            'SELECT entry_type, wallet_id, amount, balance_after, sequence
             FROM ledger_entries WHERE operation_id = :id ORDER BY sequence ASC'
        );
        $stmt->execute(['id' => $operationId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $entries);

        // Même operation_id
        $this->assertSame($operationId, $this->getOperationIdFromEntry((int) $entries[0]['wallet_id'], 1));

        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame($sourceId, (int) $entries[0]['wallet_id']);
        $this->assertSame('150.00000000', (string) $entries[0]['amount']);
        $this->assertSame('350.00000000', (string) $entries[0]['balance_after']);
        $this->assertSame(1, (int) $entries[0]['sequence']);

        $this->assertSame('credit', $entries[1]['entry_type']);
        $this->assertSame($destId, (int) $entries[1]['wallet_id']);
        $this->assertSame('150.00000000', (string) $entries[1]['amount']);
        $this->assertSame('250.00000000', (string) $entries[1]['balance_after']);
        $this->assertSame(2, (int) $entries[1]['sequence']);

        // Soldes
        $this->assertSame('350.00', $this->getBalance($sourceId));
        $this->assertSame('250.00', $this->getBalance($destId));
    }

    private function getOperationIdFromEntry(int $walletId, int $sequence): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT operation_id FROM ledger_entries
             WHERE wallet_id = :wid AND sequence = :seq
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['wid' => $walletId, 'seq' => $sequence]);
        $val = $stmt->fetchColumn();
        return $val === false ? '' : (string) $val;
    }

    public function test_transfer_multidevise_conserve_les_montants(): void
    {
        $suffix = $this->uniqueSuffix();
        // Utilisateur 1 pour EUR, Utilisateur 2 pour XAF
        $eurUserId = $this->createUser($suffix);
        $xafUserId = $this->createUser($suffix . '_dest');
        $eurId = $this->createWallet($eurUserId, 'EUR', '1000.00');
        $xafId = $this->createWallet($xafUserId, 'XAF', '50000.00');

        // FX pré-calculé par le caller (LedgerService ne fait PAS de conversion)
        $operationId = LedgerService::transfer(
            userId: $eurUserId,
            sourceWalletId: $eurId,
            destWalletId:   $xafId,
            sourceAmount:   '100.00',
            sourceCurrency: 'EUR',
            destAmount:     '65595.70',
            destCurrency:   'XAF',
            type:           'convert',
            metadata:       ['fx_rate' => '655.957']
        );
        $this->created['operationIds'][] = $operationId;

        $stmt = $this->pdo->prepare(
            'SELECT source_currency, dest_currency, source_amount, dest_amount, fx_rate
             FROM wallet_operations WHERE id = :id'
        );
        $stmt->execute(['id' => $operationId]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('EUR', $op['source_currency']);
        $this->assertSame('XAF', $op['dest_currency']);
        $this->assertSame('100.00000000', (string) $op['source_amount']);
        $this->assertSame('65595.70000000', (string) $op['dest_amount']);
        // LedgerService ne calcule PAS de taux FX — la colonne doit être NULL.
        $this->assertNull($op['fx_rate'], 'fx_rate ne doit pas être calculé par LedgerService.');

        // 2 écritures avec devises distinctes
        $stmt = $this->pdo->prepare(
            'SELECT entry_type, wallet_currency, amount FROM ledger_entries
             WHERE operation_id = :id ORDER BY sequence ASC'
        );
        $stmt->execute(['id' => $operationId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $entries);
        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame('EUR', $entries[0]['wallet_currency']);
        $this->assertSame('100.00000000', (string) $entries[0]['amount']);
        $this->assertSame('credit', $entries[1]['entry_type']);
        $this->assertSame('XAF', $entries[1]['wallet_currency']);
        $this->assertSame('65595.70000000', (string) $entries[1]['amount']);

        // Soldes
        $this->assertSame('900.00', $this->getBalance($eurId));
        $this->assertSame('115595.70', $this->getBalance($xafId));
    }

    public function test_transfer_refuse_meme_wallet(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $walletId = $this->createWallet($userId, 'EUR', '100.00');

        try {
            LedgerService::transfer(
                userId: $userId,
                sourceWalletId: $walletId,
                destWalletId:   $walletId,
                sourceAmount:   '10.00',
                sourceCurrency: 'EUR',
                destAmount:     '10.00',
                destCurrency:   'EUR'
            );
            $this->fail('Un transfert vers le même wallet aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('doivent être distincts', $e->getMessage());
        }

        // Aucun effet
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM wallet_operations')->fetchColumn();
        $this->assertSame(0, $count);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
        $this->assertSame(0, $count);
        $this->assertSame('100.00', $this->getBalance($walletId));
    }

    public function test_credit_refuse_mauvaise_devise(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $walletId = $this->createWallet($userId, 'EUR', '100.00');

        try {
            LedgerService::credit(
                userId: $userId,
                walletId: $walletId,
                amount: '50.00',
                currency: 'XAF'  // ≠ EUR
            );
            $this->fail('Un crédit avec une mauvaise devise aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Devise incohérente', $e->getMessage());
        }

        // Aucune écriture créée
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM wallet_operations')->fetchColumn();
        $this->assertSame(0, $count);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
        $this->assertSame(0, $count);
        $this->assertSame('100.00', $this->getBalance($walletId));
    }

    public function test_operation_equilibree(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $destUserId = $this->createUser($suffix . '_dest');
        $sourceId = $this->createWallet($userId, 'EUR', '300.00');
        $destId   = $this->createWallet($destUserId, 'EUR', '0.00');

        $operationId = LedgerService::transfer(
            userId: $userId,
            sourceWalletId: $sourceId,
            destWalletId:   $destId,
            sourceAmount:   '100.00',
            sourceCurrency: 'EUR',
            destAmount:     '100.00',
            destCurrency:   'EUR'
        );
        $this->created['operationIds'][] = $operationId;

        $this->assertTrue(
            LedgerService::verifyOperation($operationId),
            'verifyOperation() doit retourner true pour un transfert équilibré.'
        );

        // debit == credit par devise
        $stmt = $this->pdo->prepare(
            'SELECT
                SUM(CASE WHEN entry_type = \'debit\'  THEN amount ELSE 0 END) AS total_debit,
                SUM(CASE WHEN entry_type = \'credit\' THEN amount ELSE 0 END) AS total_credit
             FROM ledger_entries WHERE operation_id = :id AND wallet_currency = :cur'
        );
        $stmt->execute(['id' => $operationId, 'cur' => 'EUR']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('100.00000000', (string) $row['total_debit']);
        $this->assertEquals('100.00000000', (string) $row['total_credit']);
    }

    public function test_verify_operation_inconnue_retourne_false(): void
    {
        $unknownId = '00000000-0000-4000-8000-000000000000';
        $this->assertFalse(
            LedgerService::verifyOperation($unknownId),
            'verifyOperation() doit retourner false pour un UUID inconnu.'
        );
    }

    public function test_transfer_est_atomique(): void
    {
        // Test d'atomicité : on provoque une erreur en injectant une devise
        // source incorrecte APRÈS la lecture du wallet (mais avant la deuxième
        // écriture). Ici, on utilise un montant invalide pour la source.
        // LedgerService::transfer vérifie les montants EN PREMIER (avant la
        // transaction), donc on simule plutôt un cas où l'assertionCurrencyMatches
        // échoue : devise correcte pour la source mais mismatch sur la dest.
        //
        // Pour tester l'atomicité "réelle" en cas d'erreur pendant la transaction,
        // on simule en passant une devise volontairement incompatible pour la dest.

        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $destUserId = $this->createUser($suffix . '_dest');
        $sourceId = $this->createWallet($userId, 'EUR', '500.00');
        $destId   = $this->createWallet($destUserId, 'EUR', '100.00');

        // Snapshot des soldes avant
        $srcBefore = $this->getBalance($sourceId);
        $dstBefore = $this->getBalance($destId);

        // On provoque une erreur après le lock des 2 wallets :
        // devise mismatch sur la destination (source=EUR, dest=XAF mais wallet dest=EUR)
        try {
            LedgerService::transfer(
                userId: $userId,
                sourceWalletId: $sourceId,
                destWalletId:   $destId,
                sourceAmount:   '100.00',
                sourceCurrency: 'EUR',
                destAmount:     '65595.70',
                destCurrency:   'XAF'  // wallet dest est en EUR !
            );
            $this->fail('Le mismatch de devise sur la destination aurait dû lever une exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Devise incohérente', $e->getMessage());
        }

        // Vérification atomicité :
        // 1) Soldes inchangés
        $this->assertSame($srcBefore, $this->getBalance($sourceId), 'Solde source doit être inchangé.');
        $this->assertSame($dstBefore, $this->getBalance($destId), 'Solde destination doit être inchangé.');

        // 2) Aucune wallet_operation créée
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM wallet_operations')->fetchColumn();
        $this->assertSame(0, $count, 'Aucune wallet_operation après rollback.');

        // 3) Aucune ledger_entry créée
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
        $this->assertSame(0, $count, 'Aucune ledger_entry après rollback.');
    }
}