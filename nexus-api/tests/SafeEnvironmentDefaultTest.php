<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 17 — LE DÉFAUT PENCHE DU CÔTÉ LE MOINS DANGEREUX.
 *
 * LE DÉFAUT
 * ─────────
 * Six tables financières portaient `environment ... DEFAULT 'production'`.
 * Tout INSERT omettant la colonne créait donc de l'ARGENT RÉEL : l'oubli le
 * plus banal produisait la conséquence la plus grave.
 *
 * Ce n'était pas théorique. `AuthController::seedDemoTransactions()` omettait
 * la colonne. Vérifié en base : après une simple inscription, 5 transactions
 * de démonstration (« Réception SEPA », « Envoi Mobile Money ») étaient
 * marquées `production` et entraient dans les vues et totaux d'argent réel —
 * alors que `DemoMode` interdit précisément toute donnée fictive en
 * production. Le garde-fou existait ; le défaut SQL le contournait.
 *
 * LE PRINCIPE
 * ───────────
 * La production doit être DEMANDÉE, jamais héritée. En cas d'oubli, mieux
 * vaut une opération classée « test » — visible, corrigeable, sans
 * conséquence financière — qu'une opération classée « argent réel ».
 *
 * Ces tests ne remplacent pas les garanties applicatives : tous les chemins
 * financiers passent `environment` explicitement depuis l'ExecutionContext.
 * Ils protègent la défense de DERNIER RECOURS, pour le jour où un chemin
 * l'oubliera — comme le seeder l'avait oublié.
 */
final class SafeEnvironmentDefaultTest extends TestCase
{
    private PDO $pdo;

    /** Tables de la chaîne financière portant un environnement. */
    private const FINANCIAL_TABLES = [
        'transactions',
        'payments',
        'quotes',
        'wallet_operations',
        'ledger_entries',
        'idempotency_keys',
    ];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Aucune table financière ne doit défaillir vers « production ».
     */
    public function test_no_financial_table_defaults_to_production(): void
    {
        foreach (self::FINANCIAL_TABLES as $table) {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = :t
                    AND COLUMN_NAME  = :c'
            );
            $stmt->execute(['t' => $table, 'c' => 'environment']);
            $default = $stmt->fetchColumn();

            $this->assertNotFalse($default, sprintf('%s.environment doit exister.', $table));

            $this->assertSame(
                'sandbox',
                trim((string) $default, "'"),
                sprintf(
                    '%s.environment doit défaillir vers « sandbox ». Un défaut « production » '
                    . 'transforme tout oubli de colonne en argent réel.',
                    $table
                )
            );
        }
    }

    /**
     * Le comportement RÉEL, pas seulement la déclaration du schéma.
     *
     * Un INSERT qui omet la colonne doit produire une ligne « sandbox ».
     */
    public function test_an_insert_without_environment_lands_in_sandbox(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, status)
             VALUES (:n, :e, :p, :t, :c, :s)'
        );
        $stmt->execute([
            'n' => 'Default Probe',
            'e' => 'default_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);
        $userId = (int) $this->pdo->lastInsertId();

        try {
            // Volontairement SANS colonne `environment`.
            $stmt = $this->pdo->prepare(
                "INSERT INTO transactions
                    (user_id, type, direction, label, amount, currency,
                     amount_ref, ref_currency, amount_xaf, fee, fee_currency,
                     status, created_at)
                 VALUES (:u, 'send', 'out', 'Oubli volontaire', 10.00, 'EUR',
                         10.00, 'EUR', 6500.00, 0.00, 'EUR', 'completed', NOW())"
            );
            $stmt->execute(['u' => $userId]);
            $txId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare('SELECT environment FROM transactions WHERE id = ?');
            $stmt->execute([$txId]);

            $this->assertSame(
                'sandbox',
                $stmt->fetchColumn(),
                'Une transaction insérée sans environnement ne doit JAMAIS être de l\'argent réel.'
            );
        } finally {
            $this->pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }

    /**
     * Le seeder de démonstration doit marquer ses transactions comme
     * sandbox, explicitement.
     *
     * Le défaut SQL sûr suffirait aujourd'hui, mais un défaut est une
     * protection passive : si quelqu'un le remet à 'production', le seeder
     * doit rester correct par lui-même. Ceinture ET bretelles, parce que
     * cette donnée est fictive par nature.
     */
    public function test_the_demo_seeder_marks_its_transactions_explicitly(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
        $this->assertIsString($source);

        // La liste de colonnes du seeder doit inclure `environment`.
        $this->assertMatchesRegularExpression(
            '/INSERT INTO transactions.{0,400}environment\)/s',
            $source,
            'Le seeder de démo doit fournir la colonne environment explicitement.'
        );

        // Le littéral est échappé dans la requête PHP : \'sandbox\'
        $this->assertMatchesRegularExpression(
            "/\\\\'sandbox\\\\'\\)/",
            $source,
            'Les transactions de démonstration doivent être marquées sandbox.'
        );
    }

    /**
     * `audit_logs.environment` doit rester NULL par défaut.
     *
     * Un événement d'authentification n'appartient à aucun environnement. Lui
     * en inventer un — même « sandbox » — serait falsifier le journal, ce que
     * l'audit est précisément censé empêcher. L'absence de valeur est une
     * information, pas un manque.
     */
    public function test_audit_logs_keeps_a_null_environment_by_default(): void
    {
        $stmt = $this->pdo->query(
            "SELECT COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'audit_logs'
                AND COLUMN_NAME  = 'environment'"
        );
        $row = $stmt->fetch();

        $this->assertSame('YES', $row['IS_NULLABLE'], 'Un événement hors environnement doit pouvoir rester NULL.');
        // MariaDB renvoie la CHAÎNE 'NULL' pour un défaut NULL sur une
        // colonne nullable ; PDO peut aussi renvoyer null. Les deux
        // expriment la même chose : aucune valeur imposée.
        $default = $row['COLUMN_DEFAULT'];
        $this->assertTrue(
            $default === null || strtoupper((string) $default) === 'NULL',
            'Aucun environnement ne doit être inventé pour un événement auth (obtenu : '
                . var_export($default, true) . ').'
        );
    }
}
