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
}
