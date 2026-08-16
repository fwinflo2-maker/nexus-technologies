<?php

declare(strict_types=1);

namespace Nexus\Models;

use DateTimeImmutable;
use Nexus\Execution\ExecutionContext;

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

    /**
     * Contexte d'exécution — environnement déjà résolu et autorisé en amont.
     *
     * Transporté par la requête plutôt que recalculé : un transfert ne doit
     * jamais déduire son environnement de la configuration du serveur au
     * moment où il s'exécute.
     */
    private ?ExecutionContext $context;

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
        ?string $fxSource = null,
        ?ExecutionContext $context = null
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
        $this->context = $context;
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
    public function getContext(): ?ExecutionContext { return $this->context; }

    /** Environnement effectif, ou `null` si la requête n'en porte pas. */
    public function getEnvironment(): ?string { return $this->context?->environmentValue(); }
}
