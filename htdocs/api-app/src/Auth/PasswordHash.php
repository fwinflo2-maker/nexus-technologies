<?php

declare(strict_types=1);

namespace Nexus\Auth;

/**
 * Distingue un hash PHP utilisable (bcrypt / argon) d'une valeur vide
 * laissée sur les comptes Google à la création.
 */
final class PasswordHash
{
    public static function isUsable(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        $algo = password_get_info($hash)['algo'] ?? 0;

        return $algo !== 0 && $algo !== null;
    }
}
