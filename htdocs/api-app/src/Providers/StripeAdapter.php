<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Throwable;

/**
 * StripeAdapter — adaptateur Stripe (exemple concret).
 *
 * Ne contient AUCUNE clé : les credentials sont résolus depuis l'environnement
 * (PROVIDER_STRIPE_*) ou fournies par le dashboard SuperAdmin (déchiffrées).
 * Les opérations de paiement réelles seront implémentées ici, derrière
 * l'interface commune, sans jamais toucher au Core.
 *
 * Test de connexion (§5) : GET /v1/balance avec la clé secrète — un appel
 * RÉEL qui vérifie l'authentification, jamais un ping TCP.
 */
final class StripeAdapter extends AbstractProviderAdapter
{
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __construct()
    {
        parent::__construct('stripe');
    }

    protected function declaredMethods(): array
    {
        return ['card', 'bank'];
    }

    /**
     * Vérifie réellement la connexion à l'API Stripe.
     *
     * GET /v1/balance avec la clé secrète (Bearer). Réponses :
     *   200 → CONNECTION_SUCCESS (la clé est valide et a des permissions) ;
     *   401 → INVALID_CREDENTIALS (clé inconnue) ;
     *   403 → UNAUTHORIZED (clé restreinte sans permission balance) ;
     *   429 → CONFIGURATION_ERROR (rate limit) ;
     *   sinon CONFIGURATION_ERROR / PROVIDER_UNAVAILABLE / TIMEOUT.
     */
    public function testConnection(string $environment, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';

        $secret = $credentials['secret_key'] ?? null;
        if ($secret === null || $secret === '') {
            $secret = ProviderConfig::credential($this->slug, 'SECRET_KEY', $env);
        }
        if ($secret === null || $secret === '') {
            return [
                'status'    => 'PROVIDER_NOT_CONFIGURED',
                'message'   => 'Clé secrète Stripe absente : aucun appel envoyé.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        $base = rtrim(ProviderConfig::baseUrl($this->slug, $env), '/');
        $ch   = curl_init($base . '/balance');
        if ($ch === false) {
            return ['status' => 'CONFIGURATION_ERROR', 'message' => "Impossible d'initialiser la requête HTTP.", 'tested_at' => gmdate(DATE_ATOM)];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body   = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            return ['status' => 'TIMEOUT', 'message' => "Délai dépassé pour joindre l'API Stripe.", 'tested_at' => gmdate(DATE_ATOM)];
        }
        if ($errno !== CURLE_OK) {
            return ['status' => 'PROVIDER_UNAVAILABLE', 'message' => 'API Stripe injoignable (erreur réseau).', 'tested_at' => gmdate(DATE_ATOM)];
        }

        return match (true) {
            $code === 200   => ['status' => 'CONNECTION_SUCCESS', 'message' => 'Connexion Stripe valide : la clé est authentifiée.', 'tested_at' => gmdate(DATE_ATOM)],
            $code === 401   => ['status' => 'INVALID_CREDENTIALS', 'message' => 'Clé secrète Stripe rejetée (401).', 'tested_at' => gmdate(DATE_ATOM)],
            $code === 403   => ['status' => 'UNAUTHORIZED', 'message' => 'Clé Stripe sans permission suffisante (403).', 'tested_at' => gmdate(DATE_ATOM)],
            $code === 429   => ['status' => 'CONFIGURATION_ERROR', 'message' => 'Limite de débit Stripe atteinte (429).', 'tested_at' => gmdate(DATE_ATOM)],
            default         => ['status' => 'CONFIGURATION_ERROR', 'message' => 'Réponse inattendue de Stripe (HTTP ' . $code . ').', 'tested_at' => gmdate(DATE_ATOM)],
        };
    }

    /**
     * Vérification native Stripe-Signature :
     *   signed_payload = "{timestamp}.{raw_body}"
     *   signature      = HMAC-SHA256(webhook signing secret)
     *
     * Plusieurs signatures v1 sont acceptées pour permettre la rotation
     * Stripe. Chaque comparaison utilise hash_equals.
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $secret = ProviderConfig::credential($this->slug, 'WEBHOOK_SECRET', $environment);
        return is_string($secret) && $secret !== ''
            && self::verifyStripeSignature($payload, $signature, $secret);
    }

    public static function verifyStripeSignature(
        string $payload,
        string $signatureHeader,
        string $secret,
        ?int $now = null,
        int $tolerance = self::WEBHOOK_TOLERANCE_SECONDS
    ): bool {
        if ($signatureHeader === '' || $secret === '' || $tolerance < 0) {
            return false;
        }
        $timestamp = null;
        $v1 = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $value)) {
                $v1[] = strtolower($value);
            }
        }
        if ($timestamp === null || $v1 === []) {
            return false;
        }
        $now ??= time();
        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($v1 as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }
}
