<?php

declare(strict_types=1);

namespace Nexus\Providers\Cashramp;

use RuntimeException;

final class CashrampException extends RuntimeException
{
    /**
     * @param list<array<string,mixed>> $graphqlErrors
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly array $graphqlErrors = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
