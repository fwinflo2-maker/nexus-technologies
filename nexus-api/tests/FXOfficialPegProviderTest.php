<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\FXProviderRegistry;
use Nexus\Services\FXService;
use Nexus\Services\OfficialPegFXProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cycle 5 — parité de droit EUR↔XAF (OfficialPegFXProvider).
 *
 * Provenance : 1 EUR = 655,957 XAF exactement, coopération monétaire
 * franco-africaine (BEAC/CEMAC), garantie Trésor français, documentée par
 * la Banque de France. La BCE ne publie PAS EUR/XAF. Ce n'est PAS un taux
 * de marché : c'est une constante de droit, la seule admise en dur.
 */
final class FXOfficialPegProviderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        self::assertSame('nexus_test', $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    private function purge(): void
    {
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE source = ?')
            ->execute([OfficialPegFXProvider::SOURCE]);
        // Paires de contrôle utilisées ci-dessous.
        $this->pdo->exec(
            "DELETE FROM fx_rates_cache WHERE (base_currency = 'EUR' AND quote_currency = 'XAF')
             OR (base_currency = 'XAF' AND quote_currency = 'EUR')
             OR (base_currency = 'EUR' AND quote_currency = 'USD' AND source = 'peg_test')"
        );
    }

    public function test_la_parite_est_la_constante_officielle(): void
    {
        self::assertSame('655.95700000', OfficialPegFXProvider::EUR_XAF_RATE);
        // Inverse dérivé : 1/655,957 HALF_UP à 8 décimales.
        $expected = bcadd(bcdiv('1', '655.957', 10), '0.000000005', 8);
        self::assertSame($expected, OfficialPegFXProvider::XAF_EUR_RATE);
    }

    public function test_eur_xaf_resolu_sans_cache_avec_attribution(): void
    {
        $rate = FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
        self::assertSame(OfficialPegFXProvider::EUR_XAF_RATE, $rate->getRate());
        self::assertSame(OfficialPegFXProvider::SOURCE, $rate->getSource());
        self::assertGreaterThan(
            new \DateTimeImmutable('now'),
            $rate->getExpiresAt(),
            'Le taux dérivé expire (cache jamais éternel).'
        );
    }

    public function test_xaf_eur_inverse_derive(): void
    {
        $rate = FXService::resolve('XAF', 'EUR', ExecutionEnvironment::SANDBOX);
        self::assertSame(OfficialPegFXProvider::XAF_EUR_RATE, $rate->getRate());
        self::assertSame(OfficialPegFXProvider::SOURCE, $rate->getSource());
    }

    public function test_la_derivation_ecrit_une_trace_cache_attribuee_et_scopee(): void
    {
        FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        $stmt = $this->pdo->prepare(
            'SELECT rate, environment, source, expires_at > NOW() AS fresh FROM fx_rates_cache
             WHERE base_currency = ? AND quote_currency = ? AND source = ?'
        );
        $stmt->execute(['EUR', 'XAF', OfficialPegFXProvider::SOURCE]);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows, 'Une seule ligne de trace par dérivation.');
        self::assertSame('655.95700000', $rows[0]['rate']);
        self::assertSame('sandbox', $rows[0]['environment'], 'Trace scopée environnement.');
        self::assertSame('1', (string) $rows[0]['fresh'], 'Trace expirante non expirée.');
    }

    public function test_les_resolutions_suivantes_relisent_le_cache_sans_reinserer(): void
    {
        FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
        FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
        FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);

        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM fx_rates_cache
             WHERE base_currency = 'EUR' AND quote_currency = 'XAF'
               AND source = '" . OfficialPegFXProvider::SOURCE . "'"
        )->fetchColumn();
        self::assertSame(1, (int) $count);
    }

    public function test_un_taux_cache_metier_reste_prioritaire_sur_la_parite(): void
    {
        // Un taux opéré (ex. spread métier) garde la priorité sur la dérivation.
        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 3600 SECOND))'
        )->execute(['EUR', 'XAF', '655.95700000', '0.5000', 'ops_spread_test', 'sandbox']);

        $rate = FXService::resolve('EUR', 'XAF', ExecutionEnvironment::SANDBOX);
        self::assertSame('ops_spread_test', $rate->getSource());
        self::assertSame('0.5000', $rate->getSpreadPct());

        $this->pdo->exec("DELETE FROM fx_rates_cache WHERE source = 'ops_spread_test'");
    }

    public function test_aucune_autre_paire_n_est_couverte(): void
    {
        $provider = new OfficialPegFXProvider();
        self::assertNull($provider->getPair('EUR', 'USD'));
        self::assertNull($provider->getPair('USD', 'XAF'));
        self::assertNull($provider->getRate('EUR', 'GBP'));
        self::assertNull(FXProviderRegistry::providerFor('EUR', 'USD'));

        // Fail-closed intact pour les paires de marché.
        $this->expectException(RuntimeException::class);
        FXService::resolve('EUR', 'USD', ExecutionEnvironment::SANDBOX);
    }

    public function test_sante_du_provider_expose_la_provenance(): void
    {
        $health = (new OfficialPegFXProvider())->health();
        self::assertTrue($health['configured']);
        self::assertSame('fixed_peg', $health['kind']);
        self::assertSame(['EUR/XAF', 'XAF/EUR'], $health['pairs']);
        self::assertStringContainsString('Banque de France', $health['provenance']);
        self::assertStringContainsString('655,957', $health['provenance']);
        self::assertSame('CONFIGURATION_READY', $health['ladder']);
    }

    public function test_derivation_production_scopee_production(): void
    {
        FXService::resolve('EUR', 'XAF', ExecutionEnvironment::PRODUCTION);
        $envs = $this->pdo->query(
            "SELECT environment FROM fx_rates_cache
             WHERE base_currency = 'EUR' AND quote_currency = 'XAF'
               AND source = '" . OfficialPegFXProvider::SOURCE . "'"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['production'], $envs);
    }
}
