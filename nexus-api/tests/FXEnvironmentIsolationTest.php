<?php

declare(strict_types=1);

namespace Nexus\Tests;

use DateTimeImmutable;
use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Models\FXRate;
use Nexus\Services\FXRateCache;
use Nexus\Services\FXService;
use Nexus\Services\QuotePricing;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Verrou d'isolation SANDBOX / PRODUCTION du cache FX (boucle 16).
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `fx_rates_cache` ne portait aucune notion d'environnement, et
 * `FXRateCache::lookup()` ne filtrait que sur la paire de devises. Toutes les
 * autres couches financières étaient pourtant isolées : ce cache était le
 * dernier maillon partagé.
 *
 * Prouvé en HTTP avant correctif :
 *   - un taux `EUR→XAF = 100` de source « audit_sandbox » a été servi à une
 *     quote demandée en PRODUCTION ;
 *   - un taux « audit_production » à 200 a été servi en sandbox, sur Send
 *     comme sur Convert.
 *
 * Et, cache vide, la production obtenait `655.957` du `ManualRateProvider` —
 * des taux codés en dur cotant de l'argent réel.
 *
 * ISOLATION (§16) : chaque test pose ses propres taux sous une source
 * reconnaissable et les retire, sans dépendre du contenu global de la table.
 */
final class FXEnvironmentIsolationTest extends TestCase
{
    private const TEST_SOURCE = 'iso_test';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        $this->purge();
        QuotePricing::resetCache();
    }

    protected function tearDown(): void
    {
        try {
            $this->purge();
        } catch (Throwable $e) {
            fwrite(STDERR, '[FXEnvironmentIsolationTest] ' . $e->getMessage() . PHP_EOL);
        }

        QuotePricing::resetCache();
    }

    // ── Test 1 : les deux environnements coexistent ─────────────────────────

    public function test_un_meme_couple_de_devises_porte_deux_taux_distincts(): void
    {
        $this->seed('EUR', 'XAF', '100.00000000', 'sandbox');
        $this->seed('EUR', 'XAF', '655.95700000', 'production');

        $sandbox    = FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
        $production = FXService::resolve('EUR', 'XAF', ExecutionEnvironment::PRODUCTION);

        self::assertSame('100.00000000', $sandbox->getRate());
        self::assertSame('655.95700000', $production->getRate());
        self::assertNotSame(
            $sandbox->getRate(),
            $production->getRate(),
            'Les deux environnements doivent pouvoir porter des taux différents.'
        );
    }

    // ── Tests 2 et 3 : chaque environnement lit le sien ─────────────────────

    public function test_la_sandbox_lit_le_taux_sandbox(): void
    {
        $this->seed('EUR', 'XAF', '100.00000000', 'sandbox');
        $this->seed('EUR', 'XAF', '655.95700000', 'production');

        $rate = FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        self::assertSame('100.00000000', $rate->getRate());
    }

    public function test_la_production_lit_le_taux_production(): void
    {
        $this->seed('EUR', 'XAF', '100.00000000', 'sandbox');
        $this->seed('EUR', 'XAF', '655.95700000', 'production');

        $rate = FXService::resolve('EUR', 'XAF', ExecutionEnvironment::PRODUCTION);

        self::assertSame('655.95700000', $rate->getRate());
    }

    // ── Test 4 : la sandbox ne contamine pas la production ──────────────────

    /**
     * LE test de la boucle : le scénario exact constaté en HTTP, où un taux
     * de test cotait de l'argent réel.
     */
    public function test_un_taux_sandbox_ne_cote_jamais_en_production(): void
    {
        // Seul un taux SANDBOX existe.
        $this->seed('EUR', 'XAF', '100.00000000', 'sandbox');

        $this->expectException(RuntimeException::class);

        try {
            FXService::resolve('EUR', 'XAF', ExecutionEnvironment::PRODUCTION);
        } catch (RuntimeException $e) {
            self::assertStringContainsString('production', strtolower($e->getMessage()));
            throw $e;
        }
    }

    // ── Test 5 : la production ne contamine pas la sandbox ──────────────────

    public function test_un_taux_production_ne_fuit_pas_en_sandbox(): void
    {
        // Seul un taux PRODUCTION existe pour cette paire.
        $this->seed('EUR', 'GHS', '14.80000000', 'production');

        // La sandbox ne doit pas le voir. Elle retombe sur le provider manuel,
        // qui ne connaît pas GHS : donc échec, et surtout PAS 14.80.
        try {
            $rate = FXService::resolve('EUR', 'GHS', ExecutionEnvironment::SANDBOX);
            self::assertNotSame(
                '14.80000000',
                $rate->getRate(),
                'La sandbox ne doit jamais lire un taux de production.'
            );
        } catch (RuntimeException) {
            // Comportement attendu : la paire est inconnue en sandbox.
            self::assertTrue(true);
        }
    }

    // ── Test 6 : fail-closed en production ──────────────────────────────────

    public function test_la_production_sans_taux_refuse_de_coter(): void
    {
        // Aucun taux pour cette paire, dans aucun environnement.
        $this->expectException(RuntimeException::class);

        FXService::resolve('EUR', 'USD', ExecutionEnvironment::PRODUCTION);
    }

    /**
     * Plus AUCUN repli manuel, même en sandbox : sans source FX réelle
     * branchée, l'absence de taux produit un REFUS explicite (§7). La
     * sandbox d'un provider réel n'existe que lorsque ses credentials
     * sandbox sont configurées — jamais un taux codé en dur.
     */
    public function test_la_sandbox_sans_taux_refuse_de_coter(): void
    {
        $this->expectException(RuntimeException::class);

        FXService::resolve('EUR', 'USD', ExecutionEnvironment::SANDBOX);
    }

    // ── Tests 7 et 8 : l'expiration respecte l'environnement ────────────────

    public function test_un_taux_sandbox_expire_ne_bascule_pas_sur_la_production(): void
    {
        // Le taux de production porte une valeur RECONNAISSABLE, distincte de
        // celle du provider manuel (655.957). Une première version de ce test
        // utilisait 655.957 des deux côtés : le repli manuel légitime était
        // alors indiscernable d'une contamination, et le test échouait sans
        // qu'aucun défaut n'existe.
        $this->seed('EUR', 'XAF', '100.00000000', 'sandbox', -3600);   // expiré
        $this->seed('EUR', 'XAF', '777.00000000', 'production', 3600); // valide

        // La sandbox a un taux EXPIRÉ pour cette paire et AUCUN repli :
        // elle refuse, et surtout ne lit pas le taux de production.
        try {
            FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
            self::fail('Un taux sandbox expiré ne doit pas être remplacé par celui de production, ni par un taux codé en dur.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('EUR', $e->getMessage());
        }
    }

    public function test_un_taux_production_expire_ne_bascule_pas_sur_la_sandbox(): void
    {
        $this->seed('EUR', 'XAF', '123.00000000', 'sandbox', 3600);     // valide
        $this->seed('EUR', 'XAF', '655.95700000', 'production', -3600); // expiré

        $this->expectException(RuntimeException::class);

        try {
            FXService::resolve('EUR', 'XAF', ExecutionEnvironment::PRODUCTION);
        } catch (RuntimeException $e) {
            // Surtout pas le taux sandbox à 123.
            self::assertStringNotContainsString('123', $e->getMessage());
            throw $e;
        }
    }

    // ── Cohérence de bout en bout ───────────────────────────────────────────

    /**
     * `QuotePricing` (Send) doit hériter de la même isolation, cache par
     * requête compris : sans clé d'environnement, la première résolution
     * fixerait le taux des suivantes.
     */
    public function test_le_cache_par_requete_est_scope_par_environnement(): void
    {
        $this->seed('EUR', 'XAF', '100.00000000', 'sandbox');
        $this->seed('EUR', 'XAF', '655.95700000', 'production');

        $sandbox    = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
        $production = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::PRODUCTION);

        self::assertSame(100.0, $sandbox['rate']);
        self::assertSame(
            655.957,
            $production['rate'],
            'Le cache par requête ne doit pas rejouer le taux sandbox pour la production.'
        );
    }

    public function test_quote_pricing_refuse_la_production_sans_taux(): void
    {
        $this->seed('EUR', 'XAF', '100.00000000', 'sandbox');

        $res = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::PRODUCTION);

        self::assertSame(QuotePricing::UNAVAILABLE, $res['status']);
        self::assertNull($res['rate'], 'Aucun taux ne doit être servi à la production sans source réelle.');
    }

    // ── Écriture : store() respecte l'environnement ─────────────────────────

    /**
     * `store()` doit écrire DANS l'environnement demandé.
     *
     * Cette méthode n'était couverte par aucun test : une mutation forçant
     * l'écriture en « production » a d'abord SURVÉCU. Écrire un taux dans le
     * mauvais monde est pourtant aussi grave que le lire du mauvais monde —
     * c'est même la façon la plus directe de faire coter un taux de test en
     * argent réel.
     */
    public function test_store_ecrit_dans_l_environnement_demande(): void
    {
        $rate = new FXRate(
            'EUR',
            'ZAR',
            '20.00000000',
            '0.0000',
            self::TEST_SOURCE,
            new DateTimeImmutable('now'),
            new DateTimeImmutable('+1 hour')
        );

        FXRateCache::store($rate, ExecutionEnvironment::SANDBOX);

        $stmt = $this->pdo->prepare(
            'SELECT environment FROM fx_rates_cache
              WHERE base_currency = ? AND quote_currency = ? AND source = ?'
        );
        $stmt->execute(['EUR', 'ZAR', self::TEST_SOURCE]);

        self::assertSame(
            ['sandbox'],
            $stmt->fetchAll(PDO::FETCH_COLUMN),
            'Un taux stocké en sandbox ne doit pas atterrir en production.'
        );
    }

    /**
     * Corollaire : un taux écrit en sandbox n'est PAS lisible en production.
     */
    public function test_un_taux_stocke_en_sandbox_reste_invisible_en_production(): void
    {
        $rate = new FXRate(
            'EUR',
            'ZAR',
            '20.00000000',
            '0.0000',
            self::TEST_SOURCE,
            new DateTimeImmutable('now'),
            new DateTimeImmutable('+1 hour')
        );

        FXRateCache::store($rate, ExecutionEnvironment::SANDBOX);

        self::assertNotNull(
            FXRateCache::lookup('EUR', 'ZAR', ExecutionEnvironment::SANDBOX),
            'La sandbox doit relire ce qu\'elle a écrit.'
        );
        self::assertNull(
            FXRateCache::lookup('EUR', 'ZAR', ExecutionEnvironment::PRODUCTION),
            'La production ne doit pas voir un taux écrit en sandbox.'
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function seed(
        string $base,
        string $quote,
        string $rate,
        string $environment,
        int $ttlSeconds = 3600
    ): void {
        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
             VALUES (?, ?, ?, 0, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([$base, $quote, $rate, self::TEST_SOURCE, $environment, $ttlSeconds]);

        QuotePricing::resetCache();
    }

    private function purge(): void
    {
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE source = ?')->execute([self::TEST_SOURCE]);
    }
}
