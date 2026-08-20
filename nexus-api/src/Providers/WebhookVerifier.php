<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * WebhookVerifier — vérification des signatures de webhooks entrants.
 *
 * Architecture préparée (§19) : un webhook entrant devra être :
 *  - authentifié (fourni par le provider qui signe le payload) ;
 *  - signé (HMAC) ;
 *  - vérifié (comparaison en temps constant) ;
 *  - idempotent (géré par la couche d'ingestion, clé d'idempotence) ;
 *  - journalisé (audit sans le secret) ;
 *  - associé au provider ET à l'environnement (sandbox vs production).
 *
 * Ce composant ne fait QUE la vérification cryptographique. Aucun endpoint
 * HTTP n'est exposé tant qu'un provider n'est pas réellement intégré : on ne
 * doit jamais accepter aveuglément un webhook externe.
 */
final class WebhookVerifier
{
    private function __construct()
    {
    }

    /**
     * Vérifie la signature HMAC d'un payload.
     *
     * Formats de signature acceptés :
     *   - hexadécimal brut            : "a1b2c3..."
     *   - préfixé par l'algorithme   : "sha256=a1b2c3..."
     *
     * @param string $payload          Corps brut du webhook (tel que reçu).
     * @param string $signatureHeader  Valeur de l'en-tête de signature.
     * @param string $secret           Secret partagé du provider (webhook secret).
     * @param string $algo             Algorithme HMAC (défaut sha256).
     */
    public static function verify(string $payload, string $signatureHeader, string $secret, string $algo = 'sha256'): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $algo = strtolower($algo);
        if (!in_array($algo, hash_hmac_algos(), true)) {
            return false;
        }

        $expected = hash_hmac($algo, $payload, $secret);

        $provided = trim($signatureHeader);
        if ($provided === '') {
            return false;
        }

        // Format "sha256=hex" (ex. Stripe : "t=...,v1=hex")
        if (str_contains($provided, '=') && !ctype_xdigit(str_replace(['=', ' '], '', $provided))) {
            $parts = explode('=', $provided, 2);
            $algoPart = strtolower(trim($parts[0]));
            $provided = trim($parts[1] ?? '');
            if ($algoPart !== $algo) {
                return false;
            }
        }

        if (!is_string($provided) || strlen($provided) !== strlen($expected)) {
            return false;
        }

        return hash_equals($expected, strtolower($provided));
    }

    /**
     * Vérifie une signature HMAC HORODATÉE (anti-rejeu temporel).
     *
     * Format d'en-tête (schéma Stripe-like, propre à Nexus) :
     *   t=<unix>,v1=<hex hmac_sha256(t . "." . payload, secret)>
     *
     * Plusieurs `v1` sont acceptés (rotation de secret) : une seule signature
     * valide suffit. Le timestamp signé est comparé à l'horloge serveur avec
     * une fenêtre de tolérance (±300 s par défaut) : une signature capturée
     * puis rejouée plus tard est refusée (`stale_timestamp`) même si le HMAC
     * est cryptographiquement correct.
     *
     * @param string   $payload          Corps brut du webhook (tel que reçu).
     * @param string   $signatureHeader  Valeur de l'en-tête `t=...,v1=...`.
     * @param string   $secret           Secret partagé du provider.
     * @param int      $toleranceSeconds Fenêtre de validité (défaut 300 s).
     * @param int|null $now              Horloge injectable pour les tests.
     *
     * @return array{valid:bool, reason:?string, timestamp:?int}
     */
    public static function verifyTimestamped(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): array {
        if ($secret === '' || trim($signatureHeader) === '') {
            return ['valid' => false, 'reason' => 'missing_signature', 'timestamp' => null];
        }

        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $signatureHeader) as $element) {
            $parts = explode('=', trim($element), 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$k, $v] = [trim($parts[0]), trim($parts[1])];
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v !== '' && ctype_xdigit($v)) {
                $candidates[] = strtolower($v);
            }
        }

        if ($timestamp === null) {
            return ['valid' => false, 'reason' => 'missing_timestamp', 'timestamp' => null];
        }
        if ($candidates === []) {
            return ['valid' => false, 'reason' => 'missing_signature', 'timestamp' => $timestamp];
        }

        // Le HMAC couvre le timestamp : impossible de déplacer la fenêtre
        // sans invalider la signature.
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $signatureValid = false;
        foreach ($candidates as $candidate) {
            if (strlen($candidate) === strlen($expected) && hash_equals($expected, $candidate)) {
                $signatureValid = true;
                break;
            }
        }
        if (!$signatureValid) {
            return ['valid' => false, 'reason' => 'signature_mismatch', 'timestamp' => $timestamp];
        }

        // Fenêtre APRÈS la crypto : ne révèle jamais si un HMAC hors fenêtre
        // aurait été correct pour un timestamp forgé non signé.
        $clock = $now ?? time();
        if (abs($clock - $timestamp) > max(0, $toleranceSeconds)) {
            return ['valid' => false, 'reason' => 'stale_timestamp', 'timestamp' => $timestamp];
        }

        return ['valid' => true, 'reason' => null, 'timestamp' => $timestamp];
    }
}
