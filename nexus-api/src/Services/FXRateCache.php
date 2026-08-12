<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Models\FXRate;
use PDO;
use RuntimeException;

/**
 * Simple cache wrapper for the `fx_rates_cache` table.
 *
 * - `lookup($base, $quote)` returns an FXRate if a non‑expired row exists.
 * - `store(FXRate $rate)` inserts a new row (no upsert – callers decide).
 */
final class FXRateCache
{
    private function __construct() {}

    /** @return FXRate|null */
    public static function lookup(string $baseCurrency, string $quoteCurrency): ?FXRate
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at
            FROM fx_rates_cache
            WHERE base_currency = :base AND quote_currency = :quote
              AND expires_at > NOW()
            ORDER BY fetched_at DESC
            LIMIT 1'
        );
        $stmt->execute(['base' => strtoupper($baseCurrency), 'quote' => strtoupper($quoteCurrency)]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return new FXRate(
            $row['base_currency'],
            $row['quote_currency'],
            $row['rate'],
            $row['spread_pct'],
            $row['source'],
            new \DateTimeImmutable($row['fetched_at']),
            new \DateTimeImmutable($row['expires_at'])
        );
    }

    public static function store(FXRate $rate): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at)
            VALUES
                (:base, :quote, :rate, :spread, :source, :fetched, :expires)'
        );
        $stmt->execute([
            'base'    => $rate->getBaseCurrency(),
            'quote'   => $rate->getQuoteCurrency(),
            'rate'    => $rate->getRate(),
            'spread'  => $rate->getSpreadPct(),
            'source'  => $rate->getSource(),
            'fetched' => $rate->getFetchedAt()->format('Y-m-d H:i:s'),
            'expires' => $rate->getExpiresAt()->format('Y-m-d H:i:s'),
        ]);
    }
}
