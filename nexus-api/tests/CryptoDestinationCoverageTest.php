<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\CapabilityEngine;
use Nexus\Services\IntentEngine;
use PDO;
use PHPUnit\Framework\TestCase;

/**
     * USDT / USDC / ETH / BTC sont des devises de réception globales (rail crypto),
 * pas des devises locales par pays.
 */
final class CryptoDestinationCoverageTest extends TestCase
{
    private PDO $pdo;

    /** @var list<int> */
    private array $fxIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Les tests CryptoDestinationCoverageTest doivent utiliser nexus_test uniquement.');
        }
        $this->fxIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->fxIds === []) {
            return;
        }
        $ph = implode(',', array_fill(0, count($this->fxIds), '?'));
        $this->pdo->prepare("DELETE FROM fx_rates_cache WHERE id IN ($ph)")->execute($this->fxIds);
    }

    public function test_is_crypto_destination(): void
    {
        self::assertTrue(IntentEngine::isCryptoDestination('USDT'));
        self::assertTrue(IntentEngine::isCryptoDestination('usdc'));
        self::assertTrue(IntentEngine::isCryptoDestination('ETH'));
        self::assertTrue(IntentEngine::isCryptoDestination('BTC'));
        self::assertFalse(IntentEngine::isCryptoDestination('XAF'));
        self::assertFalse(IntentEngine::isCryptoDestination('EUR'));
        self::assertFalse(IntentEngine::isCryptoDestination(''));
    }

    public function test_coverage_ajoute_usdt_usdc_eth_a_chaque_pays(): void
    {
        $coverage = IntentEngine::coverage(ExecutionEnvironment::SANDBOX);
        $congo = $this->country($coverage, 'CG');
        self::assertNotNull($congo, 'Le Congo doit figurer dans la couverture (pawaPay).');

        $codes = array_column($congo['currencies'], 'code');
        self::assertSame('XAF', $codes[0], 'La devise locale reste en premier.');
        self::assertContains('USDT', $codes);
        self::assertContains('USDC', $codes);
        self::assertContains('ETH', $codes);
        self::assertContains('BTC', $codes);

        $fiat = $this->currency($congo, 'XAF');
        $fiatMethods = array_column($fiat['methods'], 'type');
        self::assertContains('mobile_money', $fiatMethods, 'Le fiat conserve ses rails locaux.');
        self::assertNotContains('crypto', $fiatMethods, 'XAF au Congo n\'a pas de rail crypto local.');

        foreach (['USDT', 'USDC', 'ETH', 'BTC'] as $code) {
            $crypto = $this->currency($congo, $code);
            $types = array_column($crypto['methods'], 'type');
            self::assertSame(['crypto'], $types, "{$code} n'admet que le mode crypto.");
        }
    }

    public function test_coverage_inclut_le_taux_eth_quand_il_existe(): void
    {
        $this->insertFx('EUR', 'ETH', '0.00038000');
        $coverage = IntentEngine::coverage(ExecutionEnvironment::SANDBOX);
        self::assertArrayHasKey('ETH', $coverage['rates']);
        self::assertEqualsWithDelta(0.00038, $coverage['rates']['ETH'], 0.0000001);
    }

    public function test_capability_usdt_hors_us_ue_ng_n_est_pas_no_provider(): void
    {
        $intent = [
            'amount'          => 10.0,
            'sourceCurrency'  => 'EUR',
            'destCountry'     => 'CG',
            'destCurrency'    => 'USDT',
            'receivingMethod' => 'crypto',
        ];

        try {
            $eligible = CapabilityEngine::findEligible($intent, ExecutionEnvironment::SANDBOX);
            self::assertNotEmpty($eligible, 'Un provider crypto opérationnel peut coter USDT vers le Congo.');
        } catch (HttpException $e) {
            self::assertSame(
                'NO_AVAILABLE_PROVIDER',
                $e->errorCode(),
                'Le corridor crypto existe (Bridge/CashRamp) : refus = non opérationnel, pas « pays non couvert ».'
            );
            self::assertSame(409, $e->statusCode());
        }
    }

    public function test_capability_fiat_crypto_hors_couverture_reste_filtre_pays(): void
    {
        $this->expectException(HttpException::class);
        try {
            CapabilityEngine::findEligible([
                'amount'          => 10.0,
                'sourceCurrency'  => 'EUR',
                'destCountry'     => 'CG',
                'destCurrency'    => 'XAF',
                'receivingMethod' => 'crypto',
            ], ExecutionEnvironment::SANDBOX);
        } catch (HttpException $e) {
            self::assertSame('NO_PROVIDER', $e->errorCode());
            self::assertSame(400, $e->statusCode());
            throw $e;
        }
    }

    /**
     * @param array{countries: list<array>} $coverage
     * @return array{code: string, currencies: list<array>}|null
     */
    private function country(array $coverage, string $code): ?array
    {
        foreach ($coverage['countries'] as $country) {
            if ($country['code'] === $code) {
                return $country;
            }
        }
        return null;
    }

    /**
     * @param array{currencies: list<array>} $country
     * @return array{code: string, methods: list<array>}
     */
    private function currency(array $country, string $code): array
    {
        foreach ($country['currencies'] as $currency) {
            if ($currency['code'] === $code) {
                return $currency;
            }
        }
        self::fail("Devise {$code} absente du pays {$country['code']}.");
    }

    private function insertFx(string $base, string $quote, string $rate): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + 86400);
        $stmt = $this->pdo->prepare(
            'INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
             VALUES (:base, :quote, :rate, 0, :source, :env, :fetched, :expires)'
        );
        $stmt->execute([
            'base'    => $base,
            'quote'   => $quote,
            'rate'    => $rate,
            'source'  => 'manual',
            'env'     => 'sandbox',
            'fetched' => $now,
            'expires' => $expires,
        ]);
        $this->fxIds[] = (int) $this->pdo->lastInsertId();
    }
}
