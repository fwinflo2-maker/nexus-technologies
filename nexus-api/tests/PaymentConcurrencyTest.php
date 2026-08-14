<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 3 — DOUBLE EXÉCUTION D'UN PAIEMENT (course critique).
 *
 * `PaymentController::execute` lit le paiement sans verrou, vérifie
 * `status === 'approved'`, puis écrit `status = 'executing'` sans
 * conditionner cette écriture au statut source.
 *
 * Deux requêtes simultanées franchissent donc toutes deux la vérification
 * avant que l'une n'ait écrit : le paiement est exécuté DEUX FOIS, c'est-à-dire
 * que l'argent part deux fois pour un seul ordre approuvé.
 *
 * Ce test reproduit la course au niveau de la base — là où elle se joue
 * réellement — plutôt que de simuler deux processus PHP.
 */
final class PaymentConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;
    private int $paymentId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Race Probe',
            'e' => 'race_' . bin2hex(random_bytes(6)) . '@nexus.test',
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
        $beneficiaryId = (int) $this->pdo->lastInsertId();

        $pay = $this->pdo->prepare(
            'INSERT INTO payments
                (user_id, beneficiary_id, source_currency, dest_currency, amount, amount_ref,
                 fee, fee_currency, status, created_by, environment)
             VALUES (:u, :b, :sc, :dc, :amt, :aref, :fee, :fc, :st, :cb, :env)'
        );
        $pay->execute([
            'u' => $this->userId, 'b' => $beneficiaryId, 'sc' => 'EUR', 'dc' => 'EUR',
            'amt' => '100.00', 'aref' => '100.00', 'fee' => '0.00', 'fc' => 'EUR',
            'st' => 'approved', 'cb' => $this->userId, 'env' => 'sandbox',
        ]);
        $this->paymentId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            foreach (['payments', 'beneficiaries', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$this->userId]);
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->userId = 0;
        }
    }

    /**
     * Simule la prise de verrou effectuée par le contrôleur : l'UPDATE
     * conditionnel « approved → executing » ne doit réussir que pour UN seul
     * appelant. Le nombre de lignes affectées est l'arbitre.
     */
    private function claim(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE payments SET status = 'executing'
              WHERE id = :id AND user_id = :uid AND status = 'approved'"
        );
        $stmt->execute(['id' => $this->paymentId, 'uid' => $this->userId]);

        return $stmt->rowCount();
    }

    // ══ 1. Un seul appelant peut réclamer le paiement ══════════════════════

    /**
     * Deux tentatives consécutives de réservation : la première réussit, la
     * seconde ne doit affecter AUCUNE ligne. C'est ce qui empêche deux
     * requêtes concurrentes d'exécuter le même ordre.
     */
    public function test_only_one_caller_can_claim_an_approved_payment(): void
    {
        $this->assertSame(1, $this->claim(), 'Le premier appelant doit réserver le paiement.');
        $this->assertSame(
            0,
            $this->claim(),
            'Le second appelant ne doit pas pouvoir réserver un paiement déjà en cours d\'exécution.'
        );
    }

    // ══ 2. Un paiement non approuvé n'est jamais réclamable ════════════════

    public function test_a_non_approved_payment_cannot_be_claimed(): void
    {
        foreach (['draft', 'pending_approval', 'rejected', 'cancelled', 'completed', 'failed'] as $status) {
            $this->pdo->prepare('UPDATE payments SET status = :s WHERE id = :id')
                ->execute(['s' => $status, 'id' => $this->paymentId]);

            $this->assertSame(
                0,
                $this->claim(),
                sprintf('Un paiement « %s » ne doit jamais pouvoir être exécuté.', $status)
            );
        }
    }

    // ══ 3. Le contrôleur utilise bien une réservation atomique ═════════════

    /**
     * Vérifie dans le SOURCE que l'écriture de `executing` est conditionnée au
     * statut source. Un test de comportement ne peut pas distinguer les deux
     * implémentations sans vrai parallélisme ; ce contrôle structurel, lui,
     * échoue si quelqu'un retire la condition.
     */
    public function test_controller_claims_the_payment_atomically(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Controllers/PaymentController.php');

        $this->assertMatchesRegularExpression(
            "/UPDATE payments SET status = 'executing'[^\"]*status = 'approved'/s",
            $source,
            'Le passage en « executing » doit être conditionné au statut « approved » dans la requête '
            . 'elle-même : sans cela, deux requêtes concurrentes exécutent le même paiement deux fois.'
        );
    }
}
