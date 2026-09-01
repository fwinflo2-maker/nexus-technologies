<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * SecretRedactor — mécanisme de redaction des secrets pour les logs et audits.
 *
 * Règle absolue (§15) : aucun secret (API key, secret, token, credential)
 * ne doit apparaître dans les logs, même en mode debug.
 *
 * Usage :
 *   error_log(SecretRedactor::redact($value));         // 'sk_...1234' → 'sk********34'
 *   SecretRedactor::mask($value);                      // → '********'
 *   SecretRedactor::redactKeys($array, ['api_key']);   // masque les clés sensibles d'un tableau
 */
final class SecretRedactor
{
    private function __construct()
    {
    }

    /** Parties de nom de clé considérées sensibles (comparaison insensible à la casse). */
    public const SENSITIVE_KEY_PARTS = [
        'secret', 'token', 'key', 'password', 'credential', 'private', 'signing',
    ];

    /** Masque partiellement : conserve les 2 premiers et 2 derniers caractères. */
    public static function redact(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (strlen($value) <= 8) {
            return '********';
        }
        return substr($value, 0, 2) . '********' . substr($value, -2);
    }

    /** Masque total : ne conserve aucune information. */
    public static function mask(?string $value): string
    {
        return $value === null || $value === '' ? '' : '********';
    }

    /** Un nom de clé correspond-il à un champ sensible ? */
    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($lower, $part)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Redacte les valeurs sensibles d'un tableau (par nom de clé).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function redactArray(array $input): array
    {
        foreach ($input as $key => $value) {
            // Descente RÉCURSIVE.
            //
            // La version précédente ne parcourait que le premier niveau : un
            // secret imbriqué (réponse de provider, corps de webhook,
            // metadata structurée) traversait la redaction intact. Les
            // charges utiles réelles étant presque toujours imbriquées, la
            // protection ne couvrait en pratique que le cas le plus simple.
            if (is_array($value)) {
                $input[$key] = self::redactArray($value);
                continue;
            }

            if (is_string($key) && self::isSensitiveKey($key) && is_string($value)) {
                $input[$key] = self::redact($value);
            }
        }
        return $input;
    }
}
