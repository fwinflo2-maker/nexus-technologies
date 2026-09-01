<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Identifiant de corrélation d'une requête HTTP.
 *
 * Relie request_id, transaction, opération provider, événement webhook,
 * écriture ledger et règlement — sans jamais porter de secret.
 */
final class Correlation
{
    private static ?string $requestId = null;

    private function __construct()
    {
    }

    public static function bindFromRequest(Request $request): string
    {
        return self::$requestId = $request->requestId();
    }

    public static function id(): string
    {
        if (self::$requestId === null || self::$requestId === '') {
            self::$requestId = bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }

    public static function reset(): void
    {
        self::$requestId = null;
    }
}
