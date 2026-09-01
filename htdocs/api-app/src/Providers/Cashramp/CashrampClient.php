<?php

declare(strict_types=1);

namespace Nexus\Providers\Cashramp;

use RuntimeException;

/**
 * CashrampClient — client GraphQL centralisé (docs.cashramp.co).
 *
 * Auth : Bearer secret key (CSHRMP-SECK_…)
 * Staging : https://staging.api.useaccrue.com/cashramp/api/graphql
 * Production : https://api.useaccrue.com/cashramp/api/graphql
 */
final class CashrampClient
{
    private const TIMEOUT_SECONDS         = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** @var null|callable(string,string,array<string,string>,string):array{status:int,body:string}> */
    private $transport;

    /**
     * @param null|callable(string,string,array<string,string>,string):array{status:int,body:string}> $transport
     */
    public function __construct(
        private readonly string $environment,
        private readonly string $secretKey,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public static function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://api.useaccrue.com/cashramp/api/graphql'
            : 'https://staging.api.useaccrue.com/cashramp/api/graphql';
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = [], ?string $requestId = null): array
    {
        $payload = json_encode(
            ['query' => $query, 'variables' => $variables],
            JSON_THROW_ON_ERROR
        );

        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        if ($requestId !== null && $requestId !== '') {
            $headers['X-Request-Id'] = $requestId;
        }

        $response = $this->request(self::baseUrl($this->environment), 'POST', $headers, $payload);

        if ($response['status'] === 401) {
            throw new CashrampException('Cashramp authentication failed (401).', 'INVALID_CREDENTIALS');
        }
        if ($response['status'] >= 500) {
            throw new CashrampException('Cashramp API unavailable (HTTP ' . $response['status'] . ').', 'PROVIDER_UNAVAILABLE');
        }
        if ($response['status'] >= 400) {
            throw new CashrampException('Cashramp request rejected (HTTP ' . $response['status'] . ').', 'PROVIDER_ERROR');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new CashrampException('Invalid Cashramp JSON response.', 'PROVIDER_ERROR');
        }

        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $message = (string) ($decoded['errors'][0]['message'] ?? 'Cashramp GraphQL error.');
            throw new CashrampException($message, 'GRAPHQL_ERROR', $decoded['errors']);
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    public function testAccountConnection(?string $requestId = null): array
    {
        return $this->graphql(
            'query CashrampConnectionTest { account { id accountBalance } }',
            [],
            $requestId
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createCustomer(array $input, ?string $requestId = null): array
    {
        $mutation = <<<'GQL'
mutation CreateCustomer($email: String!, $firstName: String!, $lastName: String!, $country: ID!) {
  createCustomer(email: $email, firstName: $firstName, lastName: $lastName, country: $country) {
    id email firstName lastName
  }
}
GQL;

        return $this->graphql($mutation, $input, $requestId)['createCustomer'] ?? [];
    }

    public function merchantCustomerByEmail(string $email, ?string $requestId = null): ?array
    {
        $query = <<<'GQL'
query MerchantCustomer($email: String!) {
  merchantCustomer(email: $email) { id email firstName lastName }
}
GQL;

        $data = $this->graphql($query, ['email' => $email], $requestId);

        return is_array($data['merchantCustomer'] ?? null) ? $data['merchantCustomer'] : null;
    }

    public function requestVirtualBankAccount(string $customerId, ?string $requestId = null): array
    {
        $mutation = <<<'GQL'
mutation RequestVirtualBankAccount($customerId: ID!) {
  requestVirtualBankAccount(customerId: $customerId) { id status }
}
GQL;

        return $this->graphql($mutation, ['customerId' => $customerId], $requestId)['requestVirtualBankAccount'] ?? [];
    }

    public function virtualBankAccount(string $id, ?string $requestId = null): array
    {
        $query = <<<'GQL'
query VirtualBankAccount($id: ID!) {
  virtualBankAccount(id: $id) {
    id accountName accountNumber bankName city country line1 postalCode state routingNumber status createdAt
  }
}
GQL;

        return $this->graphql($query, ['id' => $id], $requestId)['virtualBankAccount'] ?? [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function rampQuote(array $input, ?string $requestId = null): array
    {
        $query = <<<'GQL'
query RampQuote(
  $customer: ID!, $amount: Decimal!, $currency: P2PPaymentCurrency!, $paymentType: PaymentType,
  $paymentMethodType: String!, $country: String
) {
  rampQuote(
    customer: $customer, amount: $amount, currency: $currency, paymentType: $paymentType,
    paymentMethodType: $paymentMethodType, country: $country
  ) { id exchangeRate paymentType }
}
GQL;

        return $this->graphql($query, $input, $requestId)['rampQuote'] ?? [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function initiateRampQuoteWithdrawal(array $input, ?string $requestId = null): array
    {
        $mutation = <<<'GQL'
mutation InitiateWithdrawal($rampQuote: ID!, $paymentMethod: ID!, $reference: String) {
  initiateRampQuoteWithdrawal(rampQuote: $rampQuote, paymentMethod: $paymentMethod, reference: $reference) {
    id status agent paymentDetails exchangeRate amountUsd amountLocal
  }
}
GQL;

        return $this->graphql($mutation, $input, $requestId)['initiateRampQuoteWithdrawal'] ?? [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function withdrawOnchain(array $input, ?string $requestId = null): array
    {
        $mutation = <<<'GQL'
mutation WithdrawOnchain($address: String!, $amountUsd: Decimal!, $network: String, $symbol: String, $metadata: JSON) {
  withdrawOnchain(address: $address, amountUsd: $amountUsd, network: $network, symbol: $symbol, metadata: $metadata) {
    id quantity symbol network fee status
  }
}
GQL;

        return $this->graphql($mutation, $input, $requestId)['withdrawOnchain'] ?? [];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private function request(string $url, string $method, array $headers, string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $method, $headers, $body);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $responseBody = curl_exec($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new CashrampException(
                $error !== '' ? $error : 'Cashramp request failed.',
                str_contains(strtolower($error), 'timed out') ? 'TIMEOUT' : 'PROVIDER_UNAVAILABLE'
            );
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
