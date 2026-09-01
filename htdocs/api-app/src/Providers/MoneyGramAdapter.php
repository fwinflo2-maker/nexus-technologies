<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;
use RuntimeException;

/**
 * MoneyGramAdapter — adaptateur MoneyGram Developer APIs.
 *
 * Auth officielle (developer.moneygram.com/moneygram-developer/docs/o-auth-api) :
 *   GET {host}/oauth/accesstoken?grant_type=client_credentials
 *   Authorization: Basic base64(client_id:client_secret)
 *   → access_token (Bearer), expires_in (~3599 s)
 *
 * Hosts :
 *   sandbox    → https://sandboxapi.moneygram.com
 *   production → https://api.moneygram.com
 *
 * Modules documentés (disbursement / transfer) : cash pickup B2C et, via
 * Transfer API, bank/wallet — non câblés ici tant qu'un E2E partenaire n'est
 * pas démontré. testConnection() valide uniquement l'OAuth.
 *
 * agentPartnerId : identifiant partenaire requis sur les appels métier ;
 * optionnel pour la sonde OAuth.
 */
final class MoneyGramAdapter extends AbstractProviderAdapter
{
    /** @var null|callable(string,string,array,string):array{status:int,body:string} */
    private $transport;

    /**
     * @param null|callable(string,string,array,string):array{status:int,body:string} $transport
     */
    public function __construct(?callable $transport = null)
    {
        parent::__construct('moneygram');
        $this->transport = $transport;
    }

    protected function declaredMethods(): array
    {
        // Disbursement = cash pickup. Bank/wallet existent sur Transfer API
        // mais ne sont pas déclarés tant que non câblés.
        return ['cash_pickup'];
    }

    public function testConnection(string $environment, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        $creds = $this->credentials($env, $credentials);
        $clientId = trim((string) ($creds['client_id'] ?? ''));
        $clientSecret = trim((string) ($creds['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return [
                'status' => 'PROVIDER_NOT_CONFIGURED',
                'message' => 'Credentials MoneyGram absentes (client_id / client_secret) : aucun appel envoyé.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $res = $this->oauthToken($env, $clientId, $clientSecret);
        } catch (RuntimeException $e) {
            return [
                'status' => str_contains(strtolower($e->getMessage()), 'timeout') ? 'TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'message' => 'API MoneyGram injoignable.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        return match (true) {
            $res['status'] === 200 && $this->hasAccessToken($res['body']) => [
                'status' => 'CONNECTION_SUCCESS',
                'message' => 'OAuth MoneyGram authentifié (access_token reçu).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $res['status'] === 401 => [
                'status' => 'INVALID_CREDENTIALS',
                'message' => 'Credentials MoneyGram rejetées (401).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $res['status'] === 403 => [
                'status' => 'UNAUTHORIZED',
                'message' => 'Credentials MoneyGram sans permission suffisante (403).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            default => [
                'status' => 'CONFIGURATION_ERROR',
                'message' => 'Réponse inattendue de MoneyGram (HTTP ' . $res['status'] . ').',
                'tested_at' => gmdate(DATE_ATOM),
            ],
        };
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
        foreach (['client_id', 'client_secret', 'agent_partner_id'] as $field) {
            $v = ProviderConfig::credential($this->slug, $field, $environment);
            if ($v !== null) {
                $out[$field] = $v;
            }
        }
        return $out;
    }

    /** @return array{status:int,body:string} */
    private function oauthToken(string $environment, string $clientId, string $clientSecret): array
    {
        $base = rtrim(ProviderConfig::baseUrl($this->slug, $environment), '/');
        $url = $base . '/oauth/accesstoken?grant_type=client_credentials';
        $headers = [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        return $this->request('GET', $url, $headers, '');
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $url, array $headers, string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Initialisation HTTP MoneyGram impossible.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body !== '' ? $body : null,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXREDIRS => 0,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($response === false || $errno !== CURLE_OK) {
            throw new RuntimeException(
                $errno === CURLE_OPERATION_TIMEOUTED ? 'MoneyGram timeout.' : 'MoneyGram réseau indisponible.'
            );
        }
        return ['status' => $status, 'body' => (string) $response];
    }

    private function hasAccessToken(string $body): bool
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return false;
        }
        return trim((string) ($decoded['access_token'] ?? '')) !== '';
    }
}
