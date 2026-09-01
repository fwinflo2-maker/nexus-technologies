<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Providers\Cashramp\CashrampClient;
use Nexus\Providers\Cashramp\CashrampException;
use Nexus\Providers\Cashramp\CashrampStatusMapper;
use Nexus\Services\ProviderCredentialService;
use RuntimeException;

/**
 * CashrampAdapter — intégration GraphQL Cashramp (docs.cashramp.co).
 */
final class CashrampAdapter extends AbstractProviderAdapter
{
    /** @var null|callable(string,string,array<string,string>,string):array{status:int,body:string}> */
    private $transport;

    /**
     * @param null|callable(string,string,array<string,string>,string):array{status:int,body:string}> $transport
     */
    public function __construct(?callable $transport = null)
    {
        parent::__construct('cashramp');
        $this->transport = $transport;
    }

    protected function declaredMethods(): array
    {
        return ['bank', 'mobile_money', 'crypto'];
    }

    public function testConnection(string $environment, ?array $credentials = null): array
    {
        $env   = $this->normalizeEnv($environment);
        $creds = $this->resolveCredentials($env, $credentials);
        $secret = trim((string) ($creds['secret_key'] ?? ''));
        if ($secret === '') {
            return [
                'status'    => 'PROVIDER_NOT_CONFIGURED',
                'message'   => 'Secret key Cashramp absent : aucun appel envoyé.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $client = $this->client($env, $secret);
            $data   = $client->testAccountConnection();
            $id     = (string) ($data['account']['id'] ?? '');
            if ($id === '') {
                return [
                    'status'    => 'CONFIGURATION_ERROR',
                    'message'   => 'Réponse Cashramp inattendue (account.id absent).',
                    'tested_at' => gmdate(DATE_ATOM),
                ];
            }

            return [
                'status'    => 'CONNECTION_SUCCESS',
                'message'   => 'Connexion Cashramp authentifiée.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        } catch (CashrampException $e) {
            return [
                'status'    => match ($e->errorCode) {
                    'INVALID_CREDENTIALS' => 'INVALID_CREDENTIALS',
                    'TIMEOUT'             => 'TIMEOUT',
                    'PROVIDER_UNAVAILABLE'=> 'PROVIDER_UNAVAILABLE',
                    default               => 'CONFIGURATION_ERROR',
                },
                'message'   => $e->getMessage(),
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }
    }

    /**
     * @param array{email:string,firstName:string,lastName:string,countryId:string} $input
     * @return array<string,mixed>
     */
    public function createCustomer(array $input, string $environment, ?array $credentials = null): array
    {
        $client = $this->authenticatedClient($environment, $credentials);

        return $client->createCustomer([
            'email'     => $input['email'],
            'firstName' => $input['firstName'],
            'lastName'  => $input['lastName'],
            'country'   => $input['countryId'],
        ]);
    }

    public function getCustomerByEmail(string $email, string $environment, ?array $credentials = null): ?array
    {
        $client = $this->authenticatedClient($environment, $credentials);

        return $client->merchantCustomerByEmail($email);
    }

    public function requestVirtualBankAccount(string $customerId, string $environment, ?array $credentials = null): array
    {
        $client = $this->authenticatedClient($environment, $credentials);

        return $client->requestVirtualBankAccount($customerId);
    }

    public function getVirtualBankAccount(string $accountId, string $environment, ?array $credentials = null): array
    {
        $client = $this->authenticatedClient($environment, $credentials);

        return $client->virtualBankAccount($accountId);
    }

    public function getBalance(?string $environment = null, ?array $credentials = null): array
    {
        $env    = $this->normalizeEnv($environment ?? ProviderConfig::activeEnvironment($this->slug));
        $client = $this->authenticatedClient($env, $credentials);
        $data   = $client->testAccountConnection();
        $balance = (string) ($data['account']['accountBalance'] ?? '0');

        return [
            'currency'     => 'USD',
            'available'    => $balance,
            'pending'      => '0',
            'updated_at'   => gmdate(DATE_ATOM),
            'provider_ref' => (string) ($data['account']['id'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $intent
     */
    public function getQuote(array $intent): array
    {
        $environment = (string) ($intent['environment'] ?? ProviderConfig::activeEnvironment($this->slug));
        $credentials = is_array($intent['credentials'] ?? null) ? $intent['credentials'] : null;
        $client      = $this->authenticatedClient($environment, $credentials);

        $quote = $client->rampQuote([
            'customer'          => (string) ($intent['customer_id'] ?? ''),
            'amount'            => (float) ($intent['amount'] ?? 0),
            'currency'          => (string) ($intent['currency'] ?? 'usd'),
            'paymentType'       => (string) ($intent['payment_type'] ?? 'withdrawal'),
            'paymentMethodType' => (string) ($intent['payment_method_type'] ?? ''),
            'country'           => $intent['country'] ?? null,
        ]);

        return [
            'provider'       => $this->slug,
            'quote_id'       => (string) ($quote['id'] ?? ''),
            'exchange_rate'  => (string) ($quote['exchangeRate'] ?? ''),
            'payment_type'   => (string) ($quote['paymentType'] ?? ''),
            'fee_source'     => 'provider_native',
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    public function createPayment(array $params): array
    {
        $environment = (string) ($params['environment'] ?? ProviderConfig::activeEnvironment($this->slug));
        $credentials = is_array($params['credentials'] ?? null) ? $params['credentials'] : null;
        $client      = $this->authenticatedClient($environment, $credentials);

        $result = $client->initiateRampQuoteWithdrawal([
            'rampQuote'     => (string) ($params['ramp_quote_id'] ?? ''),
            'paymentMethod' => (string) ($params['payment_method_id'] ?? ''),
            'reference'     => (string) ($params['operation_id'] ?? $params['reference'] ?? ''),
        ]);

        $status = CashrampStatusMapper::mapPaymentRequest((string) ($result['status'] ?? 'accepted'));

        return [
            'id'     => (string) ($result['id'] ?? ''),
            'status' => strtoupper((string) ($result['status'] ?? 'ACCEPTED')),
            'mapped_status' => $status,
            'provider_ref'  => (string) ($result['id'] ?? ''),
            'raw'    => $result,
        ];
    }

    public function getPaymentStatus(string $paymentId): array
    {
        throw new ProviderOperationNotImplemented($this->slug, 'getPaymentStatus');
    }

    /**
     * Cashramp webhooks : header X-CASHRAMP-TOKEN (docs.cashramp.co).
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        $environment = ProviderConfig::activeEnvironment($this->slug);
        $token       = $this->webhookToken($environment);
        if ($token === '') {
            return false;
        }

        return hash_equals($token, trim($signature));
    }

    /**
     * @param array<string,mixed> $input
     */
    public function withdrawOnchain(array $input, string $environment, ?array $credentials = null): array
    {
        $client = $this->authenticatedClient($environment, $credentials);

        return $client->withdrawOnchain([
            'address'   => (string) ($input['address'] ?? ''),
            'amountUsd' => (float) ($input['amount_usd'] ?? 0),
            'network'   => $input['network'] ?? null,
            'symbol'    => $input['symbol'] ?? null,
            'metadata'  => $input['metadata'] ?? null,
        ]);
    }

    /** @return array<string,mixed> */
    public function normalizeWebhookPayload(array $payload): array
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        $data      = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $kind      = CashrampStatusMapper::mapWebhookEventType($eventType);

        $providerStatus = (string) ($data['status'] ?? '');
        $mapped         = $kind === 'onchain_withdrawal' || $kind === 'onchain_deposit'
            ? CashrampStatusMapper::mapOnchain($providerStatus)
            : CashrampStatusMapper::mapPaymentRequest($providerStatus);

        return [
            'event_type'        => $eventType,
            'event_kind'        => $kind,
            'provider_id'       => (string) ($data['id'] ?? ''),
            'provider_status'   => $providerStatus,
            'mapped_status'     => $mapped,
            'reference'         => (string) ($data['reference'] ?? ($data['metadata']['payoutId'] ?? '')),
        ];
    }

    private function authenticatedClient(string $environment, ?array $credentials): CashrampClient
    {
        $env   = $this->normalizeEnv($environment);
        $creds = $this->resolveCredentials($env, $credentials);
        $secret = trim((string) ($creds['secret_key'] ?? ''));
        if ($secret === '') {
            throw new HttpException(409, 'Cashramp non configuré pour cet environnement.', 'PROVIDER_NOT_CONFIGURED');
        }

        return $this->client($env, $secret);
    }

    private function client(string $environment, string $secretKey): CashrampClient
    {
        return new CashrampClient($environment, $secretKey, $this->transport);
    }

    /** @return array<string,string> */
    private function resolveCredentials(string $environment, ?array $credentials): array
    {
        if ($credentials !== null && ($credentials['secret_key'] ?? '') !== '') {
            return $credentials;
        }

        try {
            $row = ProviderCredentialService::findPlatformRow(Database::getConnection(), $this->slug, $environment);
            if ($row !== null) {
                $resolved = ProviderCredentialService::resolvePlatform(Database::getConnection(), $this->slug, $environment);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        } catch (\Throwable) {
            // fallback env
        }

        $secret = ProviderConfig::credential($this->slug, 'secret_key', $environment);
        $public = ProviderConfig::credential($this->slug, 'public_key', $environment);
        $webhook = ProviderConfig::credential($this->slug, 'webhook_token', $environment);

        return array_filter([
            'secret_key'    => $secret,
            'public_key'    => $public,
            'webhook_token' => $webhook,
        ], static fn ($v) => is_string($v) && $v !== '');
    }

    private function webhookToken(string $environment): string
    {
        $creds = $this->resolveCredentials($environment, null);

        return trim((string) ($creds['webhook_token'] ?? ''));
    }

    private function normalizeEnv(string $environment): string
    {
        return $environment === 'production' ? 'production' : 'sandbox';
    }
}
