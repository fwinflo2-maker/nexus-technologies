<?php

declare(strict_types=1);

namespace Nexus\Providers;

use RuntimeException;

/**
 * Client HTTP OpenCorporates (v0.4).
 *
 * Doc : https://api.opencorporates.com/documentation/API-Reference
 * Auth : `api_token` en query (obligatoire — sans token → 503 chez OC).
 *
 * Ce client ne fait QUE des lectures registre. Il ne remplace pas une KYB
 * réglementaire complète (représentants / UBO / liveness) : Sumsub Company
 * reste le chemin préféré quand un level KYB est configuré.
 */
final class OpenCorporatesClient
{
    private const BASE = 'https://api.opencorporates.com/v0.4';

    /** @var callable(string,string,array<string,string>):array{status:int,body:string}|null */
    private $transport;

    /**
     * @param callable(string,string,array<string,string>):array{status:int,body:string}|null $transport
     *        Test double : (method, url, headers) → {status, body}
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        return $this->apiToken() !== null;
    }

    public function apiToken(): ?string
    {
        $t = trim((string) (getenv('OPENCORPORATES_API_TOKEN') ?: ''));
        return $t !== '' ? $t : null;
    }

    /**
     * GET /companies/search
     *
     * @param array{q?:string,jurisdiction_code?:string,country_code?:string,inactive?:string,per_page?:int,page?:int,order?:string} $filters
     * @return array{companies:list<array<string,mixed>>,total_count:int,page:int,per_page:int}
     */
    public function search(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q === '') {
            throw new RuntimeException('OPENCORPORATES_SEARCH_Q_REQUIRED');
        }

        $query = [
            'q'        => $q,
            'per_page' => max(1, min(50, (int) ($filters['per_page'] ?? 10))),
            'page'     => max(1, (int) ($filters['page'] ?? 1)),
        ];
        foreach (['jurisdiction_code', 'country_code', 'inactive', 'order'] as $k) {
            $v = trim((string) ($filters[$k] ?? ''));
            if ($v !== '') {
                $query[$k] = $v;
            }
        }

        $payload = $this->get('/companies/search', $query);
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $rawList = is_array($results['companies'] ?? null) ? $results['companies'] : [];

        $companies = [];
        foreach ($rawList as $row) {
            $company = is_array($row) ? ($row['company'] ?? $row) : null;
            if (!is_array($company)) {
                continue;
            }
            $companies[] = self::normalizeCompany($company);
        }

        return [
            'companies'   => $companies,
            'total_count' => (int) ($results['total_count'] ?? count($companies)),
            'page'        => (int) ($results['page'] ?? $query['page']),
            'per_page'    => (int) ($results['per_page'] ?? $query['per_page']),
        ];
    }

    /**
     * GET /companies/:jurisdiction_code/:company_number
     *
     * @return array<string,mixed>
     */
    public function getCompany(string $jurisdictionCode, string $companyNumber): array
    {
        $j = strtolower(trim($jurisdictionCode));
        $n = trim($companyNumber);
        if ($j === '' || $n === '') {
            throw new RuntimeException('OPENCORPORATES_COMPANY_ID_REQUIRED');
        }

        $payload = $this->get('/companies/' . rawurlencode($j) . '/' . rawurlencode($n), []);
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $company = is_array($results['company'] ?? null) ? $results['company'] : null;
        if ($company === null) {
            throw new RuntimeException('OPENCORPORATES_COMPANY_NOT_FOUND');
        }

        return self::normalizeCompany($company);
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public static function normalizeCompany(array $company): array
    {
        $inactive = $company['inactive'] ?? null;
        $status = trim((string) ($company['current_status'] ?? ''));

        return [
            'name'                 => (string) ($company['name'] ?? ''),
            'company_number'       => (string) ($company['company_number'] ?? ''),
            'jurisdiction_code'    => (string) ($company['jurisdiction_code'] ?? ''),
            'incorporation_date'   => $company['incorporation_date'] ?? null,
            'dissolution_date'     => $company['dissolution_date'] ?? null,
            'company_type'         => $company['company_type'] ?? null,
            'current_status'       => $status !== '' ? $status : null,
            'inactive'             => is_bool($inactive) ? $inactive : ($inactive === 'true' || $inactive === 1 || $inactive === '1'),
            'registered_address'   => $company['registered_address_in_full']
                ?? (is_array($company['registered_address'] ?? null)
                    ? ($company['registered_address']['street_address'] ?? null)
                    : null),
            'opencorporates_url'   => (string) ($company['opencorporates_url'] ?? ''),
            'registry_url'         => $company['registry_url'] ?? null,
        ];
    }

    /**
     * @param array<string,scalar|null> $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query): array
    {
        $token = $this->apiToken();
        if ($token === null) {
            throw new RuntimeException('OPENCORPORATES_NOT_CONFIGURED');
        }

        $query['api_token'] = $token;
        $url = self::BASE . $path . '?' . http_build_query($query);

        $response = $this->request('GET', $url);
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('OPENCORPORATES_AUTH_FAILED');
        }
        if ($status === 404) {
            throw new RuntimeException('OPENCORPORATES_COMPANY_NOT_FOUND');
        }
        if ($status === 429) {
            throw new RuntimeException('OPENCORPORATES_RATE_LIMIT');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('OPENCORPORATES_HTTP_' . $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OPENCORPORATES_INVALID_JSON');
        }

        return $decoded;
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $url): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, [
                'Accept'     => 'application/json',
                'User-Agent' => 'NexusTechnologies/1.0 (+opencorporates)',
            ]);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('OPENCORPORATES_CURL_INIT');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: NexusTechnologies/1.0 (+opencorporates)',
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('OPENCORPORATES_NETWORK: ' . $err);
        }

        return ['status' => $status, 'body' => (string) $raw];
    }
}
