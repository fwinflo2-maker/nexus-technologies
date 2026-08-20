<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Services\ProviderCredentialService;
use RuntimeException;

/**
 * Stripe Issuing — émission de cartes virtuelles.
 *
 * Documentation officielle :
 *   - Create cardholder : https://docs.stripe.com/api/issuing/cardholders/create
 *   - Create card (virtual) : https://docs.stripe.com/api/issuing/cards/create
 *   - Issue virtual cards : https://docs.stripe.com/issuing/cards/virtual/issue-cards
 *
 * Credentials : `secret_key` (sk_test_… / sk_live_…) sur le slug `stripe_issuing`,
 * avec repli optionnel sur la clé `stripe` du même compte (même secret Stripe).
 *
 * HONNÊTETÉ : aucun PAN/CVV n'est stocké. L'API Stripe renvoie last4 / brand /
 * status ; le numéro complet n'est accessible que via Issuing Elements / clés
 * éphémères (hors périmètre).
 */
final class StripeIssuingAdapter extends AbstractProviderAdapter
{
    /** Devises Issuing courantes (doc Stripe) — XAF non supporté. */
    public const SUPPORTED_CURRENCIES = ['EUR', 'USD', 'GBP'];

    /** @var null|callable(string,string,array,string):array{status:int,body:string} */
    private $transport;

    /**
     * @param null|callable(string,string,array,string):array{status:int,body:string} $transport
     */
    public function __construct(?callable $transport = null)
    {
        parent::__construct('stripe_issuing');
        $this->transport = $transport;
    }

    protected function declaredMethods(): array
    {
        return ['card_issuing'];
    }

    /**
     * Sonde réelle : GET /v1/issuing/cardholders?limit=1
     * Confirme que la clé est valide ET que Stripe Issuing est activé.
     */
    public function testConnection(string $environment, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        $secret = $this->secretKey($env, $credentials);
        if ($secret === null || $secret === '') {
            return [
                'status'    => 'PROVIDER_NOT_CONFIGURED',
                'message'   => 'Clé secrète Stripe Issuing absente : aucun appel envoyé.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $res = $this->request('GET', '/issuing/cardholders?limit=1', $secret, '', $env);
        } catch (RuntimeException $e) {
            $msg = strtolower($e->getMessage());
            return [
                'status'    => str_contains($msg, 'timeout') ? 'TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'message'   => 'API Stripe Issuing injoignable.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        $code = (int) ($res['status'] ?? 0);
        $body = (string) ($res['body'] ?? '');
        $errCode = $this->stripeErrorCode($body);

        return match (true) {
            $code === 200 => [
                'status'    => 'CONNECTION_SUCCESS',
                'message'   => 'Stripe Issuing joignable : la clé est authentifiée.',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $code === 401 => [
                'status'    => 'INVALID_CREDENTIALS',
                'message'   => 'Clé secrète Stripe rejetée (401).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            $code === 403 => [
                'status'    => 'UNAUTHORIZED',
                'message'   => 'Clé sans permission Issuing (403).',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            // Compte sans produit Issuing activé.
            $code === 400 && in_array($errCode, ['issuing_not_enabled', 'resource_missing'], true) => [
                'status'    => 'CONFIGURATION_ERROR',
                'message'   => 'Stripe Issuing non activé sur ce compte.',
                'tested_at' => gmdate(DATE_ATOM),
            ],
            default => [
                'status'    => 'CONFIGURATION_ERROR',
                'message'   => 'Réponse inattendue de Stripe Issuing (HTTP ' . $code . ').',
                'tested_at' => gmdate(DATE_ATOM),
            ],
        };
    }

    /**
     * Émet une carte virtuelle Stripe Issuing.
     *
     * @param array{
     *   full_name?:string|null,
     *   email?:string|null,
     *   phone?:string|null,
     *   address?:string|null,
     *   city?:string|null,
     *   postal_code?:string|null,
     *   country_of_residence?:string|null
     * } $holder
     * @param array{currency:string,label?:string,spend_limit?:float|null} $opts
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
        $secret = $this->secretKey($env, $credentials);
        if ($secret === null || $secret === '') {
            throw new RuntimeException('CREDENTIALS_NOT_CONFIGURED');
        }

        $currency = strtoupper(trim((string) ($opts['currency'] ?? '')));
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new RuntimeException('CURRENCY_NOT_SUPPORTED_BY_ISSUER');
        }

        $name = $this->sanitizeCardholderName((string) ($holder['full_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('CARDHOLDER_NAME_REQUIRED');
        }

        $country = strtoupper(trim((string) ($holder['country_of_residence'] ?? '')));
        if (strlen($country) !== 2) {
            throw new RuntimeException('CARDHOLDER_COUNTRY_REQUIRED');
        }

        $line1 = trim((string) ($holder['address'] ?? ''));
        $city = trim((string) ($holder['city'] ?? ''));
        $postal = trim((string) ($holder['postal_code'] ?? ''));
        if ($line1 === '' || $city === '' || $postal === '') {
            throw new RuntimeException('CARDHOLDER_ADDRESS_REQUIRED');
        }

        $cardholderFields = [
            'type' => 'individual',
            'name' => $name,
            'billing' => [
                'address' => [
                    'line1'       => mb_substr($line1, 0, 200),
                    'city'        => mb_substr($city, 0, 100),
                    'postal_code' => mb_substr($postal, 0, 20),
                    'country'     => $country,
                ],
            ],
        ];
        $email = trim((string) ($holder['email'] ?? ''));
        if ($email !== '') {
            $cardholderFields['email'] = $email;
        }
        $phone = trim((string) ($holder['phone'] ?? ''));
        if ($phone !== '') {
            $cardholderFields['phone_number'] = $phone;
        }

        $chRes = $this->request('POST', '/issuing/cardholders', $secret, http_build_query($this->flatten($cardholderFields)), $env);
        if ((int) $chRes['status'] < 200 || (int) $chRes['status'] >= 300) {
            throw new RuntimeException($this->mapHttpFailure((int) $chRes['status'], (string) $chRes['body']));
        }
        $chData = json_decode((string) $chRes['body'], true);
        $cardholderId = is_array($chData) ? (string) ($chData['id'] ?? '') : '';
        if ($cardholderId === '') {
            throw new RuntimeException('ISSUER_INVALID_RESPONSE');
        }

        $cardFields = [
            'cardholder' => $cardholderId,
            'currency'   => strtolower($currency),
            'type'       => 'virtual',
            'status'     => 'active',
        ];
        $label = trim((string) ($opts['label'] ?? ''));
        if ($label !== '') {
            $cardFields['metadata'] = ['nexus_label' => mb_substr($label, 0, 100)];
        }
        $spendLimit = $opts['spend_limit'] ?? null;
        if (is_numeric($spendLimit) && (float) $spendLimit > 0) {
            // Stripe Issuing : amount en plus petite unité (centimes).
            $amountMinor = (int) round(((float) $spendLimit) * 100);
            $cardFields['spending_controls'] = [
                'spending_limits' => [
                    [
                        'amount'   => $amountMinor,
                        'interval' => 'monthly',
                    ],
                ],
            ];
        }

        $cardRes = $this->request('POST', '/issuing/cards', $secret, http_build_query($this->flatten($cardFields)), $env);
        if ((int) $cardRes['status'] < 200 || (int) $cardRes['status'] >= 300) {
            throw new RuntimeException($this->mapHttpFailure((int) $cardRes['status'], (string) $cardRes['body']));
        }
        $cardData = json_decode((string) $cardRes['body'], true);
        if (!is_array($cardData) || empty($cardData['id'])) {
            throw new RuntimeException('ISSUER_INVALID_RESPONSE');
        }

        $stripeStatus = strtolower((string) ($cardData['status'] ?? ''));
        $nexusStatus = match ($stripeStatus) {
            'active' => 'active',
            'inactive', 'canceled' => 'frozen',
            default => 'pending_issuer',
        };

        return [
            'issuer_ref'     => (string) $cardData['id'],
            'cardholder_id'  => $cardholderId,
            'last4'          => isset($cardData['last4']) ? (string) $cardData['last4'] : null,
            'brand'          => isset($cardData['brand']) ? (string) $cardData['brand'] : null,
            'status'         => $nexusStatus,
            'currency'       => strtoupper((string) ($cardData['currency'] ?? $currency)),
        ];
    }

    /**
     * True si des credentials exploitables existent (stripe_issuing ou repli stripe).
     */
    public function hasCredentials(string $environment): bool
    {
        $secret = $this->secretKey($environment === 'production' ? 'production' : 'sandbox', null);
        return is_string($secret) && $secret !== '';
    }

    private function secretKey(string $environment, ?array $provided): ?string
    {
        if (is_array($provided)) {
            $v = trim((string) ($provided['secret_key'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        foreach ([$this->slug, 'stripe'] as $slug) {
            try {
                $managed = ProviderCredentialService::resolvePlatform(Database::getConnection(), $slug, $environment);
                if (is_array($managed)) {
                    $v = trim((string) ($managed['secret_key'] ?? ''));
                    if ($v !== '') {
                        return $v;
                    }
                }
            } catch (\Throwable) {
            }
            $envVal = ProviderConfig::credential($slug, 'SECRET_KEY', $environment);
            if (is_string($envVal) && $envVal !== '') {
                return $envVal;
            }
        }

        return null;
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $path, string $secret, string $body, string $environment): array
    {
        $base = rtrim(ProviderConfig::baseUrl($this->slug, $environment), '/');
        if ($base === '') {
            $base = 'https://api.stripe.com/v1';
        }
        $url = $base . $path;
        $headers = [
            'Authorization: Bearer ' . $secret,
            'Accept: application/json',
        ];
        if ($body !== '' && strtoupper($method) !== 'GET') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Initialisation HTTP Stripe Issuing impossible.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body !== '' ? $body : null,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
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

    /** Aplatit un tableau imbriqué au format form Stripe (a[b][c]=…). */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $k = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                // Liste indexée (spending_limits[0][amount])
                $isList = array_keys($value) === range(0, count($value) - 1);
                if ($isList) {
                    foreach ($value as $i => $item) {
                        if (is_array($item)) {
                            $out += $this->flatten($item, $k . '[' . $i . ']');
                        } else {
                            $out[$k . '[' . $i . ']'] = (string) $item;
                        }
                    }
                } else {
                    $out += $this->flatten($value, $k);
                }
            } else {
                $out[$k] = (string) $value;
            }
        }
        return $out;
    }

    private function sanitizeCardholderName(string $fullName): string
    {
        // Doc Stripe : max 24, pas de chiffres ni caractères spéciaux.
        $clean = preg_replace('/[^A-Za-zÀ-ÖØ-öø-ÿ\'\-\s]/u', '', trim($fullName)) ?? '';
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
        return mb_substr(trim($clean), 0, 24);
    }

    private function stripeErrorCode(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return '';
        }
        $err = $data['error'] ?? null;
        return is_array($err) ? (string) ($err['code'] ?? $err['type'] ?? '') : '';
    }

    private function mapHttpFailure(int $status, string $body): string
    {
        if ($status === 401) {
            return 'INVALID_CREDENTIALS';
        }
        if ($status === 403) {
            return 'UNAUTHORIZED';
        }
        $code = $this->stripeErrorCode($body);
        if ($code === 'issuing_not_enabled') {
            return 'ISSUING_NOT_ENABLED';
        }
        // Message court sans secret — jamais le body Stripe brut en prod.
        return 'ISSUER_HTTP_' . $status;
    }
}
