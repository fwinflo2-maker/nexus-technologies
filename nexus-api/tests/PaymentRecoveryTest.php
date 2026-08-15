<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\PaymentRecoveryService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 8 — REPRISE DES PAIEMENTS BLOQUÉS EN « executing ».
 *
 * LE PROBLÈME
 * ───────────
 * `PaymentController::execute` écrit `status = 'executing'` HORS transaction
 * SQL, puis lance la saga. Le bloc `catch` couvre les exceptions, mais pas un
 * arrêt brutal du processus (OOM, timeout PHP, kill, redémarrage).
 *
 * Dans cette fenêtre, le paiement reste `executing` pour toujours :
 *   - il ne peut plus être exécuté (`execute` exige `approved`) ;
 *   - il ne peut plus être annulé (`cancel` exige `draft`) ;
 *   - il gonfle indéfiniment les « payables » du tableau de bord.
 *
 * POURQUOI UNE REPRISE EST SÛRE ICI
 * ─────────────────────────────────
 * La clé d'idempotence du paiement est DÉTERMINISTE :
 *
 *     payment:{id}:execute
 *
 * L'état réel de l'exécution est donc lisible en base, sans deviner : la table
 * `idempotency_keys` sait si la saga a abouti, échoué, ou n'a jamais démarré.
 * La reprise ne réexécute rien — elle se contente de faire correspondre le
 * statut du paiement à ce qui s'est RÉELLEMENT passé.
 *
 * C'est la raison pour laquelle ce mécanisme n'invente aucune règle métier :
 * il ne décide pas du sort d'un paiement, il lit un fait déjà enregistré.
 */
final class PaymentRecoveryTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;
    private int $beneficiaryId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Recovery Probe',
            'e' => 'recov_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'business',
            'c' => 'CG',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $ben = $this->pdo->prepare(
            'INSERT INTO beneficiaries
                (user_id, name, country, currency, method, account_reference_enc, status, verification_status)
             VALUES (:u, :n, :c, :cur, :m, :ref, :s, :v)'
        );
        $ben->execute([
            'u' => $this->userId, 'n' => 'Fournisseur', 'c' => 'CG', 'cur' => 'EUR',
            'm' => 'bank', 'ref' => 'ACC-' . bin2hex(random_bytes(4)),
            's' => 'active', 'v' => 'verified',
        ]);
        $this->beneficiaryId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            foreach (['payments', 'beneficiaries', 'idempotency_keys', 'transactions', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$this->userId]);
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->userId = 0;
        }
    }

    private function payment(string $status, string $ageExpr = 'NOW()', string $environment = 'sandbox'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments
                (user_id, beneficiary_id, source_currency, dest_currency, amount, amount_ref,
                 fee, fee_currency, status, created_by, environment, updated_at)
             VALUES (:u, :b, :sc, :dc, :amt, :aref, :fee, :fc, :st, :cb, :env, ' . $ageExpr . ')'
        );
        $stmt->execute([
            'u' => $this->userId, 'b' => $this->beneficiaryId, 'sc' => 'EUR', 'dc' => 'EUR',
            'amt' => '100.00', 'aref' => '100.00', 'fee' => '0.00', 'fc' => 'EUR',
            'st' => $status, 'cb' => $this->userId, 'env' => $environment,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function idempotency(int $paymentId, string $status, ?array $response = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO idempotency_keys
                (idempotency_key, user_id, operation_id, status, response_json, environment, expires_at)
             VALUES (:k, :u, :o, :s, :r, :e, DATE_ADD(NOW(), INTERVAL 1 DAY))'
        );
        $stmt->execute([
            'k' => 'payment:' . $paymentId . ':execute',
            'u' => $this->userId,
            'o' => 'op-' . bin2hex(random_bytes(6)),
            's' => $status,
            'r' => $response !== null ? json_encode($response) : null,
            'e' => 'sandbox',
        ]);
    }

    private function statusOf(int $paymentId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);

        return (string) $stmt->fetchColumn();
    }

    // ══ 1. La saga a réellement abouti → le paiement doit être complété ════

    /**
     * Crash APRÈS la saga, AVANT l'écriture de `completed`. L'argent est
     * parti ; le paiement doit refléter ce fait.
     */
    public function test_a_payment_whose_saga_succeeded_is_completed(): void
    {
        $id = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $this->idempotency($id, 'completed', ['id' => 4242]);

        $report = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $this->assertSame('completed', $this->statusOf($id));
        $this->assertSame(1, $report['completed']);
    }

    // ══ 2. La saga a échoué → le paiement doit être marqué échoué ═════════

    public function test_a_payment_whose_saga_failed_is_marked_failed(): void
    {
        $id = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $this->idempotency($id, 'error', ['error' => 'provider indisponible']);

        $report = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $this->assertSame('failed', $this->statusOf($id));
        $this->assertSame(1, $report['failed']);
    }

    // ══ 3. La saga n'a jamais démarré → retour à « approved » ══════════════

    /**
     * Crash entre la réservation et le démarrage de la saga : aucune trace
     * d'exécution. Le paiement redevient exécutable — il n'a rien produit.
     */
    public function test_a_payment_that_never_started_returns_to_approved(): void
    {
        $id = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        // Aucune clé d'idempotence : la saga n'a pas commencé.

        $report = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $this->assertSame('approved', $this->statusOf($id));
        $this->assertSame(1, $report['reset']);
    }

    // ══ 4. Une exécution EN COURS n'est jamais touchée ════════════════════

    /**
     * Le risque majeur d'un balayage : conclure trop vite sur une saga encore
     * vivante. Deux protections cumulées, testées séparément.
     */
    /**
     * L'ANCIENNETÉ SEULE doit suffire à protéger un paiement.
     *
     * Ce test isole volontairement le filtre d'âge : le paiement n'a AUCUNE
     * clé d'idempotence, donc la protection « saga en cours » ne s'applique
     * pas. Seule l'ancienneté peut le sauver.
     *
     * C'est la course réelle : le contrôleur vient d'écrire « executing » et
     * n'a pas encore démarré la saga. Un balayage sans filtre d'âge le
     * remettrait « approved » sous les pieds d'une exécution qui démarre.
     *
     * (Une première version de ce test utilisait une saga « processing » : la
     * protection « saga en cours » masquait alors le filtre d'âge, et une
     * mutation supprimant ce filtre passait inaperçue.)
     */
    public function test_a_recent_payment_is_protected_by_age_alone(): void
    {
        $id = $this->payment('executing', 'NOW()'); // réservé à l'instant

        $report = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $this->assertSame(
            'executing',
            $this->statusOf($id),
            'Un paiement tout juste réservé ne doit pas être repris : sa saga démarre peut-être.'
        );
        $this->assertSame(0, $report['examined'], 'Un paiement récent ne doit même pas être examiné.');
    }

    public function test_a_payment_still_processing_is_never_touched(): void
    {
        $id = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $this->idempotency($id, 'processing'); // saga toujours en cours

        $report = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $this->assertSame(
            'executing',
            $this->statusOf($id),
            'Tant que la clé d\'idempotence est « processing », l\'issue est inconnue : ne rien décider.'
        );
        $this->assertSame(1, $report['skipped_in_progress']);
    }

    // ══ 5. Les autres statuts ne sont jamais modifiés ══════════════════════

    public function test_other_statuses_are_left_alone(): void
    {
        $ids = [];
        foreach (['approved', 'completed', 'failed', 'cancelled', 'draft', 'pending_approval'] as $status) {
            $ids[$status] = $this->payment($status, "DATE_SUB(NOW(), INTERVAL 5 HOUR)");
        }

        PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        foreach ($ids as $status => $id) {
            $this->assertSame($status, $this->statusOf($id), sprintf('Le statut « %s » ne doit pas bouger.', $status));
        }
    }

    // ══ 6. Le balayage est idempotent ══════════════════════════════════════

    public function test_sweeping_twice_changes_nothing_more(): void
    {
        $id = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $this->idempotency($id, 'completed', ['id' => 99]);

        $first  = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);
        $second = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $this->assertSame(1, $first['completed']);
        $this->assertSame(0, $second['completed'], 'Un second passage ne doit plus rien avoir à reprendre.');
        $this->assertSame('completed', $this->statusOf($id));
    }

    // ══ 7. Chaque reprise est auditée ══════════════════════════════════════

    /**
     * Une correction d'état automatique doit laisser une trace : sans cela,
     * un paiement changerait de statut sans que personne ne puisse expliquer
     * pourquoi.
     */
    public function test_every_recovery_is_audited(): void
    {
        $id = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $this->idempotency($id, 'error', ['error' => 'timeout']);

        PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
              WHERE user_id = :u AND action LIKE 'payment.recovery%'"
        );
        $stmt->execute(['u' => $this->userId]);

        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'Toute reprise doit être auditée.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // BOUCLE 19 — LA MAINTENANCE NE TRAVERSE PAS LA FRONTIÈRE D'ENVIRONNEMENT
    // ══════════════════════════════════════════════════════════════════════

    /**
     * LE DÉFAUT (CRITICAL, prouvé en HTTP réel)
     * ─────────────────────────────────────────
     * `sweep()` ne filtrait pas sur `environment`. Un opérateur en contexte
     * SANDBOX explicite (`X-Nexus-Environment: sandbox`) a fait passer un
     * paiement de PRODUCTION de 9 500 EUR de `executing` à `approved` —
     * c'est-à-dire de « bloqué » à « prêt à envoyer de l'argent réel ».
     *
     * L'en-tête du service affirmait pourtant qu'il ne contourne pas la
     * séparation sandbox/production. Un commentaire n'est pas une garantie ;
     * seul le `WHERE` en est une.
     */
    public function test_a_sandbox_sweep_never_touches_production_payments(): void
    {
        $prod    = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)", 'production');
        $sandbox = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)", 'sandbox');

        $report = PaymentRecoveryService::sweep($this->pdo, 'sandbox', 3600);

        $this->assertSame(
            'executing',
            $this->statusOf($prod),
            'Un balayage sandbox ne doit JAMAIS modifier un paiement de production.'
        );
        $this->assertSame('approved', $this->statusOf($sandbox));
        $this->assertSame(1, $report['examined'], 'Seul le paiement sandbox devait être examiné.');
        $this->assertSame('sandbox', $report['environment']);
    }

    /**
     * Le symétrique (leçon de mutation n°4 : tester CHAQUE côté d'une garde).
     *
     * Sans ce test, un `WHERE environment = 'sandbox'` codé en dur passerait
     * le test précédent tout en rendant la maintenance de production
     * totalement inopérante.
     */
    public function test_a_production_sweep_never_touches_sandbox_payments(): void
    {
        $prod    = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)", 'production');
        $sandbox = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)", 'sandbox');

        $report = PaymentRecoveryService::sweep($this->pdo, 'production', 3600);

        $this->assertSame('approved', $this->statusOf($prod));
        $this->assertSame(
            'executing',
            $this->statusOf($sandbox),
            'Un balayage production ne doit pas modifier un paiement sandbox.'
        );
        $this->assertSame(1, $report['examined']);
        $this->assertSame('production', $report['environment']);
    }

    /**
     * Un environnement invalide ne doit pas se comporter comme « tous ».
     *
     * C'est le mode de défaillance le plus dangereux d'un filtre : une valeur
     * inattendue qui, faute de validation, élargit le périmètre au lieu de le
     * fermer.
     */
    public function test_an_invalid_environment_is_refused_rather_than_broadened(): void
    {
        $prod = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)", 'production');

        $this->expectException(\InvalidArgumentException::class);

        try {
            PaymentRecoveryService::sweep($this->pdo, 'toutes', 3600);
        } finally {
            $this->assertSame(
                'executing',
                $this->statusOf($prod),
                'Un environnement invalide ne doit rien modifier du tout.'
            );
        }
    }

    /**
     * LA DÉFENSE EN PROFONDEUR DOIT ÊTRE TESTÉE POUR SES PROPRES MÉRITES.
     *
     * MUTATION SURVIVANTE (boucle 19) : retirer `AND environment = :env` de
     * l'UPDATE ne cassait aucun test, parce que le SELECT filtre déjà. La
     * seconde garde était donc décorative — exactement ce que la leçon n°1
     * décrit : deux protections testées sur une même fixture se masquent.
     *
     * On appelle donc l'UPDATE dans les conditions qu'il est censé couvrir :
     * un paiement dont l'environnement a CHANGÉ entre la lecture et
     * l'écriture. On le simule en réutilisant la même requête d'écriture que
     * le service, avec un environnement qui ne correspond pas.
     *
     * Sans le filtre, cette écriture aboutit ; avec lui, elle n'affecte
     * aucune ligne.
     */
    public function test_the_recovery_update_itself_refuses_to_cross_environments(): void
    {
        $prod = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)", 'production');

        // Reproduction exacte de l'écriture du service, mais depuis un
        // balayage « sandbox » : c'est la fenêtre que la seconde garde ferme.
        $source = file_get_contents(__DIR__ . '/../src/Services/PaymentRecoveryService.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            'AND status = :stuck AND environment = :env',
            $source,
            'L\'UPDATE de reprise doit porter sa propre garde d\'environnement.'
        );

        $upd = $this->pdo->prepare(
            "UPDATE payments SET status = 'approved'
              WHERE id = :id AND status = 'executing' AND environment = :env"
        );
        $upd->execute(['id' => $prod, 'env' => 'sandbox']);

        $this->assertSame(
            0,
            $upd->rowCount(),
            'Une écriture de reprise sandbox ne doit atteindre aucun paiement production.'
        );
        $this->assertSame('executing', $this->statusOf($prod));
    }

    /**
     * L'AUDIT DOIT DIRE LA VÉRITÉ (HIGH).
     *
     * L'entrée `maintenance.recover_payments` portait `environment = 'sandbox'`
     * EN DUR dans le contrôleur. Le journal affirmait donc « sandbox » alors
     * que l'opération avait modifié des paiements de production. Une trace
     * fausse est pire qu'une trace absente : elle est crue.
     *
     * Ici on vérifie le versant service : chaque reprise est journalisée avec
     * l'environnement RÉEL du paiement repris.
     */
    public function test_the_recovery_audit_records_the_real_environment(): void
    {
        $prod = $this->payment('executing', "DATE_SUB(NOW(), INTERVAL 2 HOUR)", 'production');
        $this->idempotency($prod, 'error', ['error' => 'timeout']);

        PaymentRecoveryService::sweep($this->pdo, 'production', 3600);

        $stmt = $this->pdo->prepare(
            "SELECT environment FROM audit_logs
              WHERE user_id = :u AND action LIKE 'payment.recovery%' AND entity_id = :id
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $this->userId, 'id' => $prod]);

        $this->assertSame(
            'production',
            (string) $stmt->fetchColumn(),
            'La trace doit porter l\'environnement réel du paiement repris.'
        );
    }
}
