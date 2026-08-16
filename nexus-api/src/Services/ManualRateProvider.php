<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;

use Nexus\Models\FXRate;
use RuntimeException;

/**
 * Provides a minimal, deterministic set of FX rates for the demo.
 * Used as a fallback when the cache does not contain a rate.
 */
final class ManualRateProvider
{
    private const MANUAL_RATES = [
        // base => [quote => [rate, spread, source]]
        'EUR' => [
            'USD' => ['rate' => '1.08700000', 'spread' => '0.0000', 'source' => 'manual'],
            'GBP' => ['rate' => '0.85500000', 'spread' => '0.0000', 'source' => 'manual'],
            'XAF' => ['rate' => '655.95700000', 'spread' => '0.0000', 'source' => 'manual'],
            'XOF' => ['rate' => '655.95700000', 'spread' => '0.0000', 'source' => 'manual'],
            'USDT'=> ['rate' => '1.08700000', 'spread' => '0.0000', 'source' => 'manual'],
            'USDC'=> ['rate' => '1.08700000', 'spread' => '0.0000', 'source' => 'manual'],
        ],
        'USD' => [
            'EUR' => ['rate' => '0.92000000', 'spread' => '0.0000', 'source' => 'manual'],
        ],
        'GBP' => [
            'EUR' => ['rate' => '1.17000000', 'spread' => '0.0000', 'source' => 'manual'],
        ],
        'XAF' => [
            'EUR' => ['rate' => '0.00152400', 'spread' => '0.0000', 'source' => 'manual'],
        ],
    ];

    private function __construct() {}

    /** @return FXRate */
    public static function getRate(string $baseCurrency, string $quoteCurrency): FXRate
    {
        $base = strtoupper($baseCurrency);
        $quote = strtoupper($quoteCurrency);
        if (!isset(self::MANUAL_RATES[$base][$quote])) {
            // Ni faute du client, ni panne : le corridor n'a simplement pas de
            // taux configuré. 422 avec un code explicite, pour que l'appelant
            // sache que la demande est recevable mais non servie.
            throw new HttpException(
                422,
                sprintf('Aucun taux de change configuré pour %s → %s.', $base, $quote),
                'FX_RATE_NOT_AVAILABLE'
            );
        }
        $def = self::MANUAL_RATES[$base][$quote];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // TTL 24 h for demo values.
        $expires = $now->modify('+24 hours');
        return new FXRate(
            $base,
            $quote,
            $def['rate'],
            $def['spread'],
            $def['source'],
            $now,
            $expires
        );
    }
}
