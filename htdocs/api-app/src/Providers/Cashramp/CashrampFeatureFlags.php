<?php

declare(strict_types=1);

namespace Nexus\Providers\Cashramp;

/**
 * Feature flags serveur Cashramp (Milestone 3).
 *
 * Désactivés par défaut tant qu'une capacité n'est pas validée en sandbox.
 */
final class CashrampFeatureFlags
{
    private function __construct()
    {
    }

    public static function accountsEnabled(): bool
    {
        return self::enabled('CASHRAMP_ACCOUNTS_ENABLED');
    }

    public static function cryptoEnabled(): bool
    {
        return self::enabled('CASHRAMP_CRYPTO_ENABLED');
    }

    public static function transfersEnabled(): bool
    {
        return self::enabled('CASHRAMP_TRANSFERS_ENABLED');
    }

    public static function cardsEnabled(): bool
    {
        return self::enabled('CASHRAMP_CARDS_ENABLED');
    }

    private static function enabled(string $envKey): bool
    {
        $raw = strtolower(trim((string) (getenv($envKey) ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
