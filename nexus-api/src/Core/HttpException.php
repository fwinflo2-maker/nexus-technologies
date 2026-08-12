<?php

declare(strict_types=1);

namespace Nexus\Core;

use RuntimeException;

/**
 * Exception métier transportant un statut HTTP.
 *
 * Levée par les handlers de routes, elle est interceptée par le front
 * controller et traduite en réponse JSON au format uniforme.
 */
final class HttpException extends RuntimeException
{
    private int $statusCode;
    private string $errorCode;

    public function __construct(
        int $statusCode,
        string $message,
        ?string $code = null
    ) {
        parent::__construct($message);

        $this->statusCode = $statusCode;
        $this->errorCode  = $code ?? (string) $statusCode;
    }

    /** Statut HTTP à renvoyer au client. */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** Code métier d'erreur (ex. VALIDATION_ERROR). */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
