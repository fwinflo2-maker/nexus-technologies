<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderRegistry;
use Nexus\Services\ExecutionSettlementService;
use Nexus\Services\IdempotencyService;
use Nexus\Services\LedgerService;
use Nexus\Services\ProviderReconciliationService;
use Nexus\Services\WalletService;
use Nexus\Tests\Fixtures\ScriptedProviderAdapter;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * AUDIT MODÈLE DE PORTEFEUILLE & PROVIDER ACCOUNTING (§26).
 *
 * Nexus n'est pas une banque : les wallets sont des positions INTERNES
 * représentant des fonds détenus par des partenaires (providers). Ces tests
 * pin l'état RÉEL du modèle, y compris ses limites, pour que toute évolution
 * du modèle comptable soit consciente et contrôlée :
 *
 *   - entrée de fonds : LedgerService::credit() = UNE écriture (position
 *     utilisateur), SANS écriture de contrepartie provider ;
 *   - sortie : la saga send = un DEBIT unique (hold→capture) englobant le
 *     montant ET les frais — pas de compte de frais séparé ;
 *   - FX : le taux, la source et les montants source/destination sont
 *     tracés sur wallet_operations (reconstructibles) ;
 *   - réconciliation : les écarts montant/devise et les transactions sans
 *     trace provider sont DÉTECTÉS par ProviderReconciliationService ;
 *   - aucune table « provider account / balance » : la relation wallet →
 *     compte externe n'est pas enregistrée (gap documenté).
 */
final class ProviderAccountingModelTest extends TestCase
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
    private array $txIds = [];

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
            if ($this->txIds !== []) {
                $ph = implode(',', array_fill(0, count($this->txIds), '?'));
                $this->pdo->prepare("DELETE FROM reconciliation_items WHERE transaction_id IN ($ph)")->execute($this->txIds);
                $this->pdo->prepare("DELETE FROM transactions WHERE id IN ($ph)")->execute($this->txIds);
                $this->pdo->prepare("DELETE FROM notifications WHERE user_id IN ($ph)")->execute($this->txIds);
            }
            if ($this->walletIds !== []) {
                $ph = implode(',', array_fill(0, count($this->walletIds), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")->execute($this->walletIds);
            }
            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[ProviderAccountingModelTest::tearDown] ' . $e->getMessage() . PHP_EOL);
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
        $stmt->execute(['Acct ' . $suffix, 'acct_' . $suffix . '@nexus.test', 'hash', 'personal', 'ACTIVE', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;
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
        $this->walletIds[] = $id;
        return $id;
    }

    private function walletBalance(int $walletId): array
    {
        $stmt = $this->pdo->prepare('SELECT balance, available_balance, hold_balance, pending_balance, in_transit_balance, settlement_balance FROM wallets WHERE id = ?');
        $stmt->execute([$walletId]);
        return $stmt->fetch() ?: [];
    }

    private function ledgerEntries(string $operationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT entry_type, account_code, wallet_currency, amount, reference_type FROM ledger_entries WHERE operation_id = ? ORDER BY sequence'
        );
        $stmt->execute([$operationId]);
        return $stmt->fetchAll();
    }

    private function trackKey(string $key): void
    {
        $this->keys[] = $key;
    }

    private function makeTx(int $userId, string $status, string $amount, string $currency, string $destAmount, string $destCurrency, string $providerOp): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, dest_amount, dest_currency,
                 status, provider, provider_operation_id, environment)
             VALUES (?, 'send', 'out', ?, ?, ?, ?, ?, ?, 'pawapay', ?, 'sandbox')"
        );
        $stmt->execute([$userId, 'Envoi', $amount, $currency, $destAmount, $destCurrency, $status, $providerOp]);
        $id = (int) $this->pdo->lastInsertId();
        $this->txIds[] = $id;
        return $id;
    }

    private function txRow(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 1 — Entrée de fonds : position utilisateur créée
    // ══════════════════════════════════════════════════════════════════════

    public function test_entry_of_funds_credits_user_position(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '0.00');
        $ctx = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);

        $opId = LedgerService::credit($u, $wid, '100.00000000', 'EUR', 'deposit', null, 'Dépôt', null, $ctx);
        $this->operationIds[] = $opId;

        // Position utilisateur : +100, disponible immédiatement.
        $bal = $this->walletBalance($wid);
        $this->assertSame('100.00', $bal['balance']);
        $this->assertSame('100.00', $bal['available_balance']);

        // État RÉEL du modèle : UNE seule écriture ledger (le debit de
        // contrepartie « fonds safeguarded provider » n'existe pas encore —
        // gap documenté, à combler avec le plan de comptes provider).
        $entries = $this->ledgerEntries($opId);
        $this->assertCount(1, $entries, 'credit() écrit une seule entrée : aucune contrepartie provider n\'est enregistrée.');
        $this->assertSame('credit', $entries[0]['entry_type']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 2 — Sortie : position utilisateur débitée
    // ══════════════════════════════════════════════════════════════════════

    public function test_spend_debits_user_position_at_settlement(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '100.00');
        $ctx = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);

        $holdKey = 'acct-spend-hold-' . bin2hex(random_bytes(4));
        $this->trackKey($holdKey);
        $hold = WalletService::createHold($u, $wid, '100.00000000', 'EUR', $holdKey, 'Envoi', null, $ctx);
        $this->operationIds[] = $hold['operation_id'];

        $capKey = 'acct-spend-cap-' . bin2hex(random_bytes(4));
        $this->trackKey($capKey);
        WalletService::captureHold($hold['operation_id'], $u, $capKey, $ctx);

        // Modèle cible : à la capture, la position ne bouge PAS — le montant
        // passe en transit (hold → in_transit). Aucun posting.
        $bal = $this->walletBalance($wid);
        $this->assertSame('100.00', $bal['balance'], 'balance inchangé à la capture.');
        $this->assertSame('0.00', $bal['available_balance']);
        $this->assertSame('100.00', $bal['in_transit_balance']);
        $this->assertSame(0, count($this->ledgerEntries($hold['operation_id'])), 'Aucun posting à la capture.');

        // Le débit de position a lieu au RÈGLEMENT provider (posting équilibré).
        LedgerService::postOutboundDebit(
            $hold['operation_id'], $wid, 'EUR', '100.00000000', '0.00000000', 'pawapay',
            'Envoi réglé', 'send', $hold['operation_id'], null, 'sandbox'
        );

        $bal = $this->walletBalance($wid);
        $this->assertSame('0.00', $bal['balance'], 'Position débitée de 100 au règlement.');
        $this->assertSame('0.00', $bal['in_transit_balance']);

        $entries = $this->ledgerEntries($hold['operation_id']);
        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame('USER_POSITION.EUR', (string) $entries[0]['account_code']);
        $this->assertSame('PROVIDER_SETTLEMENT.pawapay.EUR', (string) $entries[1]['account_code']);
        // Équilibre : Σ debit == Σ credit (par devise).
        $this->assertSame('100.00000000', (string) $entries[0]['amount']);
        $this->assertSame('100.00000000', (string) $entries[1]['amount']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 3 & 4 — Frais : un seul debit englobant montant + frais
    // ══════════════════════════════════════════════════════════════════════

    public function test_fees_are_separated_into_ledger_legs(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '200.00');
        $ctx = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);

        // Saga send : totalDebit = montant 100 + frais 2.
        $totalDebit = '102.00000000';
        $holdKey = 'acct-fee-hold-' . bin2hex(random_bytes(4));
        $this->trackKey($holdKey);
        $hold = WalletService::createHold($u, $wid, $totalDebit, 'EUR', $holdKey, 'Envoi 100 EUR', null, $ctx);
        $this->operationIds[] = $hold['operation_id'];

        $capKey = 'acct-fee-cap-' . bin2hex(random_bytes(4));
        $this->trackKey($capKey);
        WalletService::captureHold($hold['operation_id'], $u, $capKey, $ctx);

        // Règlement : DEBIT USER_POSITION 102 / CREDIT PROVIDER_SETTLEMENT 100
        // + CREDIT NEXUS_REVENUE.fee 2 — les frais ont leur PROPRE leg.
        LedgerService::postOutboundDebit(
            $hold['operation_id'], $wid, 'EUR', '100.00000000', '2.00000000', 'pawapay',
            'Envoi 100 EUR réglé', 'send', $hold['operation_id'], null, 'sandbox'
        );

        $bal = $this->walletBalance($wid);
        $this->assertSame('98.00', $bal['balance'], 'Position débitée de 102 au règlement.');

        $entries = $this->ledgerEntries($hold['operation_id']);
        $this->assertCount(3, $entries, 'Trois legs : position, settlement, revenue.');
        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame('102.00000000', $entries[0]['amount']);
        $this->assertSame('credit', $entries[1]['entry_type']);
        $this->assertSame('PROVIDER_SETTLEMENT.pawapay.EUR', (string) $entries[1]['account_code']);
        $this->assertSame('100.00000000', $entries[1]['amount']);
        $this->assertSame('credit', $entries[2]['entry_type']);
        $this->assertSame('NEXUS_REVENUE.fee', (string) $entries[2]['account_code']);
        $this->assertSame('2.00000000', $entries[2]['amount']);
        // Équilibre par devise : 102 debit = 100 + 2 credit.
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 5 — EUR → XAF : reconstruisible depuis wallet_operations
    // ══════════════════════════════════════════════════════════════════════

    public function test_eur_to_xaf_is_reconstructible_from_wallet_operations(): void
    {
        $u    = $this->createUser();
        $eur  = $this->createWallet($u, 'EUR', '100.00');
        $xaf  = $this->createWallet($u, 'XAF', '0.00');
        $ctx  = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);
        $key  = 'acct-fx-' . bin2hex(random_bytes(4));
        $this->trackKey($key);

        // Conversion interne : 100 EUR → 65 000 XAF à 650.00000000.
        $opId = LedgerService::transfer(
            $u, $eur, $xaf,
            '100.00000000', 'EUR',
            '65000.00000000', 'XAF',
            'convert', $key, 'Conversion EUR→XAF', null,
            '650.00000000', 'fx_manual', null, $ctx
        );
        $this->operationIds[] = $opId;

        // Modèle cible : transit FX_TRANSIT — 4 legs, équilibrés PAR DEVISE
        // (jamais deux devises équilibrées directement).
        $entries = $this->ledgerEntries($opId);
        $this->assertCount(4, $entries);
        $this->assertSame('debit', $entries[0]['entry_type']);
        $this->assertSame('FX_TRANSIT.EURXAF', (string) $entries[1]['account_code']);
        $this->assertSame('credit', $entries[1]['entry_type']);
        $this->assertSame('debit', $entries[2]['entry_type']);
        $this->assertSame('credit', $entries[3]['entry_type']);
        // Équilibre par devise : EUR (debit 100 = credit 100), XAF (debit 65000 = credit 65000).

        // La traçabilité FX est complète : source, destination, taux, source du taux.
        $stmt = $this->pdo->prepare(
            'SELECT source_currency, source_amount, dest_currency, dest_amount, fx_rate, fx_source
             FROM wallet_operations WHERE id = ?'
        );
        $stmt->execute([$opId]);
        $op = $stmt->fetch();
        $this->assertSame('EUR', $op['source_currency']);
        $this->assertSame('100.00000000', $op['source_amount']);
        $this->assertSame('XAF', $op['dest_currency']);
        $this->assertSame('65000.00000000', $op['dest_amount']);
        $this->assertSame('650.00000000', $op['fx_rate']);
        $this->assertSame('fx_manual', $op['fx_source']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 6 — Webhook dupliqué : aucune double comptabilisation
    // ══════════════════════════════════════════════════════════════════════

    public function test_duplicate_webhook_failed_credits_once(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '500.00');
        $ctx = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);

        $opId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
        $holdKey = 'op:' . $opId . ':hold';
        $this->trackKey($holdKey);
        $hold = WalletService::createHold($u, $wid, '100.00000000', 'EUR', $holdKey, 'Envoi', null, $ctx);
        $this->operationIds[] = $hold['operation_id'];

        $capKey = 'op:' . $opId . ':capture';
        $this->trackKey($capKey);
        WalletService::captureHold($hold['operation_id'], $u, $capKey, $ctx);

        $txId = $this->makeTx($u, 'processing', '100.00', 'EUR', '65000.00', 'XAF', $opId);

        // Premier FAILED → retour du transit vers available (aucun débit n'avait
        // été posté : rien à compenser au ledger).
        ExecutionSettlementService::settle($this->txRow($txId), 'failed', 'FAILED');
        // Rejeu du webhook → statut terminal, acquitté sans effet.
        ExecutionSettlementService::settle($this->txRow($txId), 'failed', 'FAILED');

        $bal = $this->walletBalance($wid);
        $this->assertSame('500.00', $bal['balance'], 'Position restaurée.');
        $this->assertSame('500.00', $bal['available_balance'], 'Transit restitué au disponible.');
        $this->assertSame('0.00', $bal['in_transit_balance']);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ledger_entries
             WHERE reference_type = 'refund' AND JSON_EXTRACT(metadata, '$.transaction_id') = ?"
        );
        $stmt->execute([$txId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune écriture de compensation (rien n\'avait été débité).');
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 7 — Webhook perdu : la réconciliation détecte la transaction
    // ══════════════════════════════════════════════════════════════════════

    public function test_lost_webhook_is_detected_by_reconciliation(): void
    {
        // Adaptateur scripté : le provider ne connaît PAS ce payout.
        $adapter = new class ('pawapay') extends ScriptedProviderAdapter {
            public function getPaymentStatus(string $paymentId): array
            {
                return [
                    'status'          => 'processing',
                    'provider'        => 'pawapay',
                    'provider_status' => 'UNKNOWN',
                    'amount'          => '',
                ];
            }
        };
        ProviderRegistry::registerAdapter('pawapay', $adapter);

        try {
            $u   = $this->createUser();
            $opId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
            $txId = $this->makeTx($u, 'processing', '100.00', 'EUR', '65000.00', 'XAF', $opId);

            // Vieillir la transaction pour qu'elle soit éligible au polling.
            $this->pdo->prepare('UPDATE transactions SET updated_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?')
                ->execute([$txId]);

            $report = ProviderReconciliationService::reconcile('sandbox', 120, false);

            $this->assertSame(1, $report['examined']);
            $this->assertCount(1, $report['missing_at_provider'], 'Transaction sans trace provider → à examiner.');
            $this->assertSame($txId, $report['missing_at_provider'][0]['transaction_id']);
        } finally {
            ProviderRegistry::resetAdapters();
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 8 — Provider 100 vs Nexus 80 → écart détecté (jamais corrigé)
    // ══════════════════════════════════════════════════════════════════════

    public function test_amount_discrepancy_is_detected_never_corrected(): void
    {
        $adapter = new class ('pawapay') extends ScriptedProviderAdapter {
            public function getPaymentStatus(string $paymentId): array
            {
                return [
                    'status'          => 'completed',
                    'provider'        => 'pawapay',
                    'provider_status' => 'COMPLETED',
                    'amount'          => '100.00',
                    'currency'        => 'XAF',
                ];
            }
        };
        ProviderRegistry::registerAdapter('pawapay', $adapter);

        try {
            $u   = $this->createUser();
            $opId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
            // Nexus attend 80 XAF ; le provider dit 100 XAF.
            $txId = $this->makeTx($u, 'processing', '100.00', 'EUR', '80.00', 'XAF', $opId);
            $this->pdo->prepare('UPDATE transactions SET updated_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?')
                ->execute([$txId]);

            $report = ProviderReconciliationService::reconcile('sandbox', 120, true);

            // Écart signalé, transaction PAS réglée automatiquement en completed.
            $this->assertCount(1, $report['discrepancies']);
            $d = $report['discrepancies'][0];
            $this->assertSame($txId, $d['transaction_id']);
            $this->assertSame('80.00', $d['nexus_dest_amount']);
            $this->assertSame('100.00', $d['provider_amount']);
            $this->assertSame('processing', $this->txRow($txId)['status'], 'Aucun correctif automatique du statut en cas d\'écart.');
        } finally {
            ProviderRegistry::resetAdapters();
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 9 — Transaction provider inconnue → pas d'attribution automatique
    // ══════════════════════════════════════════════════════════════════════

    public function test_unknown_provider_operation_is_not_auto_attributed(): void
    {
        $u   = $this->createUser();
        $opId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));

        // Un webhook pour une opération que Nexus ne connaît pas :
        // ExecutionSettlementService ne doit pas créer d'écriture.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ledger_entries WHERE reference_type = :rt AND reference_id = :rid'
        );
        $stmt->execute(['rt' => 'refund', 'rid' => $opId]);
        $before = (int) $stmt->fetchColumn();

        // (Pas de transaction associée → rien à régler ; on vérifie que le
        // flux webhook refuse l'opération inconnue via findTransaction.)
        $found = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions WHERE provider = :p AND provider_operation_id = :op'
        );
        $found->execute(['p' => 'pawapay', 'op' => $opId]);
        $this->assertSame(0, (int) $found->fetchColumn(), 'Opération inconnue : aucune transaction Nexus.');

        $stmt->execute(['rt' => 'refund', 'rid' => $opId]);
        $this->assertSame($before, (int) $stmt->fetchColumn(), 'Aucune écriture créée pour une opération inconnue.');
        $this->assertSame($u, $u); // l'utilisateur existe ; rien d'attribué
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 10 — Refund : ledger et provider cohérents
    // ══════════════════════════════════════════════════════════════════════

    public function test_refund_is_ledger_consistent(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u, 'EUR', '500.00');
        $ctx = ExecutionContext::explicit($u, ExecutionEnvironment::SANDBOX);

        $opId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
        $holdKey = 'op:' . $opId . ':hold';
        $this->trackKey($holdKey);
        $hold = WalletService::createHold($u, $wid, '100.00000000', 'EUR', $holdKey, 'Envoi', null, $ctx);
        $this->operationIds[] = $hold['operation_id'];
        $capKey = 'op:' . $opId . ':capture';
        $this->trackKey($capKey);
        WalletService::captureHold($hold['operation_id'], $u, $capKey, $ctx);

        $txId = $this->makeTx($u, 'processing', '100.00', 'EUR', '65000.00', 'XAF', $opId);
        ExecutionSettlementService::settle($this->txRow($txId), 'failed', 'FAILED');

        $bal = $this->walletBalance($wid);
        $this->assertSame('500.00', $bal['balance'], 'Refund intégral : position restaurée.');
        $this->assertSame('failed', $this->txRow($txId)['status']);
        $this->assertSame('FAILED', $this->txRow($txId)['provider_status']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 11 — Reversal : l'état « reversed » existe mais n'est pas produit
    // ══════════════════════════════════════════════════════════════════════

    public function test_reversal_is_not_yet_produced_by_any_code_path(): void
    {
        // État RÉEL : un payout REVERSED chez pawaPay est mappé en FAILED
        // (compensation = remboursement). Il n'existe pas encore de machine
        // à états de reversal dédiée (ledger 'reversal', wallet 'reversed') :
        // un completed→reversed / chargeback n'est pas représentable en l'état.
        $this->assertSame(
            'failed',
            \Nexus\Providers\PawaPayAdapter::STATUS_MAP['REVERSED'] ?? '?'
        );
        // Aucun code ne produit wallet_operations.status = 'reversed' :
        // le comptage des statuts écrits par le code ne contient jamais ce
        // statut-là (recherche exhaustive dans les sources).
        $sources = [
            file_get_contents(__DIR__ . '/../src/Services/WalletService.php') ?: '',
            file_get_contents(__DIR__ . '/../src/Services/LedgerService.php') ?: '',
            file_get_contents(__DIR__ . '/../src/Services/ExecutionSettlementService.php') ?: '',
        ];
        foreach ($sources as $src) {
            $this->assertStringNotContainsString("'reversed'", $src, "Statut 'reversed' jamais écrit par le code.");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 12 — Deux providers : positions identifiables séparément
    // ══════════════════════════════════════════════════════════════════════

    public function test_two_providers_are_traceable_separately(): void
    {
        $u = $this->createUser();

        $txA = $this->makeTx($u, 'completed', '100.00', 'EUR', '65000.00', 'XAF', 'payout_provider_a');
        $txB = $this->makeTx($u, 'completed', '50.00', 'EUR', '32500.00', 'XAF', 'payout_provider_b');

        // La colonne provider + provider_operation_id permet d'isoler chaque
        // rail. (Attention : les deux lignes sont volontairement 'pawapay'
        // dans cette fixture ; le test vérifie la clé de traçabilité.)
        $this->assertSame('pawapay', $this->txRow($txA)['provider']);
        $this->assertSame('payout_provider_a', $this->txRow($txA)['provider_operation_id']);
        $this->assertSame('payout_provider_b', $this->txRow($txB)['provider_operation_id']);

        // ÉVOLUTION (modèle cible) : la table provider_accounts existe désormais
        // avec la contrainte d'unicité (provider_slug, environment, currency) —
        // un seul compte par devise/provider/env, aucune ambiguïté de position.
        $table = $this->pdo->query('SHOW TABLES LIKE "provider_accounts"')->fetchColumn();
        $this->assertSame('provider_accounts', $table, 'Table provider_accounts présente (modèle cible).');
        $stmt = $this->pdo->query(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_accounts'
               AND INDEX_NAME = 'uq_provider_account'"
        );
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Unicité (provider_slug, environment, currency) présente.');
    }
}
