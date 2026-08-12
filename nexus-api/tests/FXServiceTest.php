<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Models\FXRate;
use Nexus\Services\FXRateCache;
use Nexus\Services\FXService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests du FXService (Phase D) – résolution de taux, cache, conversion.
 */
final class FXServiceTest extends TestCase
{
    private PDO $pdo;
    private array $createdRows = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Les tests FXService doivent s\'exécuter sur la base nexus_test.');
        }
        $this->createdRows = [];
    }

    protected function tearDown(): void
    {
        // Cleanup inserted rows.
        if (!empty($this->createdRows)) {
            $placeholders = implode(',', array_fill(0, count($this->createdRows), '?'));
            $stmt = $this->pdo->prepare("DELETE FROM fx_rates_cache WHERE id IN ($placeholders)");
            $stmt->execute($this->createdRows);
        }
    }

    private function insertCacheRow(
        string $base,
        string $quote,
        string $rate,
        string $source,
        int $ttlSeconds = 86400,
        string $spread = '0.0000'
    ): int {
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = $this->pdo->prepare(
            'INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at)'
            . ' VALUES (:base, :quote, :rate, :spread, :source, :fetched, :expires)'
        );
        $stmt->execute([
            'base'    => $base,
            'quote'   => $quote,
            'rate'    => $rate,
            'spread'  => $spread,
            'source'  => $source,
            'fetched' => $now,
            'expires' => $expires,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->createdRows[] = $id;
        return $id;
    }

    public function test_resolve_uses_fresh_cache_entry(): void
    {
        // Insert a fresh cache entry for EUR → USD.
        $this->insertCacheRow('EUR', 'USD', '2.50000000', 'manual');

        $rate = FXService::resolve('EUR', 'USD');
        $this->assertInstanceOf(FXRate::class, $rate);
        $this->assertSame('2.50000000', $rate->getRate());
        $this->assertSame('manual', $rate->getSource());
    }

    public function test_resolve_falls_back_to_manual_when_missing(): void
    {
        // Ensure no cache row exists for EUR → USD.
        $stmt = $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE base_currency = :b AND quote_currency = :q');
        $stmt->execute(['b' => 'EUR', 'q' => 'USD']);

        $rate = FXService::resolve('EUR', 'USD');
        $this->assertInstanceOf(FXRate::class, $rate);
        // Manual rate defined in ManualRateProvider.
        $this->assertSame('1.08700000', $rate->getRate());
        $this->assertSame('manual', $rate->getSource());
    }

    public function test_resolve_ignores_expired_cache_and_uses_manual(): void
    {
        // Insert an expired cache entry.
        $this->insertCacheRow('EUR', 'GBP', '0.80000000', 'manual', ttlSeconds: -3600);

        $rate = FXService::resolve('EUR', 'GBP');
        $this->assertInstanceOf(FXRate::class, $rate);
        // Expect manual fallback value.
        $this->assertSame('0.85500000', $rate->getRate());
        $this->assertSame('manual', $rate->getSource());
    }

    public function test_convert_applies_half_up_rounding_to_8_dp(): void
    {
        $fxRate = new FXRate(
            'EUR',
            'USD',
            '1.23456789', // rate
            '0.0000',
            'manual',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+24 hours')
        );
        $sourceAmount = '100.12345678';
        $dest = FXService::convert($sourceAmount, $fxRate);

        // Expected calculation using bc math and half‑up rounding.
        $raw = bcmul($sourceAmount, '1.23456789', 10);
        $expected = bcadd($raw, '0.000000005', 8);
        $this->assertSame($expected, $dest);
    }

    public function test_unknown_currency_pair_throws(): void
    {
        // S'assurer qu'aucune entrée cache n'existe pour USD → GBP
        // (ni dans le cache ni dans ManualRateProvider).
        $stmt = $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE base_currency = :b AND quote_currency = :q');
        $stmt->execute(['b' => 'USD', 'q' => 'GBP']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Manual FX rate not defined');

        FXService::resolve('USD', 'GBP');
    }

    public function test_spread_pct_n_altere_pas_le_taux(): void
    {
        // Entrée cache avec un spread non nul : le taux retourné reste le
        // taux brut, le spread est simplement conservé (stocké).
        $this->insertCacheRow('EUR', 'USD', '1.50000000', 'manual', spread: '0.5000');

        $rate = FXService::resolve('EUR', 'USD');
        $this->assertInstanceOf(FXRate::class, $rate);
        $this->assertSame('1.50000000', $rate->getRate());
        $this->assertSame('0.5000', $rate->getSpreadPct());

        // La conversion applique uniquement le taux, jamais le spread.
        $dest = FXService::convert('100.00', $rate);
        $this->assertSame('150.00000000', $dest);
    }
}
