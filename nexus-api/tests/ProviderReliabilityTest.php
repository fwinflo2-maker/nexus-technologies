<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\ProviderReliability;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Verrou anti-score-fabriqué sur la fiabilité des providers (boucle 13).
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `CapabilityEngine::PERFORMANCE_SCORES` était une constante de 20 valeurs
 * écrites à la main (`pawapay => 0.97`…), présentées au client comme une
 * mesure et pesant jusqu'à 55 % du classement des routes.
 *
 * Preuve relevée pendant l'audit : après 10 paiements pawaPay `failed` sur 10,
 * l'API annonçait toujours « Élevée / 0.97 / 🛡️ PLUS FIABLE ». Le nombre
 * était insensible à la réalité qu'il prétendait décrire.
 *
 * Le test central ici est `test_dix_echecs_reels_produisent_un_score_de_zero` :
 * il rejoue exactement ce scénario et exige que la mesure suive les faits.
 *
 * Aucun test ne couvrait la fiabilité avant cette boucle — c'est ce qui a
 * permis à la constante de survivre à 484 tests verts.
 */
final class ProviderReliabilityTest extends TestCase
{
    private PDO $pdo;

    /** Slug unique par test : aucune collision avec des données existantes. */
    private string $slug;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        $this->slug = 'testprov_' . bin2hex(random_bytes(4));
        $this->userIds = [];

        ProviderReliability::resetCache();
    }

    protected function tearDown(): void
    {
        try {
            $this->pdo->prepare('DELETE FROM transactions WHERE provider = ?')->execute([$this->slug]);
            $this->pdo->prepare('DELETE FROM payments WHERE provider = ?')->execute([$this->slug]);

            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[ProviderReliabilityTest] ' . $e->getMessage() . PHP_EOL);
        }

        ProviderReliability::resetCache();
    }

    // ── Absence de données ──────────────────────────────────────────────────

    public function test_sans_aucune_execution_la_fiabilite_est_UNAVAILABLE_et_sans_score(): void
    {
        $res = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderReliability::UNAVAILABLE, $res['status']);
        // Le point capital : pas de score inventé pour combler le vide.
        self::assertNull($res['score']);
        self::assertFalse($res['measured']);
        self::assertSame(0, $res['observations']);
    }

    public function test_trop_peu_d_executions_donne_INSUFFICIENT_DATA_sans_score(): void
    {
        // Un seul succès afficherait 100 % : une observation n'est pas une
        // statistique.
        $this->seedTransactions(1, 'completed');
        ProviderReliability::resetCache();

        $res = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderReliability::INSUFFICIENT_DATA, $res['status']);
        self::assertNull($res['score'], 'Un échantillon trop faible ne doit produire aucun score.');
        self::assertFalse($res['measured']);
        self::assertSame(1, $res['observations']);
    }

    // ── Mesure réelle ───────────────────────────────────────────────────────

    /**
     * LE test de la boucle : le scénario exact constaté en HTTP pendant
     * l'audit, où 10 échecs réels laissaient le score à 0.97.
     */
    public function test_dix_echecs_reels_produisent_un_score_de_zero(): void
    {
        // 20 échecs : au-dessus du seuil, donc mesurable, et 0 % de succès.
        $this->seedTransactions(ProviderReliability::MIN_OBSERVATIONS, 'failed');
        ProviderReliability::resetCache();

        $res = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderReliability::MEASURED, $res['status']);
        self::assertTrue($res['measured']);
        self::assertSame(
            0.0,
            $res['score'],
            'Un provider qui échoue systématiquement ne peut pas afficher une fiabilité élevée.'
        );
        self::assertSame(ProviderReliability::MIN_OBSERVATIONS, $res['observations']);
    }

    public function test_le_score_reflete_le_taux_de_succes_observe(): void
    {
        // 15 succès + 5 échecs = 20 observations, 75 % de succès.
        $this->seedTransactions(15, 'completed');
        $this->seedTransactions(5, 'failed');
        ProviderReliability::resetCache();

        $res = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderReliability::MEASURED, $res['status']);
        self::assertSame(0.75, $res['score']);
        self::assertSame(20, $res['observations']);
        self::assertSame(15, $res['successes']);
        self::assertSame(5, $res['failures']);
    }

    /**
     * Les exécutions en cours ne sont pas des verdicts.
     */
    public function test_les_executions_non_terminees_ne_comptent_pas(): void
    {
        $this->seedTransactions(ProviderReliability::MIN_OBSERVATIONS, 'completed');
        $this->seedTransactions(50, 'pending');
        $this->seedTransactions(50, 'processing');
        $this->seedTransactions(50, 'cancelled');
        ProviderReliability::resetCache();

        $res = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        // Seuls les 20 « completed » sont des observations.
        self::assertSame(ProviderReliability::MIN_OBSERVATIONS, $res['observations']);
        self::assertSame(1.0, $res['score']);
    }

    /**
     * Un paiement Business et un transfert Personal sont deux exécutions
     * réelles du même provider : les deux comptent.
     */
    public function test_les_paiements_business_comptent_aussi(): void
    {
        $this->seedTransactions(10, 'completed');
        $this->seedPayments(10, 'failed');
        ProviderReliability::resetCache();

        $res = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderReliability::MEASURED, $res['status']);
        self::assertSame(20, $res['observations']);
        self::assertSame(0.5, $res['score']);
    }

    /**
     * L'ExecutionEngine enregistre le NOM d'affichage de la route
     * (« pawaPay »), pas le slug (« pawapay ») : vérifié en base, les deux
     * formes coexistent dans `transactions.provider`.
     *
     * Ne compter que le slug amputerait la mesure d'exécutions bien réelles —
     * et une mesure incomplète est une mesure fausse.
     */
    public function test_les_executions_enregistrees_sous_le_nom_catalogue_sont_comptees(): void
    {
        // L'ExecutionEngine écrit le NOM d'affichage de la route, pas le slug.
        // On choisit orange_money : slug « orange_money » vs nom
        // « Orange Money ». L'écart (underscore/espace) n'est PAS rattrapable
        // par la collation insensible à la casse — contrairement à
        // pawapay/pawaPay, où la collation masquerait le défaut et laisserait
        // la mutation survivre.
        //
        // Le test compare un AVANT/APRÈS plutôt qu'un total absolu : d'autres
        // lignes peuvent exister dans nexus_test pour ce provider, et un test
        // ne doit pas dépendre de données qu'il n'a pas créées (§16).
        $before = ProviderReliability::forProvider('orange_money', ExecutionEnvironment::SANDBOX);

        $userId = $this->userId();
        $stmt = $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, amount_ref,
                 status, provider, environment, created_at)
             VALUES (:uid, 'send', 'out', 'Test identite', 100, 'EUR', 100,
                     'completed', 'Orange Money', 'sandbox', NOW())"
        );

        $added = ProviderReliability::MIN_OBSERVATIONS;
        for ($i = 0; $i < $added; $i++) {
            $stmt->execute(['uid' => $userId]);
        }

        ProviderReliability::resetCache();
        $after = ProviderReliability::forProvider('orange_money', ExecutionEnvironment::SANDBOX);

        try {
            self::assertSame(
                $before['observations'] + $added,
                $after['observations'],
                'Les exécutions enregistrées sous le nom catalogue doivent être comptées : '
                . 'les ignorer amputerait la mesure d\'exécutions bien réelles.'
            );
            self::assertSame(ProviderReliability::MEASURED, $after['status']);
        } finally {
            $this->pdo->prepare("DELETE FROM transactions WHERE label = 'Test identite'")->execute();
            ProviderReliability::resetCache();
        }
    }

    // ── Isolation par environnement ─────────────────────────────────────────

    /**
     * Des succès en sandbox ne disent rien de la production : les mélanger
     * ferait passer un provider jamais utilisé en argent réel pour éprouvé.
     */
    public function test_les_mesures_sandbox_ne_contaminent_pas_la_production(): void
    {
        $this->seedTransactions(ProviderReliability::MIN_OBSERVATIONS, 'completed', 'sandbox');
        ProviderReliability::resetCache();

        $sandbox = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::SANDBOX);
        self::assertSame(ProviderReliability::MEASURED, $sandbox['status']);
        self::assertSame(1.0, $sandbox['score']);

        $production = ProviderReliability::forProvider($this->slug, ExecutionEnvironment::PRODUCTION);
        self::assertSame(
            ProviderReliability::UNAVAILABLE,
            $production['status'],
            'Un provider éprouvé en sandbox reste non mesuré en production.'
        );
        self::assertNull($production['score']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function userId(): int
    {
        if ($this->userIds !== []) {
            return $this->userIds[0];
        }

        $suffix = bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:n, :e, :p, :t, :s, :k)'
        );
        $stmt->execute([
            'n' => 'Reliability ' . $suffix,
            'e' => 'reliability_' . $suffix . '@nexus-test.local',
            'p' => '',
            't' => 'personal',
            's' => 'ACTIVE',
            'k' => 'none',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }

    private function seedTransactions(int $count, string $status, string $env = 'sandbox'): void
    {
        $userId = $this->userId();
        $stmt = $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, amount_ref,
                 status, provider, environment, created_at)
             VALUES (:uid, 'send', 'out', 'Test fiabilité', 100, 'EUR', 100,
                     :status, :provider, :env, NOW())"
        );

        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([
                'uid'      => $userId,
                'status'   => $status,
                'provider' => $this->slug,
                'env'      => $env,
            ]);
        }
    }

    private function seedPayments(int $count, string $status, string $env = 'sandbox'): void
    {
        $userId = $this->userId();
        $stmt = $this->pdo->prepare(
            "INSERT INTO payments
                (user_id, purpose, source_currency, dest_currency, amount, amount_ref,
                 fee, dest_amount, fx_rate, provider, environment, status, created_by, created_at)
             VALUES (:uid, 'Test fiabilité', 'EUR', 'XAF', 100, 100,
                     2, 65000, 650, :provider, :env, :status, :uid2, NOW())"
        );

        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([
                'uid'      => $userId,
                'uid2'     => $userId,
                'status'   => $status,
                'provider' => $this->slug,
                'env'      => $env,
            ]);
        }
    }
}
