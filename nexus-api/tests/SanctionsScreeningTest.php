<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\PolicyEngine;
use Nexus\Services\SanctionsScreening;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Verrou anti-faux-succès sur le filtrage des sanctions (boucle 12).
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `PolicyEngine` parcourait une constante `SANCTION_LIST = []` : zéro
 * itération, zéro contrôle, puis « Tous les contrôles de conformité sont
 * passés ». Un contrôle réglementaire déclaré effectué alors qu'il n'a jamais
 * eu lieu, sur le chemin réel du Send Personal et des paiements Business.
 *
 * Le point crucial est le test de production : sans liste configurée, une
 * transaction en argent réel doit être REFUSÉE. Si quelqu'un « simplifie »
 * un jour le screening en renvoyant CLEARED par défaut, ce test tombe.
 */
final class SanctionsScreeningTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds: list<int>, walletIds: list<int>} */
    private array $created = ['userIds' => [], 'walletIds' => []];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Refus de tourner contre la base "' . $dbName . '".');
        }

        $this->created = ['userIds' => [], 'walletIds' => []];

        // L'état d'origine est restauré en tearDown : ces tests manipulent des
        // variables d'environnement globales au processus PHPUnit.
        foreach ([SanctionsScreening::ENV_COUNTRIES, SanctionsScreening::ENV_LIST_FILE] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }

        try {
            if (!empty($this->created['walletIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")
                    ->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")
                    ->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[SanctionsScreeningTest::tearDown] ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ── Le composant de filtrage ────────────────────────────────────────────

    public function test_sans_source_configuree_le_filtrage_est_UNAVAILABLE_jamais_CLEARED(): void
    {
        $res = SanctionsScreening::screenCountry('CG');

        self::assertSame(SanctionsScreening::UNAVAILABLE, $res['status']);
        // Le point capital : le composant ADMET n'avoir rien vérifié.
        self::assertFalse($res['screened']);
        self::assertNotSame(SanctionsScreening::CLEARED, $res['status']);
        self::assertFalse(SanctionsScreening::isConfigured());
    }

    public function test_liste_configuree_sans_correspondance_donne_CLEARED(): void
    {
        putenv(SanctionsScreening::ENV_COUNTRIES . '=KP,IR,SY');

        $res = SanctionsScreening::screenCountry('FR');

        self::assertSame(SanctionsScreening::CLEARED, $res['status']);
        self::assertTrue($res['screened']);
        self::assertNull($res['matched']);
        self::assertSame(3, $res['entries']);
    }

    public function test_liste_configuree_avec_correspondance_donne_HIT(): void
    {
        putenv(SanctionsScreening::ENV_COUNTRIES . '=KP,IR,SY');

        $res = SanctionsScreening::screenCountry('ir'); // casse indifférente

        self::assertSame(SanctionsScreening::HIT, $res['status']);
        self::assertTrue($res['screened']);
        self::assertSame('IR', $res['matched']);
    }

    public function test_la_liste_est_lisible_depuis_un_fichier(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nexus_sanctions_');
        self::assertIsString($file);
        file_put_contents($file, "# Liste de test\nKP\n\nIR  # Iran\n");
        putenv(SanctionsScreening::ENV_LIST_FILE . '=' . $file);

        $hit = SanctionsScreening::screenCountry('KP');
        self::assertSame(SanctionsScreening::HIT, $hit['status']);

        // Les commentaires ne doivent pas devenir des entrées.
        $clear = SanctionsScreening::screenCountry('FR');
        self::assertSame(SanctionsScreening::CLEARED, $clear['status']);
        self::assertSame(2, $clear['entries']);

        unlink($file);
    }

    public function test_un_fichier_declare_mais_illisible_reste_UNAVAILABLE(): void
    {
        // Ne jamais interpréter « je n'ai pas pu lire la liste » comme
        // « il n'y a pas de sanctions ».
        putenv(SanctionsScreening::ENV_LIST_FILE . '=/nexus/inexistant/sanctions.txt');

        $res = SanctionsScreening::screenCountry('KP');

        self::assertSame(SanctionsScreening::UNAVAILABLE, $res['status']);
        self::assertFalse($res['screened']);
    }

    public function test_la_production_bloque_un_filtrage_indisponible(): void
    {
        self::assertTrue(SanctionsScreening::unavailableBlocks(ExecutionEnvironment::PRODUCTION));
        self::assertFalse(SanctionsScreening::unavailableBlocks(ExecutionEnvironment::SANDBOX));
    }

    // ── Intégration dans le PolicyEngine ────────────────────────────────────

    public function test_production_sans_liste_refuse_la_transaction(): void
    {
        // LE test de la boucle : argent réel + contrôle impossible = refus.
        $uid  = $this->fixture('500.00');
        $user = $this->user($uid);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        try {
            PolicyEngine::evaluate(
                $user,
                ['amount' => 100.0, 'sourceCurrency' => 'EUR', 'destCountry' => 'FR'],
                100.0,
                ExecutionEnvironment::PRODUCTION
            );
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertSame('POLICY_DECLINED', $e->errorCode());
            self::assertStringContainsString('sanctions', strtolower($e->getMessage()));
            throw $e;
        }
    }

    public function test_sandbox_sans_liste_signale_au_lieu_de_pretendre_conforme(): void
    {
        $uid  = $this->fixture('500.00');
        $user = $this->user($uid);

        $res = PolicyEngine::evaluate(
            $user,
            ['amount' => 100.0, 'sourceCurrency' => 'EUR', 'destCountry' => 'FR'],
            100.0,
            ExecutionEnvironment::SANDBOX
        );

        // La sandbox n'est pas bloquée…
        self::assertSame('REVIEW_REQUIRED', $res['decision']);
        // …mais le verdict ne ment pas sur ce qui a été fait.
        self::assertFalse($res['details']['sanctions_screened']);
        self::assertSame(SanctionsScreening::UNAVAILABLE, $res['details']['sanctions_status']);
        self::assertStringNotContainsString(
            'Tous les contrôles de conformité sont passés',
            $res['reason']
        );
    }

    public function test_production_avec_liste_et_pays_propre_approuve(): void
    {
        putenv(SanctionsScreening::ENV_COUNTRIES . '=KP,IR');

        $uid  = $this->fixture('500.00');
        $user = $this->user($uid);

        $res = PolicyEngine::evaluate(
            $user,
            ['amount' => 100.0, 'sourceCurrency' => 'EUR', 'destCountry' => 'FR'],
            100.0,
            ExecutionEnvironment::PRODUCTION
        );

        self::assertSame('APPROVED', $res['decision']);
        self::assertTrue($res['details']['sanctions_screened']);
        self::assertSame(SanctionsScreening::CLEARED, $res['details']['sanctions_status']);
        // Ici, et seulement ici, la formule est méritée.
        self::assertSame('Tous les contrôles de conformité sont passés.', $res['reason']);
    }

    public function test_un_pays_sanctionne_est_refuse_meme_avec_des_fonds_suffisants(): void
    {
        putenv(SanctionsScreening::ENV_COUNTRIES . '=KP,IR');

        $uid  = $this->fixture('5000.00');
        $user = $this->user($uid);

        $this->expectException(HttpException::class);

        try {
            PolicyEngine::evaluate(
                $user,
                ['amount' => 100.0, 'sourceCurrency' => 'EUR', 'destCountry' => 'KP'],
                100.0,
                ExecutionEnvironment::SANDBOX
            );
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertStringContainsString('réglementaire', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Sans environnement explicite, l'appelant ne doit pas hériter d'une
     * sandbox permissive : le défaut suit celui du déploiement.
     */
    public function test_le_defaut_suit_le_deploiement_et_non_une_sandbox_en_dur(): void
    {
        $saved = getenv('PROVIDERS_ENV');
        putenv('PROVIDERS_ENV=production');

        $uid  = $this->fixture('500.00');
        $user = $this->user($uid);

        try {
            PolicyEngine::evaluate(
                $user,
                ['amount' => 100.0, 'sourceCurrency' => 'EUR', 'destCountry' => 'FR'],
                100.0
                // aucun environnement passé : le défaut serveur s'applique
            );
            self::fail('Un appel sans environnement explicite doit suivre le défaut serveur (production) et refuser.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
        } finally {
            if ($saved === false) {
                putenv('PROVIDERS_ENV');
            } else {
                putenv('PROVIDERS_ENV=' . $saved);
            }
        }
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function fixture(string $balance): int
    {
        self::$counter++;
        $suffix = sprintf('%d_%d_%s', time(), self::$counter, bin2hex(random_bytes(3)));

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:name, :email, :pwd, :type, :status, :kyc)'
        );
        $stmt->execute([
            'name'   => 'Sanctions ' . $suffix,
            'email'  => 'sanctions_' . $suffix . '@nexus-test.local',
            'pwd'    => '',
            'type'   => 'personal',
            'status' => 'ACTIVE',
            'kyc'    => 'standard',
        ]);
        $uid = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $uid;

        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets
                (user_id, currency, balance, available_balance, hold_balance,
                 pending_balance, in_transit_balance, settlement_balance)
             VALUES (:uid, :cur, :bal, :avail, 0, 0, 0, 0)'
        );
        $stmt->execute(['uid' => $uid, 'cur' => 'EUR', 'bal' => $balance, 'avail' => $balance]);
        $this->created['walletIds'][] = (int) $this->pdo->lastInsertId();

        return $uid;
    }

    /** @return array<string, mixed> */
    private function user(int $uid): array
    {
        return [
            'id'           => $uid,
            'status'       => 'ACTIVE',
            'kyc_level'    => 'standard',
            'account_type' => 'personal',
        ];
    }
}
