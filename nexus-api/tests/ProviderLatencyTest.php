<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\ProviderLatency;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Verrou anti-délai-fabriqué (boucle 14).
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `CapabilityEngine::CATEGORY_DELAYS` fixait le délai par CATÉGORIE :
 * `'mobile_money' => [60, 300]`. Les trois providers Mobile Money d'un
 * corridor annonçaient donc le même « ~3 min », quels que soient leurs temps
 * réels.
 *
 * Preuve relevée pendant l'audit : après 20 exécutions à 600 s pour pawaPay
 * et 20 à 30 s pour MTN MoMo — un écart mesuré de 20× — l'API annonçait
 * « ~3 min » pour les deux. Et sur une base sans AUCUNE transaction, elle
 * annonçait déjà « ~3 min ».
 *
 * Le test central est `test_un_provider_lent_affiche_un_delai_long` : il
 * rejoue ce scénario et exige que la mesure suive les faits.
 *
 * Aucun test ne couvrait les délais avant cette boucle.
 *
 * ISOLATION (§16) : chaque test utilise un slug unique et ne compte que ses
 * propres lignes — nexus_test peut contenir des données étrangères.
 */
final class ProviderLatencyTest extends TestCase
{
    private PDO $pdo;

    private string $slug;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        $this->slug    = 'lat_' . bin2hex(random_bytes(4));
        $this->userIds = [];

        ProviderLatency::resetCache();
    }

    protected function tearDown(): void
    {
        try {
            $this->pdo->prepare('DELETE FROM transactions WHERE provider = ?')->execute([$this->slug]);

            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[ProviderLatencyTest] ' . $e->getMessage() . PHP_EOL);
        }

        ProviderLatency::resetCache();
    }

    // ── Absence de mesure ───────────────────────────────────────────────────

    public function test_sans_execution_le_delai_est_UNAVAILABLE_et_nul(): void
    {
        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderLatency::UNAVAILABLE, $res['status']);
        // Le point capital : aucun délai inventé pour combler le vide.
        self::assertNull($res['seconds']);
        self::assertFalse($res['measured']);
        self::assertSame(0, $res['observations']);
    }

    public function test_trop_peu_d_executions_donne_INSUFFICIENT_DATA(): void
    {
        $this->seed(array_fill(0, 5, 120));

        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderLatency::INSUFFICIENT_DATA, $res['status']);
        self::assertNull($res['seconds'], 'Un échantillon trop faible ne doit produire aucun ETA.');
        self::assertSame(5, $res['observations']);
    }

    /**
     * Une exécution sans chronométrage n'est pas une observation de durée.
     */
    public function test_les_executions_sans_duree_ne_comptent_pas(): void
    {
        $this->seed(array_fill(0, ProviderLatency::MIN_OBSERVATIONS, null));

        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderLatency::UNAVAILABLE, $res['status']);
        self::assertNull($res['seconds']);
    }

    /**
     * Un transfert encore en cours n'a pas de durée finale.
     */
    public function test_les_executions_non_terminees_ne_comptent_pas(): void
    {
        $this->seed(array_fill(0, ProviderLatency::MIN_OBSERVATIONS, 60), 'sandbox', 'processing');

        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderLatency::UNAVAILABLE, $res['status']);
    }

    // ── Mesure réelle ───────────────────────────────────────────────────────

    /**
     * LE test de la boucle : le scénario exact constaté en HTTP, où un
     * provider à 600 s réels affichait « ~3 min ».
     */
    public function test_un_provider_lent_affiche_un_delai_long(): void
    {
        $this->seed(array_fill(0, ProviderLatency::MIN_OBSERVATIONS, 600));

        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderLatency::MEASURED, $res['status']);
        self::assertTrue($res['measured']);
        self::assertSame(
            600,
            $res['seconds'],
            'Un provider mesuré à 600 s ne peut pas annoncer le délai générique de sa catégorie.'
        );
        // Et surtout : pas la valeur qu'affichait la constante (180 s = ~3 min).
        self::assertNotSame(180, $res['seconds']);
    }

    public function test_un_provider_rapide_affiche_un_delai_court(): void
    {
        $this->seed(array_fill(0, ProviderLatency::MIN_OBSERVATIONS, 30));

        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderLatency::MEASURED, $res['status']);
        self::assertSame(30, $res['seconds']);
    }

    /**
     * Deux providers aux temps très différents doivent produire deux mesures
     * très différentes — c'est exactement ce que la constante empêchait.
     */
    public function test_deux_providers_distincts_ne_partagent_pas_le_meme_delai(): void
    {
        $slowSlug = $this->slug;
        $this->seed(array_fill(0, ProviderLatency::MIN_OBSERVATIONS, 600));

        $fastSlug   = 'lat_fast_' . bin2hex(random_bytes(4));
        $this->slug = $fastSlug;
        $this->seed(array_fill(0, ProviderLatency::MIN_OBSERVATIONS, 30));

        ProviderLatency::resetCache();
        $slow = ProviderLatency::forProvider($slowSlug, ExecutionEnvironment::SANDBOX);
        $fast = ProviderLatency::forProvider($fastSlug, ExecutionEnvironment::SANDBOX);

        try {
            self::assertSame(600, $slow['seconds']);
            self::assertSame(30, $fast['seconds']);
            self::assertNotSame(
                $slow['seconds'],
                $fast['seconds'],
                'Deux providers aux performances opposées ne peuvent pas afficher le même délai.'
            );
        } finally {
            $this->pdo->prepare('DELETE FROM transactions WHERE provider = ?')->execute([$slowSlug]);
        }
    }

    /**
     * La médiane, et non la moyenne : un incident isolé ne doit pas
     * requalifier un provider habituellement rapide.
     */
    public function test_un_incident_isole_ne_fausse_pas_l_ETA(): void
    {
        // 19 transferts à 60 s, un seul bloqué 40 minutes.
        $durations   = array_fill(0, ProviderLatency::MIN_OBSERVATIONS - 1, 60);
        $durations[] = 2400;
        $this->seed($durations);

        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(ProviderLatency::MEASURED, $res['status']);
        self::assertSame(60, $res['seconds'], 'La médiane doit résister à une valeur extrême.');
        // La moyenne aurait donné ~177 s : presque trois fois le vécu réel.
        self::assertLessThan(120, $res['seconds']);
    }

    /**
     * Le p90 expose la borne haute réellement observée.
     */
    public function test_le_p90_reflete_les_executions_lentes(): void
    {
        $durations = array_merge(
            array_fill(0, 18, 60),
            [300, 600]
        );
        $this->seed($durations);

        $res = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);

        self::assertSame(60, $res['seconds']);
        self::assertNotNull($res['p90_seconds']);
        self::assertGreaterThan(
            $res['seconds'],
            $res['p90_seconds'],
            'Le p90 doit rendre visible la traîne lente, que la médiane masque.'
        );
    }

    // ── Isolation par environnement ─────────────────────────────────────────

    public function test_les_mesures_sandbox_ne_contaminent_pas_la_production(): void
    {
        $this->seed(array_fill(0, ProviderLatency::MIN_OBSERVATIONS, 45), 'sandbox');

        $sandbox = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::SANDBOX);
        self::assertSame(ProviderLatency::MEASURED, $sandbox['status']);
        self::assertSame(45, $sandbox['seconds']);

        $production = ProviderLatency::forProvider($this->slug, ExecutionEnvironment::PRODUCTION);
        self::assertSame(
            ProviderLatency::UNAVAILABLE,
            $production['status'],
            'Les rails de test d\'un provider ne disent rien de ses rails réels.'
        );
        self::assertNull($production['seconds']);
    }

    /**
     * L'ExecutionEngine enregistre le NOM d'affichage de la route
     * (« Orange Money »), pas le slug (« orange_money »). Ignorer cette forme
     * amputerait la mesure d'exécutions bien réelles.
     *
     * Le test compare un AVANT/APRÈS : d'autres lignes peuvent exister pour
     * ce provider dans nexus_test (§16).
     */
    public function test_les_executions_enregistrees_sous_le_nom_catalogue_sont_comptees(): void
    {
        ProviderLatency::resetCache();
        $before = ProviderLatency::forProvider('orange_money', ExecutionEnvironment::SANDBOX);

        $userId = $this->userId();
        $stmt   = $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, amount_ref,
                 status, provider, environment, execution_time_seconds, created_at)
             VALUES (:uid, 'send', 'out', 'Test latence identite', 10, 'EUR', 10,
                     'completed', 'Orange Money', 'sandbox', 77, NOW())"
        );

        $added = ProviderLatency::MIN_OBSERVATIONS;
        for ($i = 0; $i < $added; $i++) {
            $stmt->execute(['uid' => $userId]);
        }

        ProviderLatency::resetCache();
        $after = ProviderLatency::forProvider('orange_money', ExecutionEnvironment::SANDBOX);

        try {
            self::assertSame(
                $before['observations'] + $added,
                $after['observations'],
                'Les exécutions enregistrées sous le nom catalogue doivent être comptées : '
                . 'les ignorer amputerait la mesure.'
            );
            self::assertSame(ProviderLatency::MEASURED, $after['status']);
        } finally {
            $this->pdo->prepare("DELETE FROM transactions WHERE label = 'Test latence identite'")
                ->execute();
            ProviderLatency::resetCache();
        }
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function userId(): int
    {
        if ($this->userIds !== []) {
            return $this->userIds[0];
        }

        $suffix = bin2hex(random_bytes(4));
        $stmt   = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:n, :e, :p, :t, :s, :k)'
        );
        $stmt->execute([
            'n' => 'Latency ' . $suffix,
            'e' => 'latency_' . $suffix . '@nexus-test.local',
            'p' => '',
            't' => 'personal',
            's' => 'ACTIVE',
            'k' => 'none',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }

    /**
     * @param list<int|null> $durations
     */
    private function seed(array $durations, string $env = 'sandbox', string $status = 'completed'): void
    {
        $userId = $this->userId();
        $stmt   = $this->pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, amount_ref,
                 status, provider, environment, execution_time_seconds, created_at)
             VALUES (:uid, 'send', 'out', 'Test latence', 10, 'EUR', 10,
                     :status, :provider, :env, :secs, NOW())"
        );

        foreach ($durations as $seconds) {
            $stmt->execute([
                'uid'      => $userId,
                'status'   => $status,
                'provider' => $this->slug,
                'env'      => $env,
                'secs'     => $seconds,
            ]);
        }

        ProviderLatency::resetCache();
    }
}
