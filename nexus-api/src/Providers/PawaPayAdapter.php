<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Services\ProviderCredentialService;
use RuntimeException;

/**
 * Adaptateur pawaPay Merchant API v2.
 *
 * Contrat officiel :
 *   POST /v2/payouts
 *   GET  /v2/payouts/{payoutId}
 *   GET  /v2/public-key/http
 *
 * Le payoutId fourni par Nexus est l'identifiant d'idempotence officiel
 * pawaPay. Aucun succès n'est déduit du seul HTTP 200 : le statut JSON est
 * toujours interprété.
 */
final class PawaPayAdapter extends AbstractProviderAdapter
{
    /** @var null|callable(string,string,array,string):array{status:int,body:string} */
    private $transport;

    /**
     * Cartographie des statuts finaux et intermédiaires documentés.
     */
    public const STATUS_MAP = [
        'ACCEPTED'  => 'processing',
        'ENQUEUED'  => 'processing',
        'DUPLICATE_IGNORED' => 'processing',
        'REJECTED'  => 'failed',
        'COMPLETED' => 'completed',
        'FAILED'    => 'failed',
        'REVERSED'  => 'failed', // compensation = remboursement ; pas d'état 'reversed' dédié
    ];

    /**
     * @param null|callable(string,string,array,string):array{status:int,body:string} $transport
     */
    public function __construct(?callable $transport = null)
    {
        parent::__construct('pawapay');
        $this->transport = $transport;
    }

    protected function declaredMethods(): array
    {
        return ['mobile_money'];
    }

    public function testConnection(string $environment, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        $creds = $this->credentials($env, $credentials);
        $token = trim((string) ($creds['api_token'] ?? ''));
        if ($token === '') {
            return [
                'status' => 'PROVIDER_NOT_CONFIGURED',
                'message' => 'Token pawaPay absent : aucun appel envoyé.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $res = $this->request('GET', '/v2/public-key/http', $token);
        } catch (RuntimeException $e) {
            return [
                'status' => str_contains(strtolower($e->getMessage()), 'timeout') ? 'TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'message' => 'API pawaPay injoignable.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        return match (true) {
            $res['status'] === 200 => [
                'status' => 'CONNECTION_SUCCESS',
                'message' => 'Token pawaPay authentifié.',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $res['status'] === 401 => [
                'status' => 'INVALID_CREDENTIALS',
                'message' => 'Token pawaPay rejeté (401).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $res['status'] === 403 => [
                'status' => 'UNAUTHORIZED',
                'message' => 'Token pawaPay sans permission suffisante (403).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            default => [
                'status' => 'CONFIGURATION_ERROR',
                'message' => 'Réponse inattendue de pawaPay (HTTP ' . $res['status'] . ').',
                'tested_at' => gmdate(DATE_ATOM),
            ],
        };
    }

    public function createPayment(array $params): array
    {
        $env = (($params['environment'] ?? '') === 'production') ? 'production' : 'sandbox';
        $creds = $this->credentials($env);
        $token = trim((string) ($creds['api_token'] ?? ''));
        if ($token === '') {
            throw new HttpException(503, 'Credentials pawaPay non configurées.', 'CREDENTIALS_NOT_CONFIGURED');
        }

        $payoutId = trim((string) ($params['operation_id'] ?? ''));
        $amount = self::pawaAmount((string) ($params['dest_amount'] ?? ''));
        $currency = strtoupper(trim((string) ($params['dest_currency'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($params['destination'] ?? '')) ?? '';
        $operator = strtoupper(trim((string) ($params['operator'] ?? '')));

        if (!self::isUuid($payoutId) || $amount === '' || !preg_match('/^[A-Z]{3}$/', $currency)
            || $phone === '' || $phone[0] === '0' || $operator === '') {
            throw new HttpException(422, 'Paramètres payout pawaPay invalides.', 'INVALID_PROVIDER_PAYLOAD');
        }

        $payload = [
            'payoutId' => $payoutId,
            'recipient' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $phone,
                    'provider' => $operator,
                ],
            ],
            'amount' => $amount,
            'currency' => $currency,
            'clientReferenceId' => $payoutId,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Encodage du payout pawaPay impossible.');
        }

        $res = $this->request('POST', '/v2/payouts', $token, $body);
        $decoded = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($decoded)) {
            throw new HttpException(
                in_array($res['status'], [408, 429, 500, 502, 503, 504], true) ? 503 : 502,
                'Payout pawaPay refusé ou indisponible.',
                in_array($res['status'], [408, 429, 500, 502, 503, 504], true) ? 'PROVIDER_RETRYABLE' : 'PROVIDER_ERROR'
            );
        }

        $rawStatus = strtoupper((string) ($decoded['status'] ?? ''));
        if ($rawStatus === 'REJECTED') {
            $reason = is_array($decoded['failureReason'] ?? null)
                ? (string) (($decoded['failureReason']['failureCode'] ?? 'REJECTED'))
                : 'REJECTED';
            throw new HttpException(422, 'Payout pawaPay rejeté : ' . $reason, 'PROVIDER_REJECTED');
        }
        // ACCEPTED = initiation OK ; ENQUEUED = encore asynchrone ; DUPLICATE_IGNORED
        // = replay d'idempotence. Aucun de ces statuts n'est un succès terminal.
        if (!in_array($rawStatus, ['ACCEPTED', 'ENQUEUED', 'DUPLICATE_IGNORED'], true)) {
            throw new HttpException(502, 'Statut d’initiation pawaPay inconnu.', 'PROVIDER_PROTOCOL_ERROR');
        }

        return [
            'id' => (string) ($decoded['payoutId'] ?? $payoutId),
            'status' => strtolower($rawStatus),
            'provider_status' => $rawStatus,
            'created' => $decoded['created'] ?? null,
        ];
    }

    public function getPaymentStatus(string $paymentId): array
    {
        if (!self::isUuid($paymentId)) {
            throw new HttpException(422, 'payoutId pawaPay invalide.', 'INVALID_PROVIDER_REFERENCE');
        }
        $env = ProviderConfig::activeEnvironment($this->slug);
        $token = trim((string) ($this->credentials($env)['api_token'] ?? ''));
        if ($token === '') {
            throw new HttpException(503, 'Credentials pawaPay non configurées.', 'CREDENTIALS_NOT_CONFIGURED');
        }
        $res = $this->request('GET', '/v2/payouts/' . rawurlencode($paymentId), $token);
        $decoded = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($decoded)) {
            throw new HttpException(503, 'Statut pawaPay indisponible.', 'PROVIDER_RETRYABLE');
        }
        if (($decoded['status'] ?? '') !== 'FOUND' || !is_array($decoded['data'] ?? null)) {
            return ['status' => 'processing', 'provider' => $this->slug, 'provider_status' => 'NOT_FOUND'];
        }
        $data = $decoded['data'];
        $rawStatus = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));
        return [
            'status' => self::STATUS_MAP[$rawStatus] ?? 'processing',
            'provider' => $this->slug,
            'provider_status' => $rawStatus,
            'amount' => (string) ($data['amount'] ?? ''),
            'currency' => (string) ($data['currency'] ?? ''),
            'provider_reference' => (string) ($data['providerTransactionId'] ?? ''),
        ];
    }

    /** @return array<string,string> */
    private function credentials(string $environment, ?array $provided = null): array
    {
        if (is_array($provided) && $provided !== []) {
            return $provided;
        }
        try {
            $managed = ProviderCredentialService::resolvePlatform(Database::getConnection(), $this->slug, $environment);
            if (is_array($managed) && $managed !== []) {
                return $managed;
            }
        } catch (\Throwable) {
        }
        $token = ProviderConfig::credential($this->slug, 'API_TOKEN', $environment);
        return $token === null ? [] : ['api_token' => $token];
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $path, string $token, string $body = ''): array
    {
        $url = rtrim(ProviderConfig::baseUrl($this->slug, ProviderConfig::activeEnvironment($this->slug)), '/') . $path;
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
        }
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Initialisation HTTP pawaPay impossible.');
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
            throw new RuntimeException($errno === CURLE_OPERATION_TIMEOUTED ? 'pawaPay timeout.' : 'pawaPay réseau indisponible.');
        }
        return ['status' => $status, 'body' => (string) $response];
    }

    private static function pawaAmount(string $amount): string
    {
        if ($amount === '' || !preg_match('/^\d+(?:\.\d+)?$/', $amount) || bccomp($amount, '0', 8) <= 0) {
            return '';
        }
        $normalized = rtrim(rtrim(bcadd($amount, '0', 4), '0'), '.');
        return $normalized === '' ? '0' : $normalized;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
