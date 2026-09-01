<?php

declare(strict_types=1);

namespace Nexus\Auth;

use RuntimeException;

/**
 * Erreur de validation d'un JWT (malformé, signature invalide, expiré...).
 * Interceptée par Jwt::verify() qui retourne null dans ce cas.
 */
final class JwtException extends RuntimeException
{
}
