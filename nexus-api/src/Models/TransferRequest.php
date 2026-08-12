<?php

declare(strict_types=1);

namespace Nexus\Models;

use DateTimeImmutable;

/**
 * Represents a request to transfer funds between two wallets, potentially across currencies.
 */
final class TransferRequest
{
    private int $userId;
    private int $sourceWalletId;
    private int $destWalletId;
    private string $sourceAmount; // decimal string, 8 dp
    private string $sourceCurrency;
    private string $destCurrency;
    private string $type;
    private ?string $idempotencyKey;
    private ?string $description;
    private ?array $metadata;
    private ?string $fxSource; // optional override source for FX (e.g., 'manual')

    public function __construct(
        int $userId,
        int $sourceWalletId,
        int $destWalletId,
        string $sourceAmount,
        string $sourceCurrency,
        string $destCurrency,
        string $type = 'send',
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $fxSource = null
    ) {
        $this->userId = $userId;
        $this->sourceWalletId = $sourceWalletId;
        $this->destWalletId = $destWalletId;
        $this->sourceAmount = $sourceAmount;
        $this->sourceCurrency = strtoupper($sourceCurrency);
        $this->destCurrency = strtoupper($destCurrency);
        $this->type = $type;
        $this->idempotencyKey = $idempotencyKey;
        $this->description = $description;
        $this->metadata = $metadata;
        $this->fxSource = $fxSource;
    }

    public function getUserId(): int { return $this->userId; }
    public function getSourceWalletId(): int { return $this->sourceWalletId; }
    public function getDestWalletId(): int { return $this->destWalletId; }
    public function getSourceAmount(): string { return $this->sourceAmount; }
    public function getSourceCurrency(): string { return $this->sourceCurrency; }
    public function getDestCurrency(): string { return $this->destCurrency; }
    public function getType(): string { return $this->type; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getDescription(): ?string { return $this->description; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function getFxSource(): ?string { return $this->fxSource; }
}
