<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
use Nexus\Controllers\MaintenanceController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Execution\ProductionAuthorizationPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 19 — LA MAINTENANCE D'EXPLOITATION EST BORNÉE PAR L'ENVIRONNEMENT.
 *
 * LE DÉFAUT (CRITICAL, prouvé en HTTP réel avant correctif)
 * ─────────────────────────────────────────────────────────
 * `POST /api/control/maintenance/recover-payments` balayait TOUS les
 * environnements. Depuis un contexte sandbox explicite :
 *
 *     POST .../recover-payments   X-Nexus-Environment: sandbox
 *     → 200  { "reset": 2 }
 *     → paiement PRODUCTION de 9 500 EUR : executing → approved
 *
 * Un paiement « approved » est prêt à être exécuté. Un opérateur travaillant
 * en sandbox rouvrait donc la porte d'un mouvement d'argent réel, sans
 * jamais l'avoir demandé.
 *
 * Le diagnostic (`stuck-payments`) souffrait du même défaut en lecture : il
 * exposait les montants de production à un contexte de test, et surtout il
 * servait de plan de tir à un balayage qui, lui, écrivait.
 *
 * L'AUDIT MENTAIT AUSSI (HIGH)
 * ────────────────────────────
 * L'entrée `maintenance.recover_payments` portait `environment = 'sandbox'`
 * EN DUR. Le journal affirmait « sandbox » pendant que l'opération modifiait
 * de la production. Une trace fausse est pire qu'une trace absente : elle
 * est crue par l'enquêteur.
 *
 * POURQUOI CE FICHIER EXISTE
 * ──────────────────────────
 * `PaymentRecoveryTest` couvre le SERVICE. Une mutation a pourtant survécu :
 * remettre `'sandbox'` en dur dans le CONTRÔLEUR ne cassait aucun test. Un
 * service correct appelé de travers reste un défaut — c'est la leçon n°5.
 * Ces tests appellent donc le contrôleur, pas le service.
 */
final class MaintenanceEnvironmentScopeTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;
    private int $productionPayment = 0;
    private int $sandboxPayment = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);

        $this->userId            = $this->createOperator();
        $this->productionPayment = $this->createStuckPayment('production', '9500.00');
        $this->sandboxPayment    = $this->createStuckPayment('sandbox', '12.00');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::encode([
            'sub' => $this->userId, 'iat' => time(), 'exp' => time() + 3600,
        ]);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);

        putenv(ProductionAuthorizationPolicy::ENV_ALLOW_LIST);
        unset($_ENV[ProductionAuthorizationPolicy::ENV_ALLOW_LIST]);

        $this->pdo->prepare('DELETE FROM payments WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
    }

    /**
     * L'opérateur porte `sre_operator` : la capacité `maintenance`, sans être
     * superadmin. C'est le profil réaliste de celui qui déclenche un balayage.
     */
    private function createOperator(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type,
                                platform_role, country_of_residence, status)
             VALUES (:n, :e, :p, :t, :r, :c, :s)'
        );
        $stmt->execute([
            'n' => 'Maintenance Scope Probe',
            'e' => 'maintscope_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'business',
            'r' => 'sre_operator',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Un paiement immobilisé depuis 2 h : éligible au balayage. */
    private function createStuckPayment(string $environment, string $amount): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments
                (user_id, beneficiary_id, source_currency, dest_currency, amount,
                 amount_ref, fee, fee_currency, status, created_by, environment, updated_at)
             VALUES (:u, NULL, :sc, :dc, :amt, :amt2, 0.00, :fc, :st, :cb, :env,
                     DATE_SUB(NOW(), INTERVAL 2 HOUR))'
        );
        $stmt->execute([
            'u'    => $this->userId,
            'sc'   => 'EUR',
            'dc'   => 'XAF',
            'amt'  => $amount,
            'amt2' => $amount,
            'fc'   => 'EUR',
            'st'   => 'executing',
            'cb'   => $this->userId,
            'env'  => $environment,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{status:int,code:?string,data:array<string,mixed>} */
    private function call(callable $method, string $environment, array $body = []): array
    {
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = $environment;

        $request = new Request($body);

        try {
            $method($request);

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

    /**
     * Autorise CE compte à la production, comme le ferait la plateforme.
     *
     * L'autorisation ne dépend jamais des credentials (invariant NEXUS) :
     * c'est une décision explicite, ici reproduite fidèlement.
     */
    private function allowProductionForThisAccount(): void
    {
        putenv(ProductionAuthorizationPolicy::ENV_ALLOW_LIST . '=' . $this->userId);
    }

    private function statusOf(int $paymentId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);

        return (string) $stmt->fetchColumn();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Le balayage
    // ══════════════════════════════════════════════════════════════════════

    /**
     * LE CŒUR DU CORRECTIF : le scénario exact reproduit en HTTP réel.
     */
    public function test_a_sandbox_recovery_does_not_reopen_a_production_payment(): void
    {
        $res = $this->call(
            [MaintenanceController::class, 'recoverPayments'],
            'sandbox',
            ['confirm' => true, 'stale_seconds' => 3600]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(
            'executing',
            $this->statusOf($this->productionPayment),
            'Un balayage sandbox ne doit jamais rendre un paiement production réexécutable.'
        );
        $this->assertSame('approved', $this->statusOf($this->sandboxPayment));
        $this->assertSame(1, $res['data']['report']['examined'] ?? null);
        $this->assertSame('sandbox', $res['data']['report']['environment'] ?? null);
    }

    /**
     * L'AUDIT DOIT DIRE LA VÉRITÉ — versant contrôleur.
     *
     * MUTATION SURVIVANTE corrigée par ce test : remettre `'env' => 'sandbox'`
     * en dur dans `MaintenanceController` ne cassait rien, car les tests de
     * service ne passent pas par le contrôleur.
     */
    public function test_the_actor_audit_entry_carries_the_real_environment(): void
    {
        $this->call(
            [MaintenanceController::class, 'recoverPayments'],
            'sandbox',
            ['confirm' => true, 'stale_seconds' => 3600]
        );

        $stmt = $this->pdo->prepare(
            "SELECT environment, metadata FROM audit_logs
              WHERE user_id = :u AND action = 'maintenance.recover_payments'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'Le déclenchement d\'un balayage doit être tracé.');
        $this->assertSame('sandbox', (string) $row['environment']);

        $meta = json_decode((string) $row['metadata'], true);
        $this->assertIsArray($meta);
        $this->assertSame(
            'sandbox',
            $meta['environment'] ?? null,
            'La métadonnée doit porter l\'environnement réel, jamais une constante.'
        );
        $this->assertSame('sre_operator', $meta['platform_role'] ?? null);
    }

    /**
     * Le symétrique (leçon n°4) : la maintenance de production doit rester
     * possible, sinon le correctif casse le métier qu'il protège.
     *
     * L'autorisation production étant refusée par défaut à un compte non
     * habilité, on accepte les deux issues légitimes — mais JAMAIS une
     * modification du paiement sandbox.
     */
    public function test_a_production_recovery_never_touches_the_sandbox_payment(): void
    {
        // La production est refusée par défaut (fail closed). Pour exercer
        // RÉELLEMENT la branche production — et non son refus — le compte est
        // explicitement autorisé, exactement comme la plateforme le ferait.
        //
        // Sans cela, ce test ne teste que le 403 : deux mutations ont survécu
        // ainsi (environnement codé en dur à 'sandbox' dans le contrôleur),
        // car en sandbox la constante coïncide avec la valeur correcte. Une
        // garde ne se teste qu'en la faisant travailler sur les DEUX valeurs.
        $this->allowProductionForThisAccount();

        $res = $this->call(
            [MaintenanceController::class, 'recoverPayments'],
            'production',
            ['confirm' => true, 'stale_seconds' => 3600]
        );

        $this->assertSame(200, $res['status'], 'Le compte est autorisé : la production doit s\'exécuter.');
        $this->assertSame(
            'production',
            $res['data']['report']['environment'] ?? null,
            'Le rapport doit refléter l\'environnement demandé, pas une constante.'
        );
        $this->assertSame('approved', $this->statusOf($this->productionPayment));
        $this->assertSame(
            'executing',
            $this->statusOf($this->sandboxPayment),
            'Un balayage production ne doit pas toucher un paiement sandbox.'
        );
    }

    /**
     * L'AUDIT EN PRODUCTION — la mutation la plus tenace de cette boucle.
     *
     * `'env' => 'sandbox'` codé en dur survivait à tous les tests tant que
     * ceux-ci ne s'exécutaient qu'en sandbox. Ici le balayage porte sur la
     * production : la trace DOIT le dire.
     *
     * C'est précisément le scénario d'enquête : « qui a rouvert ce paiement
     * de 9 500 EUR ? » Une trace qui répond « sandbox » envoie l'enquêteur
     * dans la mauvaise direction.
     */
    public function test_a_production_recovery_is_audited_as_production(): void
    {
        $this->allowProductionForThisAccount();

        $this->call(
            [MaintenanceController::class, 'recoverPayments'],
            'production',
            ['confirm' => true, 'stale_seconds' => 3600]
        );

        $stmt = $this->pdo->prepare(
            "SELECT environment, metadata FROM audit_logs
              WHERE user_id = :u AND action = 'maintenance.recover_payments'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame(
            'production',
            (string) $row['environment'],
            'Le journal doit porter « production » : une trace fausse est pire qu\'une trace absente.'
        );

        $meta = json_decode((string) $row['metadata'], true);
        $this->assertIsArray($meta);
        $this->assertSame('production', $meta['environment'] ?? null);
    }

    /**
     * Le diagnostic en production ne voit que la production (symétrique).
     */
    public function test_the_production_diagnostic_hides_sandbox_payments(): void
    {
        $this->allowProductionForThisAccount();

        $res = $this->call([MaintenanceController::class, 'stuckPayments'], 'production');

        $this->assertSame(200, $res['status']);
        $this->assertSame('production', $res['data']['environment'] ?? null);

        $ids = array_column($res['data']['stuck_payments'] ?? [], 'payment_id');

        $this->assertContains($this->productionPayment, $ids);
        $this->assertNotContains($this->sandboxPayment, $ids);
    }

    /**
     * La confirmation explicite reste exigée, et un refus ne mute rien.
     */
    public function test_without_confirmation_nothing_is_modified(): void
    {
        $res = $this->call(
            [MaintenanceController::class, 'recoverPayments'],
            'sandbox',
            ['stale_seconds' => 3600]
        );

        $this->assertSame(400, $res['status']);
        $this->assertSame('CONFIRMATION_REQUIRED', $res['code']);
        $this->assertSame('executing', $this->statusOf($this->sandboxPayment));
        $this->assertSame('executing', $this->statusOf($this->productionPayment));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Le diagnostic
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Le diagnostic exposait les montants de production à un contexte
     * sandbox. Il est en lecture seule, mais c'est précisément la liste qui
     * servait de plan de tir au balayage.
     */
    public function test_the_diagnostic_only_lists_the_callers_environment(): void
    {
        $res = $this->call([MaintenanceController::class, 'stuckPayments'], 'sandbox');

        $this->assertSame(200, $res['status']);
        $this->assertSame('sandbox', $res['data']['environment'] ?? null);

        $ids = array_column($res['data']['stuck_payments'] ?? [], 'payment_id');

        $this->assertContains($this->sandboxPayment, $ids);
        $this->assertNotContains(
            $this->productionPayment,
            $ids,
            'Un contexte sandbox ne doit pas voir les paiements bloqués de production.'
        );
    }

    /**
     * Le diagnostic ne modifie RIEN : c'est ce que sa documentation promet.
     */
    public function test_the_diagnostic_mutates_nothing(): void
    {
        $this->call([MaintenanceController::class, 'stuckPayments'], 'sandbox');

        $this->assertSame('executing', $this->statusOf($this->sandboxPayment));
        $this->assertSame('executing', $this->statusOf($this->productionPayment));
    }
}
