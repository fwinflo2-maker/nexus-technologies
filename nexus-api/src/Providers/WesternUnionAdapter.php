<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * WesternUnionAdapter — adaptateur Western Union Mass Payments API.
 *
 * Basé sur la documentation officielle OpenAPI Western Union
 * (developer.westernunion.com/getting-started.html) :
 *   - serveurs : prod https://api.westernunion.com · sandbox https://api-sandbox.westernunion.com
 *   - auth     : Mutual TLS (mTLS) — certificat client délivré par Western Union
 *     à l'adhésion au Partnership Program (source : openapi/western-union-mass-payments-openapi.yml)
 *   - endpoints documentés :
 *       GET  /Ping                                  (health)
 *       GET  /customers/{clientId}                  (customer)
 *       POST /customers/{clientId}/quotes           (FX quote)
 *       PUT  /customers/{clientId}/batches/{batchId}
 *       POST /customers/{clientId}/batches/{batchId}/payments
 *       GET  /customers/{clientId}/batches/{batchId}/payments/{paymentId}
 *
 * Credentials résolus depuis l'environnement (PROVIDER_WESTERN_UNION_*),
 * jamais en dur ni exposés. L'accès réel nécessite l'onboarding partenaire
 * (pas de self-service) ; les opérations réseau ne sont donc exécutées que
 * si des credentials mTLS sont configurés.
 */
final class WesternUnionAdapter extends AbstractProviderAdapter
{
    public function __construct()
    {
        parent::__construct('western_union');
    }

    protected function declaredMethods(): array
    {
        return ['bank'];
    }

    /**
     * Health check réel : GET /Ping avec certificat mTLS.
     * Sans credentials mTLS configurés → statut NOT_CONFIGURED (pas de sonde).
     */
    public function healthCheck(): array
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $config      = $this->validateConfiguration();

        if ($config['status'] !== ProviderStatus::CONFIGURED) {
            return [
                'slug'        => $this->slug,
                'environment' => $environment,
                'status'      => $config['status']->value,
                'healthy'     => false,
                'latency_ms'  => null,
                'detail'      => 'Configuration mTLS incomplète — onboarding Western Union requis.',
            ];
        }

        $start = microtime(true);
        $cert  = ProviderConfig::get($this->slug, $environment, 'client_cert_path');
        $key   = ProviderConfig::get($this->slug, $environment, 'client_key_path');
        $base  = ProviderConfig::baseUrl($this->slug, $environment);

        // Sonde TCP (aucun secret échangé) si les chemins ne sont pas lisibles.
        if ($cert === null || $key === null || !is_file($cert) || !is_file($key)) {
            return [
                'slug'        => $this->slug,
                'environment' => $environment,
                'status'      => 'not_configured',
                'healthy'     => false,
                'latency_ms'  => null,
                'detail'      => 'Chemin de certificat mTLS non configuré ou introuvable.',
            ];
        }

        // Appel mTLS réel vers /Ping (ne part qu'avec un cert valide).
        $ch = curl_init($base . '/Ping');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLCERT        => $cert,
            CURLOPT_SSLKEY         => $key,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        return [
            'slug'        => $this->slug,
            'environment' => $environment,
            'status'      => $code === 200 ? 'active' : 'error',
            'healthy'     => $code === 200,
            'latency_ms'  => $latencyMs,
            'detail'      => $code === 200 ? 'API opérationnelle (pong).' : 'Réponse inattendue (HTTP ' . $code . ').',
            'raw'         => mb_substr((string) $body, 0, 200),
        ];
    }

    /**
     * Quote FX réel : POST /customers/{clientId}/quotes.
     * Corps minimaux de la doc : sendCurrency, receiveCurrency, sendAmount.
     */
    public function getQuote(array $intent): array
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $clientId    = ProviderConfig::get($this->slug, $environment, 'client_id');
        $cert        = ProviderConfig::get($this->slug, $environment, 'client_cert_path');
        $key         = ProviderConfig::get($this->slug, $environment, 'client_key_path');

        if ($clientId === null || $cert === null || $key === null) {
            throw new ProviderOperationNotImplemented('western_union', 'getQuote', 'Credentials mTLS Western Union manquantes.');
        }

        $payload = json_encode([
            'sendCurrency'    => $intent['from'] ?? null,
            'receiveCurrency' => $intent['to'] ?? null,
            'sendAmount'      => $intent['amount'] ?? null,
        ]);

        $base = ProviderConfig::baseUrl($this->slug, $environment);
        $ch = curl_init($base . '/customers/' . rawurlencode($clientId) . '/quotes');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLCERT        => $cert,
            CURLOPT_SSLKEY         => $key,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        if ($code === 201 && is_array($decoded)) {
            return $decoded;
        }
        throw new ProviderOperationNotImplemented('western_union', 'getQuote', 'Quote échouée (HTTP ' . $code . ').');
    }
}
