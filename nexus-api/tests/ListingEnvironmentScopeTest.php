<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
use Nexus\Controllers\PaymentController;
use Nexus\Controllers\TransferController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 13 — LES LISTES ET LES DÉTAILS NE MÉLANGENT PAS LES ENVIRONNEMENTS.
 *
 * LE DÉFAUT (prouvé en HTTP réel avant correctif)
 * ───────────────────────────────────────────────
 * La boucle 12 a corrigé les ÉCRITURES. Le même motif existait sur les
 * LECTURES de masse :
 *
 *     GET /api/payments    X-Nexus-Environment: sandbox
 *     → [{"amount": 7777.00, "environment": "production"}]
 *
 *     GET /api/transfers   X-Nexus-Environment: sandbox
 *     → environnements retournés : ['production']
 *
 * Moins grave qu'une écriture, mais ce n'est pas cosmétique : un opérateur
 * qui croit lire un environnement de test lit en réalité de l'argent réel.
 * Les totaux affichés, les décisions de rapprochement et les contrôles de
 * cohérence portent alors sur le mauvais périmètre.
 *
 * Corrigés ici : liste des paiements, liste des transferts, détail d'un
 * transfert, détail d'une quote.
 */
final class ListingEnvironmentScopeTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;
    private int $productionTx = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);

        $this->userId = $this->createUser();
        $this->createPayment('production', 7777.00);
        $this->createPayment('sandbox', 11.00);
        $this->productionTx = $this->createTransaction('production');
        $this->createTransaction('sandbox');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::encode([
            'sub' => $this->userId, 'iat' => time(), 'exp' => time() + 3600,
        ]);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);

        foreach (['payments', 'transactions', 'wallets', 'audit_logs'] as $t) {
            $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$this->userId]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
    }

    private function createUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, status)
             VALUES (:n, :e, :p, :t, :c, :s)'
        );
        $stmt->execute([
            'n' => 'Listing Probe',
            'e' => 'listing_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'business',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPayment(string $environment, float $amount): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments
                (user_id, beneficiary_id, source_currency, dest_currency, amount,
                 amount_ref, fee, fee_currency, status, created_by, environment)
             VALUES (:u, NULL, :sc, :dc, :a, :a2, 0.00, :fc, :st, :cb, :env)'
        );
        $stmt->execute([
            'u' => $this->userId, 'sc' => 'EUR', 'dc' => 'XAF',
            'a' => $amount, 'a2' => $amount, 'fc' => 'EUR',
            'st' => 'draft', 'cb' => $this->userId, 'env' => $environment,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTransaction(string $environment): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency,
                 amount_ref, ref_currency, amount_xaf, fee, fee_currency,
                 provider, destination, status, environment, created_at)
             VALUES (:u, 'send', 'out', 'Listing test', 100.00, 'EUR',
                     100.00, 'EUR', 65000.00, 0.00, 'EUR',
                     'stripe', '+242000000', 'completed', :env, NOW())"
        );
        $stmt->execute(['u' => $this->userId, 'env' => $environment]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{status:int,code:?string,data:array<string,mixed>} */
    private function call(callable $fn, string $environment): array
    {
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = $environment;

        try {
            $fn();

            return ['status' => 0, 'code' => null, 'data' => []];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);

            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
                'data'   => is_array($decoded) ? ($decoded['data'] ?? []) : [],
            ];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'code' => $e->errorCode(), 'data' => []];
        }
    }

    // ══ LISTES ════════════════════════════════════════════════════════════

    public function test_the_payment_list_only_returns_the_current_environment(): void
    {
        $res = $this->call(
            static fn () => PaymentController::index(new Request([])),
            'sandbox'
        );

        $this->assertSame(200, $res['status']);

        $environments = array_column($res['data']['items'] ?? [], 'environment');
        $this->assertNotEmpty($environments, 'Le paiement sandbox doit rester visible.');
        $this->assertSame(
            ['sandbox'],
            array_values(array_unique($environments)),
            'Aucun paiement de production ne doit apparaître dans une vue sandbox.'
        );
    }

    /**
     * Le compteur `total` doit suivre le même périmètre que les éléments.
     *
     * Un total calculé sur un périmètre plus large que la liste est un piège
     * silencieux : la pagination promet des éléments qui n'arriveront jamais,
     * et un contrôle de cohérence comptable porte sur le mauvais nombre.
     */
    public function test_the_payment_total_matches_the_scoped_list(): void
    {
        $res = $this->call(
            static fn () => PaymentController::index(new Request([])),
            'sandbox'
        );

        $this->assertSame(
            count($res['data']['items'] ?? []),
            (int) ($res['data']['total'] ?? -1),
            'Le total doit être compté sur le même environnement que la liste.'
        );
    }

    public function test_the_transfer_history_only_returns_the_current_environment(): void
    {
        $res = $this->call(
            static fn () => TransferController::index(new Request([])),
            'sandbox'
        );

        $this->assertSame(200, $res['status']);

        $environments = array_column($res['data']['items'] ?? [], 'environment');
        if ($environments !== []) {
            $this->assertSame(['sandbox'], array_values(array_unique($environments)));
        } else {
            $this->assertSame(0, (int) ($res['data']['total'] ?? -1));
        }
    }

    // ══ DÉTAIL ════════════════════════════════════════════════════════════

    /**
     * Le détail d'un mouvement d'argent réel ne s'ouvre pas depuis une vue de
     * test — et le refus ne distingue pas « autre environnement » de
     * « inexistant », pour ne pas offrir d'oracle d'énumération.
     */
    public function test_a_production_transfer_detail_is_not_reachable_from_sandbox(): void
    {
        $existsElsewhere = $this->call(function (): void {
            $request = new Request([]);
            $request->setParams(['id' => (string) $this->productionTx]);
            TransferController::show($request);
        }, 'sandbox');

        $missing = $this->call(static function (): void {
            $request = new Request([]);
            $request->setParams(['id' => '999999999']);
            TransferController::show($request);
        }, 'sandbox');

        $this->assertSame(404, $existsElsewhere['status']);
        $this->assertSame($missing['status'], $existsElsewhere['status']);
        $this->assertSame($missing['code'], $existsElsewhere['code']);
    }
}
