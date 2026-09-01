<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;
use Nexus\Services\VirtualCardIssuancePolicy;
use RuntimeException;

/**
 * Maplerad Issuing — cartes virtuelles pour l'Afrique.
 *
 * Paysika n'expose pas d'API BaaS (néobanque B2C, CM + CI). Maplerad est
 * l'infrastructure publique d'émission : NG, GH, KE, CI, BJ, CM, UG, TZ.
 *
 * Documentation :
 *   - Auth Bearer : https://maplerad.dev/docs/authentication
 *   - Create customer : POST /v1/customers
 *   - Create card : https://maplerad.dev/reference/create-a-card (POST /v1/issuing)
 *
 * HONNÊTETÉ : aucun PAN/CVV n'est stocké. L'émission est asynchrone
 * (référence + webhook issuing.created.successful).
 */
final class MapleradIssuingAdapter extends AbstractProviderAdapter
{
    public const SUPPORTED_CURRENCIES = ['USD'];

    /** @var null|callable(string,string,array,string):array{status:int,body:string} */
    private $transport;

    /**
     * @param null|callable(string,string,array,string):array{status:int,body:string} $transport
     */
    public function __construct(?callable $transport = null)
    {
        parent::__construct('maplerad');
        $this->transport = $transport;
    }

    protected function declaredMethods(): array
    {
        return ['card_issuing'];
    }

    public function testConnection(string $environment, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        $secret = $this->resolveSecret($env, $credentials);
        if ($secret === null || $secret === '') {
            return [
                'status'    => 'PROVIDER_NOT_CONFIGURED',
                'message'   => 'Clé secrète Maplerad absente : aucun appel envoyé.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $res = $this->request('GET', '/wallets', $secret, '', $env);
        } catch (RuntimeException $e) {
            $msg = strtolower($e->getMessage());
            return [
                'status'    => str_contains($msg, 'timeout') ? 'TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'message'   => 'API Maplerad injoignable.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        $code = (int) ($res['status'] ?? 0);
        return match (true) {
            $code === 200 => [
                'status'    => 'CONNECTION_SUCCESS',
                'message'   => 'Maplerad joignable : la clé est authentifiée.',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $code === 401 => [
                'status'    => 'INVALID_CREDENTIALS',
                'message'   => 'Clé secrète Maplerad rejetée (401).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $code === 403 => [
                'status'    => 'UNAUTHORIZED',
                'message'   => 'Clé Maplerad sans permission (403). Vérifiez l’IP whitelist en production.',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            default => [
                'status'    => 'CONFIGURATION_ERROR',
                'message'   => 'Réponse inattendue de Maplerad (HTTP ' . $code . ').',
                'tested_at' => gmdate(DATE_ATOM),
            ],
        };
    }

    /**
     * @param array<string,mixed> $holder
     * @param array{currency:string,label?:string,spend_limit?:float|null,cardholder_id?:string|null,idempotency_key?:string|null} $opts
     * @return array{
     *   issuer_ref:string,
     *   cardholder_id:string,
     *   last4:?string,
     *   brand:?string,
     *   status:string,
     *   currency:string
     * }
     */
    public function issueVirtualCard(string $environment, array $holder, array $opts, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        $secret = $this->resolveSecret($env, $credentials);
        if ($secret === null || $secret === '') {
            throw new RuntimeException('CREDENTIALS_NOT_CONFIGURED');
        }

        $currency = strtoupper(trim((string) ($opts['currency'] ?? '')));
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new RuntimeException('CURRENCY_NOT_SUPPORTED_BY_ISSUER');
        }

        $country = strtoupper(trim((string) ($holder['country_of_residence'] ?? '')));
        if (!VirtualCardIssuancePolicy::isMapleradIssuingCountry($country)) {
            throw new RuntimeException('COUNTRY_NOT_SUPPORTED_BY_ISSUER');
        }

        $existing = trim((string) ($opts['cardholder_id'] ?? ''));
        if ($this->isCustomerId($existing)) {
            $customerId = $existing;
        } else {
            $customerId = $this->createCustomer($secret, $env, $holder);
        }

        $payload = [
            'customer_id'  => $customerId,
            'currency'     => 'USD',
            'type'         => 'VIRTUAL',
            'auto_approve' => true,
            'brand'        => 'VISA',
        ];
        $res = $this->request('POST', '/issuing', $secret, json_encode($payload, JSON_THROW_ON_ERROR), $env);
        if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
            throw new RuntimeException($this->mapHttpFailure((int) $res['status']));
        }
        $data = json_decode((string) $res['body'], true);
        $inner = is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
        $ref = (string) ($inner['reference'] ?? $inner['id'] ?? '');
        if ($ref === '') {
            throw new RuntimeException('ISSUER_INVALID_RESPONSE');
        }

        $last4 = isset($inner['masked_pan']) ? $this->last4FromMasked((string) $inner['masked_pan']) : null;
        if (isset($inner['last4']) && is_string($inner['last4']) && $inner['last4'] !== '') {
            $last4 = $inner['last4'];
        }

        return [
            'issuer_ref'    => $ref,
            'cardholder_id' => $customerId,
            'last4'         => $last4,
            'brand'         => strtolower((string) ($inner['issuer'] ?? $inner['brand'] ?? 'visa')),
            'status'        => 'pending_issuer',
            'currency'      => 'USD',
        ];
    }

    public function hasCredentials(string $environment): bool
    {
        $secret = $this->resolveSecret($environment === 'production' ? 'production' : 'sandbox', null);
        return is_string($secret) && $secret !== '';
    }

    /** @param array<string,mixed> $holder */
    private function createCustomer(string $secret, string $env, array $holder): string
    {
        $names = $this->splitName((string) ($holder['full_name'] ?? ''));
        if ($names['first'] === '' || $names['last'] === '') {
            throw new RuntimeException('CARDHOLDER_NAME_REQUIRED');
        }
        $email = trim((string) ($holder['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('CARDHOLDER_EMAIL_REQUIRED');
        }
        $payload = [
            'first_name' => $names['first'],
            'last_name'  => $names['last'],
            'email'      => $email,
            'country'    => strtoupper(trim((string) ($holder['country_of_residence'] ?? ''))),
        ];
        $res = $this->request('POST', '/customers', $secret, json_encode($payload, JSON_THROW_ON_ERROR), $env);
        if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
            throw new RuntimeException($this->mapHttpFailure((int) $res['status']));
        }
        $data = json_decode((string) $res['body'], true);
        $id = '';
        if (is_array($data)) {
            $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;
            $id = (string) ($inner['id'] ?? '');
        }
        if ($id === '') {
            throw new RuntimeException('ISSUER_INVALID_RESPONSE');
        }
        return $id;
    }

    private function isCustomerId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /** @return array{first:string,last:string} */
    private function splitName(string $fullName): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
        if ($clean === '') {
            return ['first' => '', 'last' => ''];
        }
        $parts = explode(' ', $clean, 2);
        return [
            'first' => mb_substr($parts[0], 0, 80),
            'last'  => mb_substr($parts[1] ?? $parts[0], 0, 80),
        ];
    }

    private function last4FromMasked(string $masked): ?string
    {
        if (preg_match('/(\d{4})\s*$/', $masked, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function resolveSecret(string $environment, ?array $provided): ?string
    {
        if (is_array($provided)) {
            $v = trim((string) ($provided['secret_key'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        try {
            $managed = ProviderCredentialService::resolvePlatform(
                Database::getConnection(),
                $this->slug,
                $environment
            );
            if (is_array($managed)) {
                $v = trim((string) ($managed['secret_key'] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        } catch (\Throwable) {
        }
        $envVal = ProviderConfig::credential($this->slug, 'SECRET_KEY', $environment);
        return is_string($envVal) && $envVal !== '' ? $envVal : null;
    }

    /** @return array{status:int,body:string} */
    private function request(
        string $method,
        string $path,
        string $secret,
        string $body,
        string $environment
    ): array {
        $base = rtrim(ProviderConfig::baseUrl($this->slug, $environment), '/');
        if ($base === '') {
            $base = $environment === 'production'
                ? 'https://api.maplerad.com/v1'
                : 'https://sandbox.api.maplerad.com/v1';
        }
        $url = $base . $path;
        $headers = [
            'Authorization: Bearer ' . $secret,
            'Accept: application/json',
        ];
        if ($body !== '' && strtoupper($method) !== 'GET') {
            $headers[] = 'Content-Type: application/json';
        }

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Initialisation HTTP Maplerad impossible.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body !== '' ? $body : null,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            throw new RuntimeException('TIMEOUT');
        }
        if ($errno !== CURLE_OK) {
            throw new RuntimeException('PROVIDER_UNAVAILABLE');
        }

        return ['status' => $code, 'body' => is_string($raw) ? $raw : ''];
    }

    private function mapHttpFailure(int $status): string
    {
        return match ($status) {
            401, 403 => 'ISSUER_UNAUTHORIZED',
            429 => 'ISSUER_RATE_LIMITED',
            default => 'ISSUER_REJECTED',
        };
    }
}
