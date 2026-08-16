<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
use Nexus\Controllers\ReconciliationController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 12 — LE RAPPROCHEMENT NE FRANCHIT PAS LA FRONTIÈRE D'ENVIRONNEMENT.
 *
 * LE DÉFAUT (prouvé en HTTP réel avant correctif)
 * ───────────────────────────────────────────────
 * `index()` filtrait par environnement. `upsert()` et `resolve()`, non.
 * Depuis un contexte sandbox, on écrivait un écart de rapprochement sur une
 * transaction de PRODUCTION — HTTP 200 :
 *
 *     POST /api/reconciliation   X-Nexus-Environment: sandbox
 *     {"transaction_id": <tx production>, "actual_amount": 999.00}
 *     → 200 {"status":"discrepancy"}
 *
 * Asymétrie absurde : LIRE la production était refusé (403), l'ÉCRIRE ne
 * l'était pas. Un opérateur en sandbox pouvait donc salir la comptabilité
 * d'argent réel — ou clore un écart légitime portant sur de l'argent réel.
 *
 * POURQUOI PAS DE COLONNE `environment` SUR LA TABLE
 * ──────────────────────────────────────────────────
 * L'environnement appartient à la transaction rapprochée, et `uq_recon_tx`
 * garantit un item par transaction. Dupliquer la valeur créerait deux vérités
 * possibles, donc un risque de divergence silencieuse — exactement le défaut
 * qu'on cherche à éliminer. La contrainte est portée par la jointure.
 */
final class ReconciliationEnvironmentTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;
    private int $sandboxTx = 0;
    private int $productionTx = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);

        $this->userId       = $this->createUser();
        $this->sandboxTx    = $this->createTransaction('sandbox');
        $this->productionTx = $this->createTransaction('production');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::encode([
            'sub' => $this->userId, 'iat' => time(), 'exp' => time() + 3600,
        ]);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);

        $this->pdo->prepare('DELETE FROM reconciliation_items WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
    }

    private function createUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, status)
             VALUES (:n, :e, :p, :t, :c, :s)'
        );
        $stmt->execute([
            'n' => 'Recon Probe',
            'e' => 'recon_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'business',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTransaction(string $environment): int
    {
        $stmt = $this->pdo->prepare(
            // Colonnes NOT NULL sans défaut : direction, label, amount_ref,
            // ref_currency, amount_xaf, fee, fee_currency (schéma réel).
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency,
                 amount_ref, ref_currency, amount_xaf, fee, fee_currency,
                 dest_amount, dest_currency, provider, destination,
                 status, environment, created_at)
             VALUES (:u, 'send', 'out', 'Rapprochement test', 100.00, 'EUR',
                     100.00, 'EUR', 65000.00, 0.00, 'EUR',
                     65000.00, 'XAF', 'stripe', '+242000000',
                     'completed', :env, NOW())"
        );
        $stmt->execute(['u' => $this->userId, 'env' => $environment]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{status:int,code:?string} */
    private function call(callable $fn): array
    {
        try {
            $fn();

            return ['status' => 0, 'code' => null];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);

            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
            ];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'code' => $e->errorCode()];
        }
    }

    /** @return array{status:int,code:?string} */
    private function upsert(int $txId, string $environment, float $actual = 999.00): array
    {
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = $environment;

        return $this->call(function () use ($txId, $actual): void {
            ReconciliationController::upsert(new Request([
                'transaction_id'     => $txId,
                'provider_reference' => 'REF-TEST',
                'actual_amount'      => $actual,
            ]));
        });
    }

    private function countItemsFor(int $txId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM reconciliation_items WHERE transaction_id = ?');
        $stmt->execute([$txId]);

        return (int) $stmt->fetchColumn();
    }

    // ══ ÉCRITURE ══════════════════════════════════════════════════════════

    /**
     * Le cœur du correctif : depuis sandbox, la transaction de production est
     * introuvable — et surtout, AUCUN item n'est écrit.
     */
    public function test_sandbox_cannot_reconcile_a_production_transaction(): void
    {
        $res = $this->upsert($this->productionTx, 'sandbox');

        $this->assertSame(404, $res['status']);
        $this->assertSame('TRANSACTION_NOT_FOUND', $res['code']);
        $this->assertSame(
            0,
            $this->countItemsFor($this->productionTx),
            'Un refus ne doit produire aucune écriture comptable.'
        );
    }

    /**
     * Le message ne doit pas trahir l'existence de la transaction : sinon
     * l'endpoint devient un oracle d'énumération des transactions de
     * production (on teste des identifiants jusqu'à obtenir un autre code).
     */
    public function test_a_production_transaction_is_indistinguishable_from_a_missing_one(): void
    {
        $existsElsewhere = $this->upsert($this->productionTx, 'sandbox');
        $doesNotExist    = $this->upsert(999_999_999, 'sandbox');

        $this->assertSame($doesNotExist['status'], $existsElsewhere['status']);
        $this->assertSame($doesNotExist['code'], $existsElsewhere['code']);
    }

    /** Le chemin nominal doit rester intact : même environnement = accepté. */
    public function test_reconciling_within_the_same_environment_works(): void
    {
        $res = $this->upsert($this->sandboxTx, 'sandbox', 65000.00);

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $this->countItemsFor($this->sandboxTx));
    }

    /**
     * Une transaction encore en vol ne se rapproche pas.
     *
     * `index()` n'affiche que les transactions `completed` : sans cette
     * condition, `upsert()` créait des items INVISIBLES du rapport, donc
     * impossibles à corriger depuis l'interface.
     */
    public function test_an_in_flight_transaction_cannot_be_reconciled(): void
    {
        $this->pdo->prepare("UPDATE transactions SET status = 'processing' WHERE id = ?")
                  ->execute([$this->sandboxTx]);

        $res = $this->upsert($this->sandboxTx, 'sandbox');

        $this->assertSame(404, $res['status']);
        $this->assertSame(0, $this->countItemsFor($this->sandboxTx));
    }

    // ══ RÉSOLUTION ════════════════════════════════════════════════════════

    /**
     * Clore un écart est une décision comptable : elle ne doit pas franchir la
     * frontière d'environnement non plus.
     */
    public function test_sandbox_cannot_resolve_a_production_discrepancy(): void
    {
        // Un écart légitime, créé côté production.
        $this->pdo->prepare(
            'INSERT INTO reconciliation_items
                (user_id, transaction_id, provider_reference, expected_amount, actual_amount, currency, status)
             VALUES (:u, :t, :r, 65000.00, 1.00, :c, :s)'
        )->execute([
            'u' => $this->userId,
            't' => $this->productionTx,
            'r' => 'REF-PROD',
            'c' => 'XAF',
            's' => 'discrepancy',
        ]);
        $itemId = (int) $this->pdo->lastInsertId();

        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'sandbox';
        $res = $this->call(function () use ($itemId): void {
            $request = new Request(['notes' => 'clos depuis le sandbox']);
            $request->setParams(['id' => (string) $itemId]);
            ReconciliationController::resolve($request);
        });

        $this->assertSame(404, $res['status']);
        $this->assertSame('RECON_ITEM_NOT_FOUND', $res['code']);

        $stmt = $this->pdo->prepare('SELECT status, resolved_at FROM reconciliation_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();

        $this->assertSame('discrepancy', $row['status'], "L'écart production doit rester ouvert.");
        $this->assertNull($row['resolved_at'], 'Aucune date de résolution ne doit être posée.');
    }
}
