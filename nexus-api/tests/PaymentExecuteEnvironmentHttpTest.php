<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\PaymentController;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PHASE 4 §2 + §9 — CHEMIN HTTP RÉEL DE PaymentController::execute.
 *
 * Le point critique de la phase. Un paiement approuvé en sandbox ne doit
 * jamais pouvoir être exécuté en production : la revue et l'approbation
 * auraient été faites sur une opération fictive, et l'exécution déplacerait
 * de l'argent réel.
 *
 * Ces tests passent par le contrôleur complet — authentification, résolution
 * du contexte d'exécution depuis l'en-tête HTTP, garde, réponse — et non par
 * un appel de service isolé.
 */
final class PaymentExecuteEnvironmentHttpTest extends TestCase
{
    private PDO $pdo;
    private int $businessId = 0;
    private string $token = '';

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);
        $this->clearEnv();

        $suffix = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Payment Env Biz',
            'e' => 'payenv_' . $suffix . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'business',
            'c' => 'CG',
        ]);
        $this->businessId = (int) $this->pdo->lastInsertId();

        $this->token = \Nexus\Auth\Jwt::encode([
            'sub' => $this->businessId,
            'iat' => time(),
            'exp' => time() + 3600,
        ]);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        $this->clearEnv();

        if ($this->businessId > 0) {
            $uid = $this->businessId;
            $this->pdo->prepare(
                'DELETE FROM ledger_entries WHERE operation_id IN (SELECT id FROM wallet_operations WHERE user_id = ?)'
            )->execute([$uid]);
            foreach (['payments', 'beneficiaries', 'wallet_operations', 'transactions',
                      'quotes', 'idempotency_keys', 'wallets', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$uid]);
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $this->businessId = 0;
        }
    }

    /**
     * Autorise explicitement ce compte en production. Sans cela, la politique
     * d'autorisation (phase précédente) répond 403 AVANT la garde
     * d'environnement, et le 409 recherché ne serait jamais atteint. On teste
     * ici la garde, pas l'autorisation.
     */
    private function allowProduction(): void
    {
        putenv('NEXUS_PRODUCTION_ALLOWED_ACCOUNTS=' . $this->businessId);
    }

    private function clearEnv(): void
    {
        putenv('NEXUS_PRODUCTION_ALLOWED_ACCOUNTS');
        putenv('NEXUS_PRODUCTION_ALLOWED');
        putenv('PROVIDERS_ENV');
        putenv('APP_ENV');
        unset($_SERVER['HTTP_X_NEXUS_ENVIRONMENT'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    private function beneficiary(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO beneficiaries
                (user_id, name, country, currency, method, account_reference_enc, status, verification_status)
             VALUES (:u, :n, :c, :cur, :m, :ref, :s, :v)'
        );
        $stmt->execute([
            'u' => $this->businessId, 'n' => 'Fournisseur', 'c' => 'CG', 'cur' => 'EUR',
            'm' => 'bank', 'ref' => 'ACC-' . bin2hex(random_bytes(4)),
            's' => 'active', 'v' => 'verified',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function approvedPayment(string $environment): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments
                (user_id, beneficiary_id, source_currency, dest_currency, amount, amount_ref,
                 fee, fee_currency, status, created_by, environment)
             VALUES (:u, :b, :sc, :dc, :amt, :aref, :fee, :fc, :st, :cb, :env)'
        );
        $stmt->execute([
            'u'    => $this->businessId,
            'b'    => $this->beneficiary(),
            'sc'   => 'EUR',
            'dc'   => 'EUR',
            'amt'  => '100.00',
            'aref' => '100.00',
            'fee'  => '0.00',
            'fc'   => 'EUR',
            'st'   => 'approved',
            'cb'   => $this->businessId,
            'env'  => $environment,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Exécute le contrôleur et retourne la réponse capturée.
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    private function callExecute(int $paymentId, ?string $headerEnv): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
        if ($headerEnv !== null) {
            $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = $headerEnv;
        }

        $request = new Request([]);
        $request->setParams(['id' => (string) $paymentId]);

        try {
            PaymentController::execute($request);
            $this->fail('Le contrôleur aurait dû émettre une réponse.');
        } catch (ResponseSent $sent) {
            $body = json_decode($sent->body(), true);

            return ['status' => $sent->statusCode(), 'body' => is_array($body) ? $body : []];
        } catch (\Nexus\Core\HttpException $e) {
            // Le routeur convertit HttpException en réponse HTTP : on
            // reproduit fidèlement cette conversion.
            return [
                'status' => $e->statusCode(),
                'body'   => ['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]],
            ];
        }
    }

    /** @return array{tx:int,ledger:int,ops:int} */
    private function snapshot(): array
    {
        $count = function (string $sql): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['u' => $this->businessId]);

            return (int) $stmt->fetchColumn();
        };

        return [
            'tx'     => $count('SELECT COUNT(*) FROM transactions WHERE user_id = :u'),
            'ops'    => $count('SELECT COUNT(*) FROM wallet_operations WHERE user_id = :u'),
            'ledger' => $count(
                'SELECT COUNT(*) FROM ledger_entries
                  WHERE operation_id IN (SELECT id FROM wallet_operations WHERE user_id = :u)'
            ),
        ];
    }

    // ══ 1. Paiement sandbox exécuté en production → 409 ════════════════════

    public function test_sandbox_payment_cannot_be_executed_in_production(): void
    {
        $this->allowProduction();
        $paymentId = $this->approvedPayment('sandbox');
        $before    = $this->snapshot();

        $res = $this->callExecute($paymentId, 'production');

        $this->assertSame(409, $res['status']);
        $this->assertSame('ENVIRONMENT_MISMATCH', $res['body']['error']['code'] ?? null);

        // §7 — atomicité : rien n'a bougé.
        $this->assertSame($before, $this->snapshot(), 'Un refus ne doit produire aucune écriture financière.');

        // Le paiement reste approuvé : ni executing, ni failed, ni completed.
        $stmt = $this->pdo->prepare('SELECT status, environment, transaction_id FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('approved', $payment['status'], 'Le statut ne doit pas être altéré par un refus.');
        $this->assertSame('sandbox', $payment['environment'], 'Le paiement ne doit jamais être promu.');
        $this->assertNull($payment['transaction_id']);
    }

    // ══ 2. Paiement production exécuté en sandbox → 409 ════════════════════

    public function test_production_payment_cannot_be_executed_in_sandbox(): void
    {
        $paymentId = $this->approvedPayment('production');
        $before    = $this->snapshot();

        $res = $this->callExecute($paymentId, 'sandbox');

        $this->assertSame(409, $res['status']);
        $this->assertSame('ENVIRONMENT_MISMATCH', $res['body']['error']['code'] ?? null);
        $this->assertSame($before, $this->snapshot());

        $stmt = $this->pdo->prepare('SELECT status, environment FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('approved', $payment['status']);
        $this->assertSame('production', $payment['environment'], 'Aucune rétrogradation.');
    }

    // ══ 3. Une bascule serveur postérieure ne change rien ══════════════════

    /**
     * Le scénario le plus insidieux : le paiement est créé en sandbox, puis
     * la configuration du serveur bascule. Sans garde, l'exécution suivrait
     * la configuration courante et déplacerait de l'argent réel.
     */
    public function test_server_reconfiguration_cannot_promote_a_persisted_payment(): void
    {
        $this->allowProduction();
        $paymentId = $this->approvedPayment('sandbox');

        // Le serveur bascule APRÈS la création du paiement.
        putenv('PROVIDERS_ENV=production');

        $res = $this->callExecute($paymentId, 'production');

        $this->assertSame(409, $res['status']);
        $this->assertSame('ENVIRONMENT_MISMATCH', $res['body']['error']['code'] ?? null);

        $stmt = $this->pdo->prepare('SELECT environment FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $this->assertSame('sandbox', $stmt->fetchColumn());
    }

    // ══ 4. §14 — le refus ne divulgue aucun secret ═════════════════════════

    public function test_mismatch_response_leaks_no_secret(): void
    {
        $this->allowProduction();
        $paymentId = $this->approvedPayment('sandbox');
        $res       = $this->callExecute($paymentId, 'production');

        $serialized = json_encode($res['body']);

        foreach (['sk_live', 'sk_test', 'whsec_', 'api_token', 'secret_key',
                  'Bearer ', 'client_secret', 'BEGIN'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                (string) $serialized,
                sprintf('La réponse de mismatch ne doit pas contenir « %s ».', $needle)
            );
        }

        // Le code d'erreur reste exploitable par le client.
        $this->assertSame('ENVIRONMENT_MISMATCH', $res['body']['error']['code'] ?? null);
    }

    // ══ 5. Un environnement invalide est un 400, pas un 409 ════════════════

    public function test_invalid_environment_is_a_bad_request(): void
    {
        $paymentId = $this->approvedPayment('sandbox');
        $res       = $this->callExecute($paymentId, 'staging');

        $this->assertSame(400, $res['status']);
        $this->assertSame('ENVIRONMENT_INVALID', $res['body']['error']['code'] ?? null);
    }

    // ══ 6. Le chemin nominal reste ouvert ══════════════════════════════════

    /**
     * Un refus n'a de valeur que si l'acceptation fonctionne : sans ce test,
     * une garde qui refuse tout passerait pour correcte.
     */
    public function test_matching_environment_is_not_blocked_by_the_guard(): void
    {
        $paymentId = $this->approvedPayment('sandbox');
        $res       = $this->callExecute($paymentId, 'sandbox');

        $this->assertNotSame(
            409,
            $res['status'],
            'Un paiement sandbox exécuté en sandbox ne doit jamais être bloqué par la garde d\'environnement.'
        );
        $this->assertNotSame('ENVIRONMENT_MISMATCH', $res['body']['error']['code'] ?? null);
    }
}
