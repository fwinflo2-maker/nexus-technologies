<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 — le seed de démonstration ne doit pas bloquer le parcours Send.
 *
 * RÉGRESSION (audit) : le seed générait des séries d'envois qui consumaient
 * des dizaines de milliers d'EUR ; le compte démo (ex. Marc Lefèvre,
 * standard, plafond 2000 EUR/mois) était donc PERMANEMMENT bloqué par
 * PolicyEngine à l'étape 3 de /send. Ce test exécute le VRAI script de seed
 * contre une base vierge et vérifie :
 *   - chaque utilisateur reste SOUS son plafond KYC mensuel (en EUR) ;
 *   - la marge restante permet au moins un petit envoi de test.
 *
 * Les plafonds doivent rester alignés sur PolicyEngine (KYC_LIMITS).
 */
final class SeedKycLimitsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Refus de tourner contre la base "' . $dbName . '".');
        }
    }

    /** Plafonds mensuels KYC en EUR — miroir de PolicyEngine. */
    private function kycLimits(): array
    {
        return [
            1 => 10000.0,   // Super Admin (advanced)
            2 => 2000.0,    // Marc Lefèvre (standard)
            3 => 2000.0,    // Sophie Martin (standard)
            4 => 1000.0,    // Jean Dupont (basic)
            5 => 10000.0,   // Business (advanced)
        ];
    }

    public function test_seed_respects_kyc_monthly_caps_for_every_user(): void
    {
        // Le seed exige des tables vierges (il fait ses propres DELETE).
        // Il tourne contre nexus_test (BDD de test, jamais la dev).
        $env = [
            'SEED_DB_NAME' => 'nexus_test',
            'SEED_DB_USER' => 'nexus',
            'SEED_DB_PASS' => 'nexus_dev_pw',
            'SEED_DB_HOST' => '127.0.0.1',
        ];
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/../scripts/seed_dev_data.php');
        $previous = [];
        foreach ($env as $k => $v) {
            $previous[$k] = getenv($k);
            putenv($k . '=' . $v);
        }

        try {
            exec($cmd . ' 2>&1', $output, $exitCode);
        } finally {
            foreach ($previous as $k => $v) {
                $v === false ? putenv($k) : putenv($k . '=' . $v);
            }
        }
        $this->assertSame(
            0,
            $exitCode,
            'Le seed a échoué : ' . implode("\n", $output)
        );

        // Totaux mensuels (même requête que PolicyEngine::getMonthlyTotal) :
        // type=send, direction=out, statuts non annulés/échoués, mois courant.
        $rows = $this->pdo->query(
            "SELECT user_id, COALESCE(SUM(amount_ref), 0) AS total
             FROM transactions
             WHERE type = 'send' AND direction = 'out'
               AND status NOT IN ('cancelled', 'failed')
               AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
             GROUP BY user_id"
        )->fetchAll();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['user_id']] = (float) $row['total'];
        }

        foreach ($this->kycLimits() as $uid => $cap) {
            $used = $totals[$uid] ?? 0.0;
            $this->assertLessThanOrEqual(
                $cap,
                $used,
                sprintf("L'utilisateur %d consomme %.2f EUR sur un plafond de %.2f EUR : Send est bloqué.", $uid, $used, $cap)
            );
            // Marge : il doit rester de quoi envoyer un petit montant de test.
            $this->assertGreaterThan(
                50.0,
                $cap - $used,
                sprintf("L'utilisateur %d n'a plus de marge pour un envoi de test (restant %.2f EUR).", $uid, $cap - $used)
            );
        }
    }
}
