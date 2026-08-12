<?php

declare(strict_types=1);

namespace Nexus\Core;

/**
 * Chiffrement/déchiffrement AES-256-GCM des données sensibles.
 *
 * Format du ciphertext binaire : IV (12 octets) · TAG (16 octets) · CIPHERTEXT.
 * Encodé en base64 pour stockage MySQL.
 *
 * La clé dérivée de APP_KEY (SHA-256) est recalculée à chaque appel.
 * En production, APP_KEY doit mesurer ≥ 32 caractères.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private function __construct() {}

    /**
     * Dériver la clé de chiffrement depuis APP_KEY.
     * SHA-256 produit toujours 32 octets, requis pour AES-256.
     */
    private static function key(): string
    {
        return hash('sha256', APP_KEY, true);
    }

    /**
     * Chiffre une valeur en clair. Retourne null si la valeur est null ou vide.
     */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($cipher === false) {
            throw new \RuntimeException('Chiffrement AES-256-GCM impossible.');
        }

        // Format : IV (12) + TAG (16) + CIPHERTEXT
        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * Déchiffre une valeur encodée en base64. Retourne null si l'entrée
     * est null, vide, ou si le déchiffrement échoue (clé incorrecte / corrompue).
     */
    public static function decrypt(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $raw = base64_decode($encoded, true);
        $minLen = self::IV_LEN + self::TAG_LEN; // 28 octets minimum

        if ($raw === false || strlen($raw) < $minLen) {
            return null;
        }

        $iv     = substr($raw, 0, self::IV_LEN);
        $tag    = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plain === false ? null : $plain;
    }
}
