<?php

declare(strict_types=1);

namespace Nexus\Providers;

use RuntimeException;

/**
 * PawaPaySignature — signatures de messages HTTP (RFC-9421) pour l'API pawaPay.
 *
 * pawaPay documente une seconde couche de sécurité au-dessus du bearer token :
 *   - les REQUÊTES financières (payout, deposit, refund) peuvent être signées
 *     avec votre clé privée (alg : ecdsa-p256-sha256 | rsa-v1_5-sha256 |
 *     rsa-pss-sha512 | ecdsa-p384-sha384) ;
 *   - les CALLBACKS peuvent être signés par pawaPay (vérification avec la clé
 *     publique du provider, résolue via l'endpoint Public Keys).
 *
 * Implémentation fidèle au guide officiel (docs.pawapay.io/using_the_api) :
 *   - Content-Digest : `sha-512=:<base64>:`
 *   - Base de signature : composants dérivés (@method, @authority, @path) +
 *     en-têtes listés dans Signature-Input (signature-date, content-digest,
 *     content-type) + ligne `@signature-params`.
 *   - ECDSA : la signature RFC-9421 est la concaténation brute R||S (64 octets
 *     pour P-256), pas la forme DER d'OpenSSL — conversion incluse ici.
 *
 * Aucun secret n'est journalisé : les clés privées ne quittent jamais le
 * process serveur.
 */
final class PawaPaySignature
{
    /** Format Signature / Signature-Input attendu : `sig-pp=<value>` (ou `<key>=<value>`). */
    public const SIGNATURE_PARAM_NAME = 'sig-pp';

    private function __construct()
    {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Construction (requêtes sortantes)
    // ──────────────────────────────────────────────────────────────────────

    /** Content-Digest d'un corps JSON : `sha-512=:<base64>:`. */
    public static function contentDigest(string $body, string $algo = 'sha-512'): string
    {
        $algo = strtolower($algo);
        if (!in_array($algo, ['sha-256', 'sha-512'], true)) {
            throw new RuntimeException('Algorithme de digest pawaPay non supporté : ' . $algo);
        }
        return $algo . '=:' . base64_encode(hash($algo === 'sha-256' ? 'sha256' : 'sha512', $body, true)) . ':';
    }

    /** Signature-Date : ISO 8601 avec microsecondes + fuseau UTC. */
    public static function signatureDate(): string
    {
        $now = \DateTimeImmutable::createFromFormat('U.u', number_format(microtime(true), 6, '.', ''));
        if ($now === false) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Ligne `@signature-params` : composants + algorithme + keyid + dates.
     *
     * Ex. : ("@method" "@authority" "@path" "signature-date" "content-digest"
     *       "content-type");alg="ecdsa-p256-sha256";keyid="CUSTOMER_TEST_KEY";
     *       created=1714653405;expires=1714653465
     *
     * @param list<string> $components
     */
    public static function signatureParams(
        array $components,
        string $alg,
        string $keyId,
        int $created,
        int $expires
    ): string {
        $list = implode(' ', array_map(static fn (string $c): string => '"' . $c . '"', $components));

        return '(' . $list . ');alg="' . $alg . '";keyid="' . $keyId
            . '";created=' . $created . ';expires=' . $expires;
    }

    /**
     * Base de signature : une ligne par composant (valeurs encodées JSON),
     * terminée par la ligne @signature-params.
     *
     * @param array<string,string> $components valeurs des composants (clés en minuscules)
     */
    public static function signatureBase(array $components, string $params): string
    {
        $lines = [];
        foreach ($components as $name => $value) {
            $lines[] = '"' . $name . '": ' . self::jsonQuote($value);
        }
        $lines[] = '"@signature-params": ' . $params;

        return implode("\n", $lines);
    }

    /**
     * Signe une base de signature avec une clé privée PEM.
     *
     * @param string $alg ecdsa-p256-sha256 | rsa-v1_5-sha256
     * @return string Signature binaire brute (R||S pour ECDSA, octets RSA)
     */
    public static function sign(string $base, string $privateKeyPem, string $alg): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Clé privée pawaPay illisible (format PEM attendu).');
        }

        return match ($alg) {
            'ecdsa-p256-sha256' => self::signEcdsa($key, $base),
            'rsa-v1_5-sha256'   => self::signRsaV15($key, $base),
            default             => throw new RuntimeException(
                'Algorithme de signature pawaPay non supporté par cet adaptateur : ' . $alg
                . ' (supporté : ecdsa-p256-sha256, rsa-v1_5-sha256).'
            ),
        };
    }

    /** Signature encodée pour l'en-tête `Signature` : `sig-pp=:<base64>:`. */
    public static function signatureHeader(string $rawSignature, string $paramName = self::SIGNATURE_PARAM_NAME): string
    {
        return $paramName . '=:' . base64_encode($rawSignature) . ':';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Vérification (callbacks entrants)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Vérifie un callback pawaPay : intégrité du corps (Content-Digest) puis
     * signature RFC-9421 avec la clé publique du provider.
     *
     * @param string               $body     Corps brut du callback.
     * @param array<string,string> $headers  En-têtes (clés en minuscules) :
     *        content-digest, signature, signature-input, signature-date,
     *        content-type.
     * @param callable(string):?string $publicKeyResolver Résout la clé PEM
     *        publique pour un keyid (endpoint Public Keys, cache).
     */
    public static function verifyCallback(
        string $body,
        array $headers,
        callable $publicKeyResolver
    ): bool {
        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower($k)] = $v;
        }

        // 1) Intégrité du corps : Content-Digest obligatoire.
        $digestHeader = trim((string) ($lower['content-digest'] ?? ''));
        if ($digestHeader === '' || !self::verifyContentDigest($body, $digestHeader)) {
            return false;
        }

        // 2) Signature RFC-9421 obligatoire (callback signé activé).
        $signatureInput = trim((string) ($lower['signature-input'] ?? ''));
        $signature      = trim((string) ($lower['signature'] ?? ''));
        if ($signatureInput === '' || $signature === '') {
            return false;
        }

        // 3) Extraire le premier paramètre `sig-pp=...` (ou équivalent).
        $parsed = self::parseSignatureInput($signatureInput);
        if ($parsed === null) {
            return false;
        }
        $sigValue = self::extractSignatureValue($signature, $parsed['param_name']);
        if ($sigValue === '') {
            return false;
        }
        $rawSignature = base64_decode($sigValue, true);
        if ($rawSignature === false || $rawSignature === '') {
            return false;
        }

        // 4) Fenêtre anti-rejeu. `expires` est honoré lorsqu'il est présent ;
        // `created` ne peut être ni futur de plus de 5 minutes, ni vieux de
        // plus de 5 minutes. Une signature sans aucune borne temporelle est
        // refusée : l'intégrité sans fraîcheur autoriserait le rejeu.
        $now = time();
        if ($parsed['expires'] !== null && $parsed['expires'] < $now) {
            return false;
        }
        if ($parsed['created'] === null
            || $parsed['created'] > $now + 300
            || $parsed['created'] < $now - 300) {
            return false;
        }

        // 5) Clé publique du provider (par keyid).
        $publicKeyPem = $publicKeyResolver($parsed['keyid']);
        if ($publicKeyPem === null || $publicKeyPem === '') {
            return false;
        }

        // 6) Base de signature reconstruite depuis les en-têtes réels.
        //    RFC-9421 : l'en-tête Signature-Input porte le label (sig-pp=...),
        //    mais la ligne @signature-params de la base n'a PAS de label.
        $components = [];
        foreach ($parsed['components'] as $comp) {
            $components[$comp] = self::componentValue($comp, $lower);
        }
        $base = self::signatureBase($components, $parsed['params']);

        // 7) Vérification.
        return self::verify($base, $rawSignature, $publicKeyPem, $parsed['alg']);
    }

    /**
     * Vérifie qu'un Content-Digest correspond au corps reçu.
     */
    public static function verifyContentDigest(string $body, string $digestHeader): bool
    {
        if (!preg_match('/^(sha-256|sha-512)=:([A-Za-z0-9+\/=]+):$/', trim($digestHeader), $m)) {
            return false;
        }
        $algo  = $m[1] === 'sha-256' ? 'sha256' : 'sha512';
        $expected = base64_encode(hash($algo, $body, true));

        return hash_equals($expected, $m[2]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internes
    // ──────────────────────────────────────────────────────────────────────

    /*     * @return array{alg:string,keyid:string,created:?int,expires:?int,components:list<string>,param_name:string,params:string}|null
     */
    private static function parseSignatureInput(string $signatureInput): ?array
    {
        // RFC-9421 : `sig-pp=("..." "...");alg="...";keyid="...";created=N;expires=N`
        // (le label peut être absent dans certains émetteurs — accepté ici aussi).
        if (!preg_match('/^(?:[A-Za-z0-9_\-]+=)?\(([^)]*)\);(.*)$/', trim($signatureInput), $m)) {
            return null;
        }
        $paramName = 'sig-pp';
        if (preg_match('/^([A-Za-z0-9_\-]+)=\(/', trim($signatureInput), $pm)) {
            $paramName = $pm[1];
        }
        $components = [];
        if (preg_match_all('/"([^"]+)"/', $m[1], $cm)) {
            $components = $cm[1];
        }
        if ($components === []) {
            return null;
        }

        $alg = $keyid = '';
        $created = $expires = null;
        foreach (explode(';', $m[2]) as $attr) {
            if (preg_match('/^alg="([^"]+)"$/', $attr, $a)) {
                $alg = $a[1];
            } elseif (preg_match('/^keyid="([^"]+)"$/', $attr, $a)) {
                $keyid = $a[1];
            } elseif (preg_match('/^created=(\d+)$/', $attr, $a)) {
                $created = (int) $a[1];
            } elseif (preg_match('/^expires=(\d+)$/', $attr, $a)) {
                $expires = (int) $a[1];
            }
        }
        if ($alg === '' || $keyid === '') {
            return null;
        }

        return [
            'alg'         => $alg,
            'keyid'       => $keyid,
            'created'     => $created,
            'expires'     => $expires,
            'components'  => $components,
            'param_name'  => $paramName,
            // Forme canonique (sans label) pour la ligne @signature-params.
            'params'      => '(' . $m[1] . ');' . $m[2],
        ];
    }

    /** Extrait la valeur binaire base64 d'un en-tête Signature pour un param donné. */
    private static function extractSignatureValue(string $signature, string $paramName): string
    {
        // Format : `sig-pp=:MEQCIH...:` — une ou plusieurs paires clé=valeur.
        if (preg_match('/\b' . preg_quote($paramName, '/') . '=:([A-Za-z0-9+\/=]+):/', $signature, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Valeur d'un composant de la base de signature (dérivé ou en-tête). */
    private static function componentValue(string $component, array $headers): string
    {
        return match ($component) {
            '@method'    => strtoupper((string) ($headers['@method'] ?? 'POST')),
            '@authority' => (string) ($headers['@authority'] ?? ''),
            '@path'      => (string) ($headers['@path'] ?? ''),
            default      => (string) ($headers[$component] ?? ''),
        };
    }

    /** Encodage JSON d'une valeur de composant (guillemets + échappements). */
    private static function jsonQuote(string $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '"' . addslashes($value) . '"' : $json;
    }

    private static function signEcdsa($key, string $base): string
    {
        $der = '';
        if (!openssl_sign($base, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature ECDSA pawaPay impossible.');
        }
        return self::derToRawEcdsa($der, 32);
    }

    private static function signRsaV15($key, string $base): string
    {
        $sig = '';
        if (!openssl_sign($base, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature RSA v1.5 pawaPay impossible.');
        }
        return $sig;
    }

    private static function verify(string $base, string $rawSignature, string $publicKeyPem, string $alg): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return false;
        }
        return match ($alg) {
            'ecdsa-p256-sha256' => openssl_verify($base, self::rawToDerEcdsa($rawSignature, 32), $key, OPENSSL_ALGO_SHA256) === 1,
            'rsa-v1_5-sha256'   => openssl_verify($base, $rawSignature, $key, OPENSSL_ALGO_SHA256) === 1,
            default             => false,
        };
    }

    /**
     * Convertit une signature ECDSA DER (ASN.1 r||s) en concaténation brute R||S.
     *
     * @param int $coordLen Longueur d'une coordonnée (32 pour P-256, 48 pour P-384).
     */
    public static function derToRawEcdsa(string $der, int $coordLen = 32): string
    {
        // Parse minimaliste du DER : SEQUENCE { INTEGER r, INTEGER s }
        $offset = 0;
        $len = strlen($der);
        if ($len < 8 || ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Signature ECDSA DER invalide.');
        }
        $seqLen = ord($der[$offset++]);
        if ($seqLen & 0x80) {
            $n = $seqLen & 0x7f;
            $seqLen = 0;
            for ($i = 0; $i < $n; $i++) {
                $seqLen = ($seqLen << 8) | ord($der[$offset++]);
            }
        }

        $readInt = static function () use ($der, &$offset, $coordLen): string {
            if (ord($der[$offset++]) !== 0x02) {
                throw new RuntimeException('Signature ECDSA DER invalide (INTEGER attendu).');
            }
            $iLen = ord($der[$offset++]);
            if ($iLen & 0x80) {
                $n = $iLen & 0x7f;
                $iLen = 0;
                for ($i = 0; $i < $n; $i++) {
                    $iLen = ($iLen << 8) | ord($der[$offset++]);
                }
            }
            $bytes = substr($der, $offset, $iLen);
            $offset += $iLen;
            // Retire le zéro de signe éventuel.
            if (strlen($bytes) > $coordLen && $bytes[0] === "\x00") {
                $bytes = substr($bytes, 1);
            }
            // Pad à coordLen à gauche.
            return str_pad($bytes, $coordLen, "\x00", STR_PAD_LEFT);
        };

        $r = $readInt();
        $s = $readInt();

        return $r . $s;
    }

    /** Convertit une signature brute R||S en DER (pour openssl_verify). */
    public static function rawToDerEcdsa(string $raw, int $coordLen = 32): string
    {
        if (strlen($raw) !== $coordLen * 2) {
            return "\x00"; // invalide : openssl_verify échouera
        }
        $r = substr($raw, 0, $coordLen);
        $s = substr($raw, $coordLen);

        $toDerInt = static function (string $bytes): string {
            // Retire les zéros de tête puis garantit un bit de signe 0.
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '') {
                $bytes = "\x00";
            }
            if ((ord($bytes[0]) & 0x80) !== 0) {
                $bytes = "\x00" . $bytes;
            }
            return "\x02" . chr(strlen($bytes)) . $bytes;
        };

        $content = $toDerInt($r) . $toDerInt($s);
        return "\x30" . chr(strlen($content)) . $content;
    }
}
