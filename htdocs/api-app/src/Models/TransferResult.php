<?php

declare(strict_types=1);

namespace Nexus\Models;

/**
 * Résultat d'un transfert multi-devises réussi (Phase D).
 *
 * Toutes les valeurs monétaires sont des chaînes décimales (8 décimales)
 * afin de conserver la précision BCMath.
 *
 * Le DTO est volontairement sérialisable par `json_encode()` :
 *   - il est stocké dans `idempotency_keys.response_json` (IdempotencyService) ;
 *   - il est reconstruit à l'identique par `fromArray()` lors d'un replay
 *     idempotent (même `operation_id`, mêmes montants, même taux).
 */
final class TransferResult
{
    private string $operationId; // UUID liant wallet_operations + ledger_entries
    private string $sourceAmount; // chaîne décimale (8 dp)
    private string $destAmount;   // chaîne décimale (8 dp)
    private string $fxRate;       // chaîne décimale (8 dp)
    private string $fxSource;     // ex. 'manual', 'identity'
    private string $status;       // ex. 'completed'
    private ?string $description;
    private ?array $metadata;

    public function __construct(
        string $operationId,
        string $sourceAmount,
        string $destAmount,
        string $fxRate,
        string $fxSource,
        ?string $description = null,
        ?array $metadata = null,
        string $status = 'completed'
    ) {
        $this->operationId  = $operationId;
        $this->sourceAmount = $sourceAmount;
        $this->destAmount   = $destAmount;
        $this->fxRate       = $fxRate;
        $this->fxSource     = $fxSource;
        $this->description  = $description;
        $this->metadata     = $metadata;
        $this->status       = $status;
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getSourceAmount(): string
    {
        return $this->sourceAmount;
    }

    public function getDestAmount(): string
    {
        return $this->destAmount;
    }

    public function getFxRate(): string
    {
        return $this->fxRate;
    }

    public function getFxSource(): string
    {
        return $this->fxSource;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Représentation sérialisable du résultat (utilisée par
     * `IdempotencyService::complete()` → response_json).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'operation_id'  => $this->operationId,
            'source_amount' => $this->sourceAmount,
            'dest_amount'   => $this->destAmount,
            'fx_rate'       => $this->fxRate,
            'fx_source'     => $this->fxSource,
            'status'        => $this->status,
            'description'   => $this->description,
            'metadata'      => $this->metadata,
        ];
    }

    /**
     * Reconstruit un TransferResult depuis un tableau (replay idempotent).
     *
     * @param array<string,mixed> $data Tableau produit par `toArray()` (ou décodé de response_json).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['operation_id'] ?? ''),
            (string) ($data['source_amount'] ?? '0'),
            (string) ($data['dest_amount'] ?? '0'),
            (string) ($data['fx_rate'] ?? '1.00000000'),
            (string) ($data['fx_source'] ?? 'identity'),
            isset($data['description']) ? (string) $data['description'] : null,
            isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
            (string) ($data['status'] ?? 'completed')
        );
    }
}
