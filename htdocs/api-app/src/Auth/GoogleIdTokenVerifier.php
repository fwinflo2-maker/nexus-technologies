<?php

declare(strict_types=1);

namespace Nexus\Auth;

use Nexus\Core\HttpException;

/**
 * Vérifie un ID Token Google (JWT RS256) localement via JWKS.
 *
 * `aud` doit matcher un des client IDs configurés (web / Android / iOS).
 * En tests, `$testOverride` court-circuite le réseau Google.
 */
final class GoogleIdTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const JWKS_TTL = 3600;
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** @var (callable(string): array<string,mixed>)|null */
    public static $testOverride = null;

    /** @var array{keys: list<array<string,mixed>>, fetched_at: int}|null */
    private static ?array $jwksCache = null;

    /** @var array{keys: list<array<string,mixed>>}|null */
    public static ?array $testJwks = null;

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function allowedClientIds(): array
    {
        $ids = [];
        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_ANDROID_CLIENT_ID', 'GOOGLE_IOS_CLIENT_ID'] as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                $ids[] = trim($value);
            }
        }
        if (defined('GOOGLE_CLIENT_ID') && is_string(GOOGLE_CLIENT_ID) && GOOGLE_CLIENT_ID !== '') {
            $ids[] = GOOGLE_CLIENT_ID;
        }

        return array_values(array_unique($ids));
    }

    public static function webClientId(): string
    {
        $env = getenv('GOOGLE_CLIENT_ID');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if (defined('GOOGLE_CLIENT_ID') && is_string(GOOGLE_CLIENT_ID)) {
            return GOOGLE_CLIENT_ID;
        }

        return '';
    }

    public static function iosClientId(): string
    {
        $env = getenv('GOOGLE_IOS_CLIENT_ID');

        return is_string($env) ? trim($env) : '';
    }

    /**
     * @return array{sub: string, email: string, email_verified: bool, name: string, picture: string, aud: string}
     */
    public static function verify(string $idToken): array
    {
        if (self::$testOverride !== null) {
            /** @var array<string,mixed> $claims */
            $claims = (self::$testOverride)($idToken);

            return self::normalizeClaims($claims);
        }

        $allowed = self::allowedClientIds();
        if ($allowed === []) {
            throw new HttpException(503, 'Connexion Google non configurée.', 'GOOGLE_AUTH_UNAVAILABLE');
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
        }

        [$rawHeader, $rawPayload, $rawSig] = $parts;
        $header = json_decode(self::base64UrlDecode($rawHeader), true);
        $payload = json_decode(self::base64UrlDecode($rawPayload), true);
        $signature = self::base64UrlDecode($rawSig);

        if (!is_array($header) || !is_array($payload) || $signature === '') {
            throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
        }

        $alg = (string) ($header['alg'] ?? '');
        $kid = (string) ($header['kid'] ?? '');
        if ($alg !== 'RS256' || $kid === '') {
            throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
        }

        $pem = self::pemForKid($kid);
        $signed = $rawHeader . '.' . $rawPayload;
        $ok = openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new HttpException(401, 'Token Google invalide ou expiré.', 'UNAUTHORIZED');
        }

        $iss = (string) ($payload['iss'] ?? '');
        $aud = (string) ($payload['aud'] ?? '');
        $exp = (int) ($payload['exp'] ?? 0);
        $sub = (string) ($payload['sub'] ?? '');
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if (!in_array($iss, self::ISSUERS, true)) {
            throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
        }
        if (!in_array($aud, $allowed, true)) {
            throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
        }
        if ($exp < time() - 30) {
            throw new HttpException(401, 'Token Google expiré.', 'UNAUTHORIZED');
        }
        if ($sub === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
        }

        $verified = $payload['email_verified'] ?? false;
        $emailVerified = $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';

        return self::normalizeClaims([
            'sub' => $sub,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => (string) ($payload['name'] ?? ''),
            'picture' => (string) ($payload['picture'] ?? ''),
            'aud' => $aud,
        ]);
    }

    public static function resetTestState(): void
    {
        self::$testOverride = null;
        self::$testJwks = null;
        self::$jwksCache = null;
    }

    /**
     * @param array<string,mixed> $claims
     * @return array{sub: string, email: string, email_verified: bool, name: string, picture: string, aud: string}
     */
    private static function normalizeClaims(array $claims): array
    {
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '' || $email === '') {
            throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
        }

        $verified = $claims['email_verified'] ?? false;

        return [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => $verified === true || $verified === 'true' || $verified === 1 || $verified === '1',
            'name' => (string) ($claims['name'] ?? ''),
            'picture' => (string) ($claims['picture'] ?? ''),
            'aud' => (string) ($claims['aud'] ?? ''),
        ];
    }

    private static function pemForKid(string $kid): string
    {
        $jwks = self::jwks();
        foreach ($jwks['keys'] as $key) {
            if (($key['kid'] ?? '') !== $kid) {
                continue;
            }
            $n = (string) ($key['n'] ?? '');
            $e = (string) ($key['e'] ?? '');
            if ($n === '' || $e === '') {
                break;
            }

            return self::rsaJwkToPem($n, $e);
        }

        throw new HttpException(401, 'Token Google invalide.', 'UNAUTHORIZED');
    }

    /** @return array{keys: list<array<string,mixed>>} */
    private static function jwks(): array
    {
        if (self::$testJwks !== null) {
            return self::$testJwks;
        }

        if (self::$jwksCache !== null && self::$jwksCache['fetched_at'] > time() - self::JWKS_TTL) {
            return ['keys' => self::$jwksCache['keys']];
        }

        $body = self::httpGet(self::JWKS_URL);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new HttpException(503, 'Vérification Google indisponible.', 'GOOGLE_AUTH_UNAVAILABLE');
        }

        /** @var list<array<string,mixed>> $keys */
        $keys = array_values(array_filter($decoded['keys'], 'is_array'));
        self::$jwksCache = ['keys' => $keys, 'fetched_at' => time()];

        return ['keys' => $keys];
    }

    private static function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new HttpException(503, 'Vérification Google indisponible.', 'GOOGLE_AUTH_UNAVAILABLE');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $body === '' || $status >= 400) {
                throw new HttpException(503, 'Vérification Google indisponible.', 'GOOGLE_AUTH_UNAVAILABLE');
            }

            return $body;
        }

        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body) || $body === '') {
            throw new HttpException(503, 'Vérification Google indisponible.', 'GOOGLE_AUTH_UNAVAILABLE');
        }

        return $body;
    }

    private static function rsaJwkToPem(string $n, string $e): string
    {
        $modulus = self::base64UrlDecode($n);
        $exponent = self::base64UrlDecode($e);
        $modulus = ltrim($modulus, "\x00");
        if ($modulus !== '' && ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        $modSeq = "\x02" . self::asn1Length(strlen($modulus)) . $modulus;
        $expSeq = "\x02" . self::asn1Length(strlen($exponent)) . $exponent;
        $rsaKey = $modSeq . $expSeq;
        $rsaSeq = "\x30" . self::asn1Length(strlen($rsaKey)) . $rsaKey;
        $bitString = "\x03" . self::asn1Length(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;
        $oid = pack('H*', '300d06092a864886f70d0101010500');
        $spki = "\x30" . self::asn1Length(strlen($oid . $bitString)) . $oid . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
