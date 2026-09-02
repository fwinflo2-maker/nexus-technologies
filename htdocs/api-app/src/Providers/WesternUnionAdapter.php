<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;
use RuntimeException;

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
 * Credentials (schéma vérifié) : chemins serveur `client_cert_path` /
 * `client_key_path` + `client_id` — curl CURLOPT_SSLCERT / CURLOPT_SSLKEY.
 * Pas de PEM inline en Credential Manager (alignement explicite path-based).
 *
 * L'accès réel nécessite l'onboarding partenaire (pas de self-service).
 */
final class WesternUnionAdapter extends AbstractProviderAdapter
{
    /** @var null|callable(string,string,array,string,?string,?string):array{status:int,body:string} */
    private $transport;

    /**
     * @param null|callable(string,string,array,string,?string,?string):array{status:int,body:string} $transport
     *        method, url, headers, body, certPath|null, keyPath|null
     */
    public function __construct(?callable $transport = null)
    {
        parent::__construct('western_union');
        $this->transport = $transport;
    }

    protected function declaredMethods(): array
    {
        return ['cash_pickup', 'bank'];
    }

    /**
     * Sonde auth réelle : GET /Ping avec mTLS.
     * Statuts normalisés comme MoneyGram.
     */
    public function testConnection(string $environment, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        $creds = $this->credentials($env, $credentials);
        $cert = trim((string) ($creds['client_cert_path'] ?? ''));
        $key = trim((string) ($creds['client_key_path'] ?? ''));
        $clientId = trim((string) ($creds['client_id'] ?? ''));

        if ($cert === '' || $key === '' || $clientId === '') {
            return [
                'status' => 'PROVIDER_NOT_CONFIGURED',
                'message' => 'Credentials mTLS Western Union absentes (client_id / client_cert_path / client_key_path).',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        // Transport injecté (tests) : ne pas exiger des fichiers réels.
        if ($this->transport === null && (!is_file($cert) || !is_file($key))) {
            return [
                'status' => 'PROVIDER_NOT_CONFIGURED',
                'message' => 'Chemin de certificat mTLS Western Union non configuré ou introuvable.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $res = $this->request('GET', '/Ping', [], '', $cert, $key, $env);
        } catch (RuntimeException $e) {
            $msg = strtolower($e->getMessage());
            return [
                'status' => str_contains($msg, 'timeout') ? 'TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'message' => 'API Western Union injoignable.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        return match (true) {
            $res['status'] === 200 => [
                'status' => 'CONNECTION_SUCCESS',
                'message' => 'mTLS Western Union authentifié (Ping OK).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $res['status'] === 401 => [
                'status' => 'INVALID_CREDENTIALS',
                'message' => 'Certificat mTLS Western Union rejeté (401).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $res['status'] === 403 => [
                'status' => 'UNAUTHORIZED',
                'message' => 'Certificat mTLS Western Union sans permission (403).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            default => [
                'status' => 'CONFIGURATION_ERROR',
                'message' => 'Réponse inattendue de Western Union (HTTP ' . $res['status'] . ').',
                'tested_at' => gmdate(DATE_ATOM),
            ],
        };
    }

    /**
     * Health check réel : GET /Ping avec certificat mTLS.
     * Sans credentials mTLS configurés → statut NOT_CONFIGURED (pas de sonde).
     */
    public function healthCheck(): array
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $config = $this->validateConfiguration();

        if ($config['status'] !== ProviderStatus::CONFIGURED) {
            return [
                'slug' => $this->slug,
                'environment' => $environment,
                'status' => $config['status']->value,
                'healthy' => false,
                'latency_ms' => null,
                'detail' => 'Configuration mTLS incomplète — onboarding Western Union requis.',
            ];
        }

        $start = microtime(true);
        $probe = $this->testConnection($environment);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $ok = ($probe['status'] ?? '') === 'CONNECTION_SUCCESS';

        return [
            'slug' => $this->slug,
            'environment' => $environment,
            'status' => $ok ? 'active' : 'error',
            'healthy' => $ok,
            'latency_ms' => $latencyMs,
            'detail' => (string) ($probe['message'] ?? ''),
        ];
    }

    /**
     * Quote FX réel : POST /customers/{clientId}/quotes.
     * Corps minimaux de la doc : sendCurrency, receiveCurrency, sendAmount.
     */
    public function getQuote(array $intent): array
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $creds = $this->credentials($environment);
        $clientId = trim((string) ($creds['client_id'] ?? ''));
        $cert = trim((string) ($creds['client_cert_path'] ?? ''));
        $key = trim((string) ($creds['client_key_path'] ?? ''));

        if ($clientId === '' || $cert === '' || $key === '') {
            throw new ProviderOperationNotImplemented(
                'western_union',
                'getQuote',
                'Credentials mTLS Western Union manquantes.'
            );
        }

        $payload = json_encode([
            'sendCurrency' => $intent['from'] ?? null,
            'receiveCurrency' => $intent['to'] ?? null,
            'sendAmount' => $intent['amount'] ?? null,
        ], JSON_THROW_ON_ERROR);

        $res = $this->request(
            'POST',
            '/customers/' . rawurlencode($clientId) . '/quotes',
            ['Content-Type: application/json'],
            $payload,
            $cert,
            $key,
            $environment
        );

        $decoded = json_decode($res['body'], true);
        if ($res['status'] === 201 && is_array($decoded)) {
            return $decoded;
        }
        throw new ProviderOperationNotImplemented(
            'western_union',
            'getQuote',
            'Quote échouée (HTTP ' . $res['status'] . ').'
        );
    }

    /** @return array<string,string> */
    private function credentials(string $environment, ?array $provided = null): array
    {
        if (is_array($provided) && $provided !== []) {
            return $provided;
        }
        try {
            $managed = ProviderCredentialService::resolvePlatform(
                Database::getConnection(),
                $this->slug,
                $environment
            );
            if (is_array($managed) && $managed !== []) {
                return $managed;
            }
        } catch (\Throwable) {
        }

        $out = [];
        foreach (['client_id', 'client_cert_path', 'client_key_path', 'partner_id'] as $field) {
            $v = ProviderConfig::credential($this->slug, $field, $environment);
            if ($v !== null) {
                $out[$field] = $v;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,body:string}
     */
    private function request(
        string $method,
        string $path,
        array $headers,
        string $body,
        string $cert,
        string $key,
        string $environment
    ): array {
        $url = rtrim(ProviderConfig::baseUrl($this->slug, $environment), '/') . $path;

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body, $cert, $key);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Initialisation HTTP Western Union impossible.');
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLCERT => $cert,
            CURLOPT_SSLKEY => $key,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXREDIRS => 0,
        ];
        if ($body !== '') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($response === false || $errno !== CURLE_OK) {
            throw new RuntimeException(
                $errno === CURLE_OPERATION_TIMEOUTED ? 'Western Union timeout.' : 'Western Union réseau indisponible.'
            );
        }
        return ['status' => $status, 'body' => (string) $response];
    }
}
