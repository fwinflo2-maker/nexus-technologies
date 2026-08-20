<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Providers\PawaPaySignature;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la couche de signature HTTP RFC-9421 de l'adaptateur pawaPay :
 *  - round-trip complet signer → vérifier (callbacks signés) ;
 *  - tolérance : Signature-Input avec ou sans label ;
 *  - rejet : corps modifié, en-tête signé modifié, clé inconnue,
 *    expiration dépassée, digest absent/invalide.
 */
final class PawaPaySignatureTest extends TestCase
{
    private const KEY_ID = 'HARNESS_EC_P256_KEY:1';

    private string $privateKeyPem;
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $cfg = self::opensslConfig();
        $key = openssl_pkey_new($cfg + [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($key === false) {
            $this->markTestSkipped('Extension OpenSSL sans support EC P-256.');
        }
        $this->privateKeyPem = '';
        $this->publicKeyPem  = '';
        openssl_pkey_export($key, $this->privateKeyPem, null, $cfg);
        $details = openssl_pkey_get_details($key);
        $this->publicKeyPem = (string) ($details['key'] ?? '');
    }

    /** Options de config OpenSSL (XAMPP : pas de config par défaut). */
    private static function opensslConfig(): array
    {
        $candidates = [
            'C:/xampp/php/extras/openssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return ['config' => $path];
            }
        }
        return [];
    }

    /** Fabrique un callback signé complet (comme le ferait pawaPay). */
    private function signedCallback(array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $sigDate = PawaPaySignature::signatureDate();
        $digest  = PawaPaySignature::contentDigest($json);
        $components = ['@method', '@authority', '@path', 'signature-date', 'content-digest', 'content-type'];
        $created = time();
        $params = PawaPaySignature::signatureParams($components, 'ecdsa-p256-sha256', self::KEY_ID, $created, $created + 60);
        $base = PawaPaySignature::signatureBase([
            '@method'        => 'POST',
            '@authority'     => '127.0.0.1:8080',
            '@path'          => '/api/providers/webhook/pawapay',
            'signature-date' => $sigDate,
            'content-digest' => $digest,
            'content-type'   => 'application/json; charset=UTF-8',
        ], $params);
        $raw = PawaPaySignature::sign($base, $this->privateKeyPem, 'ecdsa-p256-sha256');

        return [
            'body'    => $json,
            'headers' => [
                'content-type'    => 'application/json; charset=UTF-8',
                'content-digest'  => $digest,
                'signature-date'  => $sigDate,
                'signature'       => PawaPaySignature::signatureHeader($raw),
                'signature-input' => PawaPaySignature::SIGNATURE_PARAM_NAME . '=' . $params,
                '@method'         => 'POST',
                '@authority'      => '127.0.0.1:8080',
                '@path'           => '/api/providers/webhook/pawapay',
            ],
        ];
    }

    public function test_callback_round_trip_verifies(): void
    {
        $cb = $this->signedCallback(['payoutId' => '11111111-2222-3333-4444-555555555555', 'status' => 'COMPLETED']);

        $this->assertTrue(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        ));
    }

    public function test_signature_input_without_label_is_tolerated(): void
    {
        $cb = $this->signedCallback(['status' => 'ACCEPTED']);
        // Certains émetteurs omettent le label `sig-pp=` dans Signature-Input.
        $cb['headers']['signature-input'] = substr($cb['headers']['signature-input'], strlen(PawaPaySignature::SIGNATURE_PARAM_NAME) + 1);

        $this->assertTrue(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        ));
    }

    public function test_tampered_body_is_rejected(): void
    {
        $cb = $this->signedCallback(['status' => 'COMPLETED']);
        $cb['body'] .= ' ';

        $this->assertFalse(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        ));
    }

    public function test_tampered_signed_header_is_rejected(): void
    {
        $cb = $this->signedCallback(['status' => 'COMPLETED']);
        $cb['headers']['signature-date'] .= '0';

        $this->assertFalse(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        ));
    }

    public function test_unknown_key_id_is_rejected(): void
    {
        $cb = $this->signedCallback(['status' => 'COMPLETED']);

        $this->assertFalse(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => null
        ));
    }

    public function test_wrong_public_key_is_rejected(): void
    {
        $cb = $this->signedCallback(['status' => 'COMPLETED']);

        // Une autre paire de clés, résolue pour le même keyid.
        $cfg = self::opensslConfig();
        $other = openssl_pkey_new($cfg + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($other);

        $this->assertFalse(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => (string) ($details['key'] ?? '')
        ));
    }

    public function test_expired_signature_is_rejected(): void
    {
        $cb = $this->signedCallback(['status' => 'COMPLETED']);
        // Remplace expires par une date passée dans la base signée → re-signer.
        $h = &$cb['headers'];
        $input = $h['signature-input'];
        $newInput = preg_replace('/expires=\d+/', 'expires=' . (time() - 10), $input);
        $this->assertIsString($newInput);

        $newBase = PawaPaySignature::signatureBase([
            '@method'        => 'POST',
            '@authority'     => '127.0.0.1:8080',
            '@path'          => '/api/providers/webhook/pawapay',
            'signature-date' => $h['signature-date'],
            'content-digest' => $h['content-digest'],
            'content-type'   => $h['content-type'],
        ], substr($newInput, strlen(PawaPaySignature::SIGNATURE_PARAM_NAME) + 1));
        $raw = PawaPaySignature::sign($newBase, $this->privateKeyPem, 'ecdsa-p256-sha256');
        $h['signature-input'] = $newInput;
        $h['signature']       = PawaPaySignature::signatureHeader($raw);

        $this->assertFalse(PawaPaySignature::verifyCallback(
            $cb['body'],
            $h,
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        ));
    }

    public function test_missing_signature_is_rejected(): void
    {
        $cb = $this->signedCallback(['status' => 'COMPLETED']);
        unset($cb['headers']['signature'], $cb['headers']['signature-input']);

        $this->assertFalse(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        ));
    }

    public function test_missing_content_digest_is_rejected(): void
    {
        $cb = $this->signedCallback(['status' => 'COMPLETED']);
        $cb['headers']['content-digest'] = 'sha-512=::::::';

        $this->assertFalse(PawaPaySignature::verifyCallback(
            $cb['body'],
            $cb['headers'],
            fn (string $keyId): ?string => $keyId === self::KEY_ID ? $this->publicKeyPem : null
        ));
    }

    public function test_content_digest_detects_body_change(): void
    {
        $body = '{"payoutId":"abc"}';
        $digest = PawaPaySignature::contentDigest($body);

        $this->assertTrue(PawaPaySignature::verifyContentDigest($body, $digest));
        $this->assertFalse(PawaPaySignature::verifyContentDigest($body . ' ', $digest));
        $this->assertFalse(PawaPaySignature::verifyContentDigest($body, 'sha-256=:' . base64_encode('bogus') . ':'));
    }
}
