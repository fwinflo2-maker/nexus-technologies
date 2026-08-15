<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\QuoteEngine;
use Nexus\Services\QuotePricing;
use Nexus\Services\QuoteRateUnavailable;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Verrou sur le taux, les frais, le spread et le montant reçu (boucle 15).
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `QuoteEngine` calculait le montant reçu avec :
 *
 *     $effectiveRate = FIXED_RATE_EUR_TO_XAF * (1 - $spreadPct);
 *
 * Trois défauts prouvés en HTTP pendant l'audit :
 *
 *  1. Le taux XAF s'appliquait à TOUTES les devises. 100 EUR annonçaient
 *     63 027 GHS là où le réel avoisine 1 435 GHS — facteur 44.
 *  2. Le taux ignorait `fx_rates_cache`. En injectant EUR→XAF = 100, Convert
 *     appliquait bien 100 alors que Send annonçait toujours ~650.
 *  3. Spread et frais étaient tirés par `mt_rand()` « pour simuler la
 *     concurrence » — sur des montants facturés à un client.
 *
 * Aucun test ne couvrait `QuoteEngine::quote()` avant cette boucle.
 *
 * ISOLATION (§16) : chaque test pose ses propres taux et les retire, sans
 * dépendre du contenu global de `fx_rates_cache`.
 */
final class QuotePricingTest extends TestCase
{
    private PDO $pdo;

    /** @var list<array{string,string}> */
    private array $seededPairs = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        $this->seededPairs = [];
        QuotePricing::resetCache();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->seededPairs as [$base, $quote]) {
                $this->pdo
                    ->prepare('DELETE FROM fx_rates_cache WHERE base_currency = ? AND quote_currency = ?')
                    ->execute([$base, $quote]);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[QuotePricingTest] ' . $e->getMessage() . PHP_EOL);
        }

        QuotePricing::resetCache();
    }

    // ── Résolution du taux ──────────────────────────────────────────────────

    public function test_le_taux_provient_du_cache_FX_et_porte_sa_source(): void
    {
        $this->seedRate('EUR', 'XAF', '600.00000000', '0.0000', 'audit_source');

        $res = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        self::assertSame(QuotePricing::RESOLVED, $res['status']);
        self::assertSame(600.0, $res['rate']);
        self::assertSame('audit_source', $res['source'], 'La provenance du taux doit être traçable.');
        self::assertNotNull($res['fetched_at']);
        self::assertNotNull($res['expires_at']);
    }

    /**
     * LE test de la boucle : le taux doit SUIVRE la source, pas une constante.
     */
    public function test_modifier_la_source_change_reellement_le_taux(): void
    {
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.0000', 'test_a');
        $a = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        $this->seedRate('EUR', 'XAF', '100.00000000', '0.0000', 'test_b');
        QuotePricing::resetCache();
        $b = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        self::assertSame(655.957, $a['rate']);
        self::assertSame(
            100.0,
            $b['rate'],
            'Un taux insensible à sa source est une constante déguisée.'
        );
    }

    public function test_une_paire_inconnue_est_UNAVAILABLE_sans_taux_invente(): void
    {
        // ZZZ n'existe ni en cache ni dans ManualRateProvider.
        $res = QuotePricing::resolveRate('EUR', 'ZZZ', ExecutionEnvironment::SANDBOX);

        self::assertSame(QuotePricing::UNAVAILABLE, $res['status']);
        self::assertNull($res['rate'], 'Aucun taux ne doit être inventé pour une paire inconnue.');
        self::assertNotNull($res['reason']);
    }

    public function test_un_taux_nul_ou_negatif_est_refuse(): void
    {
        $this->seedRate('EUR', 'XAF', '0.00000000', '0.0000', 'broken');

        $res = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        self::assertSame(QuotePricing::UNAVAILABLE, $res['status']);
        self::assertNull($res['rate']);
    }

    public function test_le_spread_vient_de_la_source_et_non_du_hasard(): void
    {
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.5000', 'with_spread');

        $res = QuotePricing::resolveRate('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        // 0.5000 % en base → 0.005 en ratio.
        self::assertEqualsWithDelta(0.005, $res['spread_pct'], 0.0000001);
    }

    // ── Le montant reçu ─────────────────────────────────────────────────────

    /**
     * Le bug le plus grave : le taux XAF appliqué à une destination en GHS.
     */
    public function test_le_montant_recu_utilise_le_taux_de_la_devise_demandee(): void
    {
        $this->seedRate('EUR', 'GHS', '14.80000000', '0.0000', 'test_ghs');

        $quote = QuoteEngine::quote(
            $this->intent(100.0, 'EUR', 'GHS'),
            $this->provider(),
            'seed-ghs'
        );

        // 100 EUR - 2.90 de frais = 97.10 ; × 14.80 = 1437.08 → 1437
        self::assertSame(14.8, $quote['rate']);
        self::assertSame(1437.0, (float) $quote['received']);
        // Et surtout : plus jamais la valeur issue du taux XAF (~63 000).
        self::assertLessThan(2000.0, (float) $quote['received']);
    }

    public function test_le_montant_recu_est_mathematiquement_justifiable(): void
    {
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.0000', 'test_math');

        foreach ([100.0, 1000.0, 5000.0] as $amount) {
            $quote = QuoteEngine::quote(
                $this->intent($amount, 'EUR', 'XAF'),
                $this->provider(),
                'seed-math'
            );

            $fees     = (float) $quote['fees'];
            $expected = round(($amount - $fees) * 655.957, 0);

            self::assertSame(
                $expected,
                (float) $quote['received'],
                sprintf('received doit valoir (%.2f - %.2f) × 655.957', $amount, $fees)
            );
        }
    }

    public function test_le_spread_de_la_source_reduit_le_montant_recu(): void
    {
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.0000', 'no_spread');
        $sans = QuoteEngine::quote($this->intent(100.0, 'EUR', 'XAF'), $this->provider(), 's1');

        $this->seedRate('EUR', 'XAF', '655.95700000', '1.0000', 'with_spread');
        QuotePricing::resetCache();
        $avec = QuoteEngine::quote($this->intent(100.0, 'EUR', 'XAF'), $this->provider(), 's1');

        self::assertGreaterThan(
            (float) $avec['received'],
            (float) $sans['received'],
            'Un spread de 1 % doit réduire le montant reçu.'
        );
    }

    /**
     * Sans taux réel, la quote est refusée — jamais estimée.
     */
    public function test_sans_taux_la_quote_est_refusee(): void
    {
        $this->expectException(QuoteRateUnavailable::class);

        QuoteEngine::quote(
            $this->intent(100.0, 'EUR', 'ZZZ'),
            $this->provider(),
            'seed-none'
        );
    }

    /**
     * Conversion des frais quand la devise source n'est PAS l'euro.
     *
     * Les frais du barème sont libellés en EUR, mais se déduisent d'un montant
     * exprimé en devise source : il faut donc les convertir. Tant que tous les
     * tests utilisaient EUR (où le facteur vaut 1), multiplier ou diviser
     * donnait le même résultat et l'erreur restait invisible — une mutation
     * inversant l'opération a effectivement survécu jusqu'à ce test.
     */
    public function test_les_frais_sont_convertis_dans_la_devise_source(): void
    {
        // 1 EUR = 655.957 XAF, et on envoie DEPUIS des XAF.
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.0000', 'test_xaf_src');
        $this->seedRate('XAF', 'EUR', '0.00152400', '0.0000', 'test_xaf_dst');

        $quote = QuoteEngine::quote(
            $this->intent(100000.0, 'XAF', 'EUR'),
            $this->provider(),
            'seed-conv'
        );

        // 2.90 EUR de frais valent 2.90 × 655.957 ≈ 1902.3 XAF.
        // Montant après frais : 100 000 - 1902.3 = 98 097.7 XAF
        // Converti en EUR au taux 0.001524 : ≈ 149.5 EUR
        $received = (float) $quote['received'];

        self::assertGreaterThan(140.0, $received);
        self::assertLessThan(160.0, $received);

        // Si les frais étaient DIVISÉS au lieu d'être multipliés, ils
        // vaudraient 2.90 / 655.957 ≈ 0.0044 XAF — négligeables — et le
        // montant reçu grimperait à ~152.4 EUR.
        self::assertLessThan(
            152.0,
            $received,
            'Les frais doivent être convertis EN devise source, pas divisés par le taux.'
        );
    }

    // ── Frais et déterminisme ───────────────────────────────────────────────

    /**
     * Les frais étaient tirés au sort dans une fourchette de ±10 %.
     */
    public function test_les_frais_ne_sont_plus_aleatoires(): void
    {
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.0000', 'test_fees');

        $a = QuoteEngine::quote($this->intent(100.0, 'EUR', 'XAF'), $this->provider(), 'graine-A');
        $b = QuoteEngine::quote($this->intent(100.0, 'EUR', 'XAF'), $this->provider(), 'graine-B');

        // Deux graines différentes doivent donner le MÊME frais : le barème
        // ne dépend que de la méthode de réception.
        self::assertSame($a['fees'], $b['fees']);
        self::assertSame(2.90, (float) $a['fees'], 'mobile_money : 2.90 EUR au barème.');
    }

    public function test_le_bareme_de_frais_suit_la_methode_de_reception(): void
    {
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.0000', 'test_method');

        $mobile = QuoteEngine::quote($this->intent(100.0, 'EUR', 'XAF', 'mobile_money'), $this->provider(), 'g');
        $bank   = QuoteEngine::quote($this->intent(100.0, 'EUR', 'XAF', 'bank'), $this->provider(), 'g');

        self::assertSame(2.90, (float) $mobile['fees']);
        self::assertSame(4.50, (float) $bank['fees']);
    }

    /**
     * La quote doit permettre de retrouver l'origine de chacun de ses chiffres.
     */
    public function test_la_quote_expose_la_provenance_de_ses_chiffres(): void
    {
        $this->seedRate('EUR', 'XAF', '655.95700000', '0.0000', 'traceable_src');

        $quote = QuoteEngine::quote($this->intent(100.0, 'EUR', 'XAF'), $this->provider(), 'g');

        self::assertSame('traceable_src', $quote['rate_source']);
        self::assertNotNull($quote['rate_fetched_at']);
        self::assertNotNull($quote['rate_expires_at']);
        self::assertSame('nexus_schedule', $quote['fee_source']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function seedRate(
        string $base,
        string $quote,
        string $rate,
        string $spread,
        string $source,
        string $environment = 'sandbox'
    ): void {
        $this->pdo
            ->prepare('DELETE FROM fx_rates_cache WHERE base_currency = ? AND quote_currency = ?')
            ->execute([$base, $quote]);

        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$base, $quote, $rate, $spread, $source, $environment]);

        $this->seededPairs[] = [$base, $quote];
        QuotePricing::resetCache();
    }

    /** @return array<string, mixed> */
    private function intent(
        float $amount,
        string $source,
        string $dest,
        string $method = 'mobile_money'
    ): array {
        return [
            'amount'          => $amount,
            'sourceCurrency'  => $source,
            'destCountry'     => 'CM',
            'destCurrency'    => $dest,
            'receivingMethod' => $method,
        ];
    }

    /** @return array<string, mixed> */
    private function provider(): array
    {
        return [
            'slug'               => 'testprov',
            'name'               => 'Test Provider',
            'category'           => 'mobile_money',
            'reliability'        => null,
            'reliability_status' => 'UNAVAILABLE',
            'reliability_obs'    => 0,
            'delay_seconds'      => null,
            'delay_status'       => 'UNAVAILABLE',
            'delay_obs'          => 0,
            'delay_p90_seconds'  => null,
            'method_type'        => 'mobile_money',
        ];
    }
}
