<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\QuotePricing;
use Nexus\Services\ReferenceConverter;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unification du référentiel FX (boucle 17).
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `Currency::RATE_TO_EUR` et `RATE_TO_XAF` sont des tables de taux écrites en
 * dur, documentées dans le code comme « taux de démo ». Elles alimentaient
 * pourtant des chemins financiers réels :
 *
 *   - `ExecutionEngine` : `amount_ref` / `amount_xaf` PERSISTÉS au ledger, et
 *     conversion des frais réellement débités au client ;
 *   - `QuoteService` / `QuoteController` : montant comparé aux PLAFONDS KYC.
 *
 * Preuve relevée pendant l'audit : en injectant `1 EUR = 5 USD` dans le cache
 * FX, `FXService` renvoyait bien 5,00 tandis que `Currency::rateToRef('USD')`
 * restait à 0,92 — un écart de 4,6× sur un montant porté au ledger.
 *
 * Preuve HTTP côté policy : le taux réel passant de 1,10 à 5,00 (×4,5), le
 * PolicyEngine rendait un verdict IDENTIQUE. Un contrôle de sécurité
 * insensible au taux qu'il applique ne protège rien.
 *
 * NOTE SUR UNE FAUSSE PISTE
 * ─────────────────────────
 * La boucle 16 signalait une « divergence » entre `Currency` (USD → 0.92) et
 * `QuoteEngine::rateToEur` (USD → 1.0870). Vérification faite, ce sont deux
 * conventions INVERSES numériquement équivalentes (1 / 1.0870 = 0.9200), soit
 * 0,004 % d'écart. Le défaut réel n'était pas la divergence entre constantes,
 * mais le fait qu'aucune ne consulte FXService.
 *
 * ISOLATION (§16) : chaque test pose ses propres taux sous une source
 * reconnaissable et les retire.
 */
final class FXUnificationTest extends TestCase
{
    private const TEST_SOURCE = 'unif17';

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
            fwrite(STDERR, '[FXUnificationTest] ' . $e->getMessage() . PHP_EOL);
        }

        QuotePricing::resetCache();
    }

    // ── La conversion de référence suit le FX réel ──────────────────────────

    /**
     * LE test de la boucle : changer le taux dans FXService doit changer le
     * montant de référence.
     */
    public function test_le_montant_de_reference_suit_le_taux_reel(): void
    {
        // 1 USD = 0.25 EUR (donc 1 EUR = 4 USD), très loin de la constante 0.92.
        $this->seed('USD', 'EUR', '0.25000000', 'sandbox');

        $converted = ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::SANDBOX);

        self::assertSame(250.0, $converted);
        self::assertNotSame(
            920.0,
            $converted,
            'Le montant de référence ne doit plus provenir de la constante Currency (0.92).'
        );
    }

    public function test_le_taux_inverse_est_deduit_sans_etre_invente(): void
    {
        // ZAR n'est couvert ni par ManualRateProvider ni par les constantes
        // Currency : le seul chemin possible est la déduction inverse. Une
        // première version de ce test utilisait USD, que ManualRateProvider
        // résout DIRECTEMENT (0.92) — la branche inverse n'était alors jamais
        // atteinte et le test ne prouvait rien.
        $this->seed('EUR', 'ZAR', '20.00000000', 'sandbox');

        $rate = ReferenceConverter::toEur('ZAR', ExecutionEnvironment::SANDBOX);

        self::assertSame(ReferenceConverter::RESOLVED, $rate['status']);
        self::assertEqualsWithDelta(0.05, $rate['rate'], 0.0000001);
    }

    public function test_une_devise_identique_a_la_reference_vaut_un(): void
    {
        $rate = ReferenceConverter::toEur('EUR', ExecutionEnvironment::SANDBOX);

        self::assertSame(ReferenceConverter::RESOLVED, $rate['status']);
        self::assertSame(1.0, $rate['rate']);
        self::assertSame('identity', $rate['source']);
    }

    // ── Sandbox : repli autorisé, mais signalé ──────────────────────────────

    /**
     * En pratique, le repli sur les constantes `Currency` est INATTEIGNABLE en
     * sandbox : `ManualRateProvider` couvre déjà toutes les devises que
     * `Currency` connaît (EUR, USD, GBP, XAF, USDT, USDC). Vérifié par
     * comparaison des deux jeux de devises.
     *
     * Le repli reste néanmoins codé, comme filet pour une devise qui serait
     * ajoutée à `Currency` sans l'être au provider. Ce test fige le fait qu'il
     * ne masque rien aujourd'hui : toute conversion sandbox est RÉSOLUE par le
     * FX, jamais rabattue silencieusement sur une constante.
     */
    public function test_la_sandbox_resout_par_le_FX_et_non_par_les_constantes(): void
    {
        foreach (['USD', 'GBP', 'XAF', 'USDT', 'USDC'] as $currency) {
            $rate = ReferenceConverter::toEur($currency, ExecutionEnvironment::SANDBOX);

            self::assertSame(
                ReferenceConverter::RESOLVED,
                $rate['status'],
                sprintf('%s doit être résolu par le FX, pas par une constante.', $currency)
            );
            self::assertNotSame('currency_constants', $rate['source']);
        }
    }

    /**
     * Le repli existe et reste explicite lorsqu'il est réellement exercé :
     * une devise inconnue du FX ET de Currency n'obtient aucun taux inventé.
     */
    public function test_une_devise_inconnue_partout_n_obtient_aucun_taux(): void
    {
        $rate = ReferenceConverter::toEur('ZZZ', ExecutionEnvironment::SANDBOX);

        self::assertSame(ReferenceConverter::UNAVAILABLE, $rate['status']);
        self::assertNull($rate['rate'], 'Aucun taux ne doit être inventé pour une devise inconnue.');
    }

    // ── Production : fail-closed ────────────────────────────────────────────

    /**
     * Une constante de démonstration ne peut ni être portée au ledger ni
     * servir de base à un contrôle de plafond.
     */
    public function test_la_production_sans_taux_refuse_de_convertir(): void
    {
        $rate = ReferenceConverter::toEur('USD', ExecutionEnvironment::PRODUCTION);

        self::assertSame(ReferenceConverter::UNAVAILABLE, $rate['status']);
        self::assertNull($rate['rate']);
        self::assertNull(
            ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::PRODUCTION),
            'Aucun montant de référence ne doit être calculé sans taux réel en production.'
        );
    }

    public function test_la_production_convertit_avec_un_taux_reel(): void
    {
        $this->seed('USD', 'EUR', '0.25000000', 'production');

        $converted = ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::PRODUCTION);

        self::assertSame(250.0, $converted);
    }

    // ── Isolation ───────────────────────────────────────────────────────────

    public function test_un_taux_sandbox_ne_sert_pas_de_reference_en_production(): void
    {
        $this->seed('USD', 'EUR', '0.25000000', 'sandbox');

        self::assertSame(250.0, ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::SANDBOX));
        self::assertNull(
            ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::PRODUCTION),
            'Le taux sandbox ne doit pas franchir la frontière de production.'
        );
    }

    public function test_les_deux_environnements_portent_des_references_distinctes(): void
    {
        $this->seed('USD', 'EUR', '0.25000000', 'sandbox');
        $this->seed('USD', 'EUR', '0.90000000', 'production');

        self::assertSame(250.0, ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::SANDBOX));
        self::assertSame(900.0, ReferenceConverter::amountToEur(1000.0, 'USD', ExecutionEnvironment::PRODUCTION));
    }

    // ── Cohérence entre consommateurs ───────────────────────────────────────

    /**
     * Une même paire consommée par plusieurs services doit donner le même
     * taux : c'est précisément ce que cinq tables de constantes empêchaient.
     */
    public function test_une_meme_paire_donne_le_meme_taux_a_tous_les_consommateurs(): void
    {
        $this->seed('EUR', 'XAF', '600.00000000', 'sandbox');

        $pricing   = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
        $reference = ReferenceConverter::toXaf('EUR', ExecutionEnvironment::SANDBOX);

        self::assertSame(600.0, $pricing['rate'], 'Le pricing (Send) doit voir 600.');
        self::assertSame(600.0, $reference['rate'], 'La conversion de référence doit voir le même 600.');
        self::assertSame($pricing['rate'], $reference['rate']);
    }

    /**
     * Le référentiel XAF suit lui aussi le FX réel : la constante
     * `RATE_TO_XAF['USD'] = 603.0` était de surcroît incohérente avec
     * `RATE_TO_EUR['USD'] × 655.957 = 603.48`.
     */
    public function test_la_reference_XAF_suit_aussi_le_taux_reel(): void
    {
        $this->seed('USD', 'XAF', '700.00000000', 'sandbox');

        $converted = ReferenceConverter::amountToXaf(10.0, 'USD', ExecutionEnvironment::SANDBOX);

        self::assertSame(7000.0, $converted);
        self::assertNotSame(6030.0, $converted, 'La constante 603.0 ne doit plus être utilisée.');
    }

    /**
     * Documente le fait que les constantes restent cohérentes entre elles à
     * 0,1 % près — ce n'était pas le défaut. Le défaut était leur absence de
     * lien avec FXService.
     */
    public function test_les_constantes_currency_restent_coherentes_entre_elles(): void
    {
        $viaEur = Currency::rateToRef('USD') * Currency::rateToXaf('EUR');
        $viaXaf = Currency::rateToXaf('USD');

        self::assertEqualsWithDelta(
            $viaEur,
            $viaXaf,
            1.0,
            'Les deux tables de constantes divergent de plus d\'une unité XAF.'
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function seed(string $base, string $quote, string $rate, string $environment): void
    {
        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
             VALUES (?, ?, ?, 0, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$base, $quote, $rate, self::TEST_SOURCE, $environment]);

        QuotePricing::resetCache();
    }

    private function purge(): void
    {
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE source = ?')->execute([self::TEST_SOURCE]);
    }
}
