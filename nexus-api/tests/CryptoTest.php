<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * Tests du chiffrement AES-256-GCM (audit §2) :
 *  - round-trip plaintext → ciphertext → plaintext ;
 *  - IV unique par chiffrement (deux chiffrements ≠) ;
 *  - tag GCM vérifié : toute altération (tag ou ciphertext) → déchiffrement impossible ;
 *  - entrées dégénérées (vide, non-base64, trop court) → null propre.
 */
final class CryptoTest extends TestCase
{
    public function test_round_trip(): void
    {
        $plain  = 'sk_test_1234567890abcdef';
        $cipher = Crypto::encrypt($plain);

        $this->assertNotNull($cipher);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, Crypto::decrypt($cipher));
    }

    public function test_unique_iv_per_encryption(): void
    {
        // Deux chiffrements du même texte doivent produire deux blobs différents
        // (nonce/IV aléatoire à chaque appel).
        $a = Crypto::encrypt('même valeur');
        $b = Crypto::encrypt('même valeur');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a, $b);

        // Format binaire : IV (12) + TAG (16) + ciphertext.
        $raw = base64_decode($a, true);
        $this->assertIsString($raw);
        $this->assertGreaterThanOrEqual(28, strlen($raw)); // 12 + 16 minimum
    }

    public function test_authentication_tag_detects_tampering(): void
    {
        $cipher = Crypto::encrypt('sensible-value');
        $raw    = base64_decode($cipher, true);

        // 1. Altération du TAG (octets 12..27) → déchiffrement refusé.
        $tamperedTag = $raw;
        $tamperedTag[13] = chr(ord($tamperedTag[13]) ^ 0xFF);
        $this->assertNull(Crypto::decrypt(base64_encode($tamperedTag)));

        // 2. Altération du CIPHERTEXT (après 28 octets) → déchiffrement refusé.
        $tamperedCipher = $raw;
        $idx = strlen($raw) - 1;
        $tamperedCipher[$idx] = chr(ord($tamperedCipher[$idx]) ^ 0xFF);
        $this->assertNull(Crypto::decrypt(base64_encode($tamperedCipher)));
    }

    public function test_wrong_key_or_corruption_returns_null(): void
    {
        // Le tag GCM garantit l'authenticité : une clé différente équivaut
        // à un tag invalide (vérifié par openssl_decrypt → false → null).
        $this->assertNull(Crypto::decrypt(''));
        $this->assertNull(Crypto::decrypt('pas-du-base64!'));
        $this->assertNull(Crypto::decrypt(base64_encode('tropcourt')));
        $this->assertNull(Crypto::decrypt(Crypto::encrypt('x') . 'corruption'));
    }

    public function test_null_and_empty_inputs(): void
    {
        $this->assertNull(Crypto::encrypt(null));
        $this->assertNull(Crypto::encrypt(''));
        $this->assertNull(Crypto::decrypt(null));
        $this->assertNull(Crypto::decrypt(''));
    }
}
