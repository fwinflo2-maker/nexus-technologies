<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
use Nexus\Controllers\PaymentController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 12 — LE CYCLE DE VIE D'UN PAIEMENT EST SCOPÉ PAR ENVIRONNEMENT.
 *
 * LE DÉFAUT (prouvé en HTTP réel avant correctif)
 * ───────────────────────────────────────────────
 * `execute()` était protégé par EnvironmentGuard depuis une boucle
 * précédente. Mais `show()`, `submit()`, `approve()`, `reject()` et
 * `cancel()` passaient tous par un `find()` SANS filtre d'environnement :
 *
 *     POST /api/payments/{id}/approve   X-Nexus-Environment: sandbox
 *     → 200, paiement de PRODUCTION de 5 000 EUR : pending_approval → approved
 *
 * L'approbation est la porte qui précède l'exécution d'argent réel. La
 * franchir depuis un contexte de test vide la séparation sandbox/production de
 * son sens : il suffisait d'approuver en sandbox puis d'exécuter en
 * production, où le seul garde restant vérifiait un paiement déjà approuvé.
 *
 * LA CORRECTION
 * ─────────────
 * `find()` prend un `string $environment` OBLIGATOIRE (non nullable, sans
 * valeur par défaut). Un défaut aurait laissé les appels existants compiler
 * en silence — et la prochaine méthode ajoutée aurait réintroduit la faille.
 *
 * Ces tests couvrent CHAQUE méthode du cycle de vie, pas seulement une :
 * la leçon de la boucle 11 est qu'une garde non testée individuellement
 * survit à sa propre suppression.
 */
final class PaymentEnvironmentScopeTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;
    private int $productionPayment = 0;
    private int $sandboxPayment = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);

        $this->userId            = $this->createUser();
        $this->productionPayment = $this->createPayment('production', 'pending_approval');
        $this->sandboxPayment    = $this->createPayment('sandbox', 'pending_approval');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::encode([
            'sub' => $this->userId, 'iat' => time(), 'exp' => time() + 3600,
        ]);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);

        $this->pdo->prepare('DELETE FROM payments WHERE user_id = ?')->execute([$this->userId]);
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
            'n' => 'Payment Scope Probe',
            'e' => 'payscope_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'business',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPayment(string $environment, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments
                (user_id, beneficiary_id, source_currency, dest_currency, amount,
                 amount_ref, fee, fee_currency, status, created_by, environment)
             VALUES (:u, NULL, :sc, :dc, :amt, :amt2, 0.00, :fc, :st, :cb, :env)'
        );
        $stmt->execute([
            'u'   => $this->userId,
            'sc'  => 'EUR',
            'dc'  => 'XAF',
            'amt' => 5000.00,
            'amt2' => 5000.00,
            'fc'  => 'EUR',
            'st'  => $status,
            'cb'  => $this->userId,
            'env' => $environment,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{status:int,code:?string} */
    private function callWithId(callable $method, int $paymentId, string $environment): array
    {
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = $environment;

        $request = new Request([]);
        $request->setParams(['id' => (string) $paymentId]);

        try {
            $method($request);

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

    private function statusOf(int $paymentId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string, callable>
     */
    private function lifecycleMethods(): array
    {
        return [
            'show'    => [PaymentController::class, 'show'],
            'submit'  => [PaymentController::class, 'submit'],
            'approve' => [PaymentController::class, 'approve'],
            'reject'  => [PaymentController::class, 'reject'],
            'cancel'  => [PaymentController::class, 'cancel'],
        ];
    }

    /**
     * CHAQUE méthode du cycle de vie, une par une.
     *
     * Un seul test global aurait pu passer alors qu'une seule méthode est
     * protégée — c'est exactement ainsi qu'une mutation a survécu en boucle 11.
     */
    public function test_no_lifecycle_method_reaches_a_production_payment_from_sandbox(): void
    {
        foreach ($this->lifecycleMethods() as $name => $method) {
            $res = $this->callWithId($method, $this->productionPayment, 'sandbox');

            $this->assertSame(
                404,
                $res['status'],
                sprintf('%s() doit refuser un paiement de production depuis le sandbox.', $name)
            );
            $this->assertSame('PAYMENT_NOT_FOUND', $res['code'], $name);
        }
    }

    /**
     * Le test qui compte vraiment : l'ÉTAT n'a pas bougé.
     *
     * Un 404 qui aurait quand même muté la ligne serait pire qu'un 200 honnête.
     */
    public function test_a_production_payment_keeps_its_state_after_sandbox_attempts(): void
    {
        foreach ($this->lifecycleMethods() as $method) {
            $this->callWithId($method, $this->productionPayment, 'sandbox');
        }

        $this->assertSame(
            'pending_approval',
            $this->statusOf($this->productionPayment),
            "Le paiement de production ne doit avoir subi AUCUNE transition d'état."
        );
    }

    /** Le chemin nominal reste intact : même environnement, approbation réelle. */
    public function test_approving_within_the_same_environment_still_works(): void
    {
        $res = $this->callWithId(
            [PaymentController::class, 'approve'],
            $this->sandboxPayment,
            'sandbox'
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('approved', $this->statusOf($this->sandboxPayment));
    }

    /**
     * Symétrie : la production ne doit pas non plus atteindre le sandbox.
     *
     * Moins grave financièrement, mais une fuite d'isolation reste une fuite,
     * et l'invariant Nexus est bidirectionnel — aucun fallback, dans aucun sens.
     */
    public function test_production_context_cannot_reach_a_sandbox_payment(): void
    {
        $res = $this->callWithId(
            [PaymentController::class, 'show'],
            $this->sandboxPayment,
            'production'
        );

        // Soit la production est refusée en amont (autorisation), soit le
        // paiement sandbox est introuvable. Jamais un succès.
        $this->assertNotSame(200, $res['status']);
        $this->assertSame('pending_approval', $this->statusOf($this->sandboxPayment));
    }
}
