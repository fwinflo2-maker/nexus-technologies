<?php

declare(strict_types=1);

namespace Nexus\Auth;

/**
 * Générateur et validateur de JSON Web Tokens — sans dépendance externe.
 *
 * - Signature : HMAC-SHA256 (HS256)
 * - Encodage : base64url
 * - Header   : { "alg": "HS256", "typ": "JWT" }
 * - Payload  : { sub: user_id, iat, exp, jti }
 * - Expiration : 24 h par défaut (constante JWT_TTL)
 *
 * Le secret de signature est défini dans config/constants.php (JWT_SECRET).
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';

    /** Construit et signe un JWT. Les claims iat/exp/jti sont ajoutés. */
    public static function encode(array $payload, ?int $ttl = null): string
    {
        $now = time();

        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
        ];

        $payload = array_merge([
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + ($ttl ?? JWT_TTL),
        ], $payload);

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $segments[] = self::signature(implode('.', $segments));

        return implode('.', $segments);
    }

    /** Décode et valide un JWT. Lève JwtException en cas de problème. */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new JwtException('JWT malformé');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header  = json_decode(self::base64UrlDecode($headerB64), true);
        $payload = json_decode(self::base64UrlDecode($payloadB64), true);

        if (!is_array($header) || !is_array($payload)) {
            throw new JwtException('Payload JWT invalide');
        }

        // Anti-algorithm confusion : seul HS256 est accepté.
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new JwtException('Algorithme JWT non autorisé');
        }

        // Vérification de la signature (comparaison en temps constant).
        if (!hash_equals(self::signature($headerB64 . '.' . $payloadB64), $signatureB64)) {
            throw new JwtException('Signature JWT invalide');
        }

        // Claims obligatoires.
        foreach (['sub', 'iat', 'exp'] as $claim) {
            if (!array_key_exists($claim, $payload)) {
                throw new JwtException('Claims JWT manquants');
            }
        }

        // Expiration (tolérance d'horloge de 30 s).
        if ((int) $payload['exp'] <= time() - 30) {
            throw new JwtException('JWT expiré');
        }

        return $payload;
    }

    /** Valide un JWT et retourne son payload, ou null si invalide/expiré. */
    public static function verify(string $token): ?array
    {
        try {
            return self::decode($token);
        } catch (JwtException) {
            return null;
        }
    }

    /** Signature HMAC-SHA256 en base64url. */
    private static function signature(string $input): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $input, JWT_SECRET, true));
    }

    /** Encode binaire → base64url (RFC 7515). */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Décode base64url → binaire (strict, lève JwtException si invalide). */
    private static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new JwtException('Encodage base64url invalide');
        }

        return $decoded;
    }
}
