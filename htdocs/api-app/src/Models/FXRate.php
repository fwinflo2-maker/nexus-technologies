<?php

declare(strict_types=1);

namespace Nexus\Models;

use DateTimeImmutable;

/**
 * Représente une ligne du cache `fx_rates_cache`.
 *
 * Toutes les valeurs sont stockées sous forme de chaînes afin de conserver la
 * précision décimale (DECIMAL(20,8)).
 */
final class FXRate
{
    private string $baseCurrency;
    private string $quoteCurrency;
    private string $rate; // DECIMAL(20,8) sous forme de chaîne
    private string $spreadPct; // DECIMAL(8,4) sous forme de chaîne
    private string $source; // ex: 'manual', 'ecb', 'fixer', ...
    private DateTimeImmutable $fetchedAt;
    private DateTimeImmutable $expiresAt;

    public function __construct(
        string $baseCurrency,
        string $quoteCurrency,
        string $rate,
        string $spreadPct,
        string $source,
        DateTimeImmutable $fetchedAt,
        DateTimeImmutable $expiresAt
    ) {
        $this->baseCurrency = strtoupper($baseCurrency);
        $this->quoteCurrency = strtoupper($quoteCurrency);
        $this->rate = $rate;
        $this->spreadPct = $spreadPct;
        $this->source = $source;
        $this->fetchedAt = $fetchedAt;
        $this->expiresAt = $expiresAt;
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function getQuoteCurrency(): string
    {
        return $this->quoteCurrency;
    }

    /** @return string DECIMAL(20,8) */
    public function getRate(): string
    {
        return $this->rate;
    }

    /** @return string DECIMAL(8,4) */
    public function getSpreadPct(): string
    {
        return $this->spreadPct;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getFetchedAt(): DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
