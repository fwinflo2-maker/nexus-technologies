<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * Cache court des clés publiques HTTP pawaPay, scopé par environnement.
 *
 * Les callbacks RFC-9421 résolvent la clé par `keyid`. Sans cache, chaque
 * webhook rejouerait GET /v2/public-key/http. Le cache ne survit pas au
 * process (pas de secret disque) et expire pour forcer une rotation.
 */
final class PawaPayPublicKeyCache
{
    private const TTL_SECONDS = 300;

    /** @var array<string, array{pem: string, expires_at: int}> */
    private static array $keys = [];

    private function __construct()
    {
    }

    public static function get(string $environment, string $keyId): ?string
    {
        $row = self::$keys[self::index($environment, $keyId)] ?? null;
        if ($row === null || $row['expires_at'] < time() || $row['pem'] === '') {
            return null;
        }

        return $row['pem'];
    }

    public static function put(string $environment, string $keyId, string $pem): void
    {
        if ($pem === '' || $keyId === '') {
            return;
        }
        self::$keys[self::index($environment, $keyId)] = [
            'pem'        => $pem,
            'expires_at' => time() + self::TTL_SECONDS,
        ];
    }

    public static function clear(): void
    {
        self::$keys = [];
    }

    private static function index(string $environment, string $keyId): string
    {
        return strtolower($environment) . ':' . $keyId;
    }
}
