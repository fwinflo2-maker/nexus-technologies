<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\QuotePricing;
use Nexus\Services\ReferenceConverter;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * `amount_ref` / `amount_xaf` persistés suivent-ils le taux réel ? (boucle 17)
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `ExecutionEngine::insertTransaction()` écrit `amount_ref` et `amount_xaf`
 * dans la table `transactions`. Ces colonnes ne sont pas décoratives : elles
 * alimentent les totaux, les rapports et la valorisation de référence des
 * mouvements. Elles étaient calculées sur `Currency::rateToRef()` /
 * `rateToXaf()`, deux tables de constantes documentées comme « taux de démo »
 * et totalement déconnectées de `FXService`.
 *
 * Preuve relevée pendant l'audit : en injectant `1 EUR = 5 USD` dans le cache
 * FX, `FXService` renvoyait 5,00 alors que `Currency::rateToRef('USD')`
 * restait figé à 0,92 — un écart de 4,6× sur un montant porté au ledger.
 *
 * POURQUOI CE TEST EXISTE
 * ───────────────────────
 * Une mutation remettant les constantes dans `insertTransaction()` a d'abord
 * SURVÉCU : aucun test ne vérifiait la valeur réellement écrite en base. Le
 * test porte donc sur l'effet observable — la ligne persistée — et non sur
 * l'implémentation.
 *
 * VALORISATION HISTORIQUE
 * ───────────────────────
 * Le taux appliqué est celui du MOMENT de l'exécution : la référence est figée
 * avec la transaction, elle n'est pas recalculée après coup. C'est le contrat
 * attendu pour une écriture comptable.
 */
final class TransactionReferenceAmountTest extends TestCase
{
    private const TEST_SOURCE = 'txref17';

    private PDO $pdo;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        $this->purge();
        QuotePricing::resetCache();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM transactions WHERE user_id IN ($ph)")->execute($this->userIds);
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
            $this->purge();
        } catch (Throwable $e) {
            fwrite(STDERR, '[TransactionReferenceAmountTest] ' . $e->getMessage() . PHP_EOL);
        }

        QuotePricing::resetCache();
    }

    /**
     * LE test : la valeur ÉCRITE EN BASE doit suivre le taux réel.
     */
    public function test_amount_ref_persiste_suit_le_taux_reel(): void
    {
        // 1 USD = 0.25 EUR — très loin de la constante Currency (0.92).
        $this->seed('USD', 'EUR', '0.25000000', 'sandbox');
        $this->seed('USD', 'XAF', '164.00000000', 'sandbox');

        $userId = $this->createUser();
        $txId   = $this->insertTransaction($userId, '1000.00', 'USD');

        $row = $this->fetchTransaction($txId);

        self::assertSame('250.00', $row['amount_ref'], '1000 USD × 0.25 = 250 EUR');
        self::assertNotSame(
            '920.00',
            $row['amount_ref'],
            'La constante Currency (0.92) ne doit plus être utilisée pour une valeur persistée.'
        );
    }

    public function test_amount_xaf_persiste_suit_le_taux_reel(): void
    {
        $this->seed('USD', 'EUR', '0.25000000', 'sandbox');
        $this->seed('USD', 'XAF', '164.00000000', 'sandbox');

        $userId = $this->createUser();
        $txId   = $this->insertTransaction($userId, '10.00', 'USD');

        $row = $this->fetchTransaction($txId);

        self::assertSame('1640.00', $row['amount_xaf'], '10 USD × 164 = 1640 XAF');
        self::assertNotSame(
            '6030.00',
            $row['amount_xaf'],
            'La constante RATE_TO_XAF (603.0) ne doit plus être utilisée.'
        );
    }

    /**
     * Deux taux différents doivent produire deux valeurs persistées
     * différentes — c'est ce que la constante figée empêchait.
     */
    public function test_deux_taux_produisent_deux_references_distinctes(): void
    {
        $userId = $this->createUser();

        $this->seed('USD', 'EUR', '0.25000000', 'sandbox');
        $first = $this->fetchTransaction($this->insertTransaction($userId, '1000.00', 'USD'));

        $this->seed('USD', 'EUR', '0.80000000', 'sandbox');
        QuotePricing::resetCache();
        $second = $this->fetchTransaction($this->insertTransaction($userId, '1000.00', 'USD'));

        self::assertSame('250.00', $first['amount_ref']);
        self::assertSame('800.00', $second['amount_ref']);
        self::assertNotSame($first['amount_ref'], $second['amount_ref']);
    }

    /**
     * Documente l'écart avec l'ancien comportement, pour que la régression
     * soit reconnaissable si quelqu'un réintroduit les constantes.
     */
    public function test_la_reference_diverge_desormais_de_la_constante(): void
    {
        $this->seed('USD', 'EUR', '0.25000000', 'sandbox');

        $viaFx        = ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::SANDBOX);
        $viaConstante = 1000.0 * Currency::rateToRef('USD');

        self::assertSame(250.0, $viaFx);
        self::assertSame(920.0, $viaConstante);
        self::assertNotEqualsWithDelta(
            $viaConstante,
            $viaFx,
            1.0,
            'Le test doit rester discriminant : si les deux valeurs convergent, il ne prouve plus rien.'
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** Appelle la méthode privée réellement responsable de l'écriture. */
    private function insertTransaction(int $userId, string $amount, string $currency): int
    {
        $method = new ReflectionMethod(\Nexus\Services\ExecutionEngine::class, 'insertTransaction');
        $method->setAccessible(true);

        return (int) $method->invoke(
            null,
            $this->pdo,
            $userId,
            'Test référence',
            'testprov',
            'A',
            'NX-TEST-REF',
            'dest',
            $amount,
            $currency,
            0.0,
            'XAF',
            '0.00000000',
            0.0,
            'send',
            'op-' . bin2hex(random_bytes(6)),
            microtime(true),
            null
        );
    }

    /** @return array<string, string> */
    private function fetchTransaction(int $txId): array
    {
        $stmt = $this->pdo->prepare('SELECT amount_ref, amount_xaf FROM transactions WHERE id = ?');
        $stmt->execute([$txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'La transaction doit exister en base.');

        return $row;
    }

    private function createUser(): int
    {
        $suffix = bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:n, :e, :p, :t, :s, :k)'
        );
        $stmt->execute([
            'n' => 'TxRef ' . $suffix,
            'e' => 'txref_' . $suffix . '@nexus-test.local',
            'p' => '',
            't' => 'personal',
            's' => 'ACTIVE',
            'k' => 'none',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }

    private function seed(string $base, string $quote, string $rate, string $environment): void
    {
        $this->pdo
            ->prepare('DELETE FROM fx_rates_cache WHERE base_currency = ? AND quote_currency = ? AND environment = ? AND source = ?')
            ->execute([$base, $quote, $environment, self::TEST_SOURCE]);

        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
             VALUES (?, ?, ?, 0, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$base, $quote, $rate, self::TEST_SOURCE, $environment]);

        QuotePricing::resetCache();
    }

    private function purge(): void
    {
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE source = ?')->execute([self::TEST_SOURCE]);
    }
}
