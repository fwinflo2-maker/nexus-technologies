<?php

declare(strict_types=1);

namespace Nexus\Providers;

use Nexus\Core\Database;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\ProviderCredentialService;
use Throwable;

/**
 * ProviderAuthProbe — branchement adaptatif des credentials.
 *
 * Quand un opérateur saisit des clés dans le SuperAdmin, ce moteur :
 *  1. résout les credentials plateforme (ou celles fournies au test) ;
 *  2. construit l'auth selon le profil officiel du provider ;
 *  3. exécute une sonde HTTP réelle (jamais un faux succès).
 *
 * Utilisé par ConfigDrivenProviderAdapter. Les adaptateurs dédiés
 * (pawaPay, Stripe, …) gardent leur propre testConnection.
 */
final class ProviderAuthProbe
{
    /**
     * Profils d'auth + chemin de sonde.
     *
     * @var array<string, array{
     *   mode: string,
     *   path: string,
     *   method?: string,
     *   body?: string,
     *   content_type?: string,
     *   ok_codes?: list<int>,
     *   user_key?: string,
     *   pass_key?: string,
     *   token_key?: string,
     *   extra_headers?: array<string, string>
     * }>
     */
    private const PROFILES = [
        // Basic api_key:api_secret → GET /ping (Thunes Money Transfer v2)
        'thunes' => [
            'mode' => 'basic',
            'path' => '/ping',
            'user_key' => 'api_key',
            'pass_key' => 'api_secret',
            'ok_codes' => [200],
        ],
        // MTN MoMo : OAuth token via Basic api_user:api_key + subscription key
        'mtn_momo' => [
            'mode' => 'mtn_token',
            'path' => '/collection/token/',
            'method' => 'POST',
            'ok_codes' => [200],
        ],
        // Daraja : OAuth consumer_key:consumer_secret
        'safaricom_mpesa' => [
            'mode' => 'mpesa_oauth',
            'path' => '/oauth/v1/generate?grant_type=client_credentials',
            'ok_codes' => [200],
        ],
        // Orange Money OAuth client credentials (sonde token)
        'orange_money' => [
            'mode' => 'oauth_basic',
            'path' => '/oauth/token',
            'method' => 'POST',
            'body' => 'grant_type=client_credentials',
            'content_type' => 'application/x-www-form-urlencoded',
            'user_key' => 'client_id',
            'pass_key' => 'client_secret',
            'ok_codes' => [200],
        ],
        // Currencycloud login → auth_token
        'currencycloud' => [
            'mode' => 'currencycloud_login',
            'path' => '/authenticate/api',
            'method' => 'POST',
            'ok_codes' => [200],
        ],
        // Wise client credentials (mTLS peut manquer → 401/403 honnêtes)
        'wise' => [
            'mode' => 'oauth_basic',
            'path' => '/v1/oauth/token',
            'method' => 'POST',
            'body' => 'grant_type=client_credentials',
            'content_type' => 'application/x-www-form-urlencoded',
            'user_key' => 'client_id',
            'pass_key' => 'client_secret',
            'ok_codes' => [200],
        ],
        // MoneyGram OAuth2 client credentials (GET + Basic) — doc o-auth-api
        'moneygram' => [
            'mode' => 'oauth_basic',
            'path' => '/oauth/accesstoken?grant_type=client_credentials',
            'method' => 'GET',
            'content_type' => 'application/json',
            'user_key' => 'client_id',
            'pass_key' => 'client_secret',
            'ok_codes' => [200],
        ],
        // Marqeta Basic application_token:admin_access_token
        'marqeta' => [
            'mode' => 'basic',
            'path' => '/users?count=1',
            'user_key' => 'application_token',
            'pass_key' => 'admin_access_token',
            'ok_codes' => [200],
        ],
        'maplerad' => [
            'mode' => 'bearer',
            'path' => '/wallets',
            'token_key' => 'secret_key',
            'ok_codes' => [200, 401, 403],
        ],
        // Xendit Basic secret_key:
        'xendit' => [
            'mode' => 'basic_user_only',
            'path' => '/balance',
            'user_key' => 'secret_key',
            'ok_codes' => [200],
        ],
        // Tazapay Basic api_key:api_secret
        'tazapay' => [
            'mode' => 'basic',
            'path' => '/v1/metadata/country',
            'user_key' => 'api_key',
            'pass_key' => 'api_secret',
            'ok_codes' => [200, 201],
        ],
        // EBANX — sonde légère avec integration_key en query (doc historique)
        'ebanx' => [
            'mode' => 'ebanx_query',
            'path' => '/ws/query',
            'method' => 'POST',
            'content_type' => 'application/json',
            'ok_codes' => [200],
        ],
        // Nium — headers client credentials (profil générique)
        'nium' => [
            'mode' => 'nium_headers',
            'path' => '/v1/client/{client_id}',
            'ok_codes' => [200, 401, 403, 404],
        ],
        // Modulr HMAC (api_key + secret) — sonde accounts
        'modulr' => [
            'mode' => 'modulr_hmac',
            'path' => '/api-v1.0/customers',
            'ok_codes' => [200, 401, 403],
        ],
        // Bridge Bearer api_key
        'bridge' => [
            'mode' => 'bearer',
            'path' => '/v0/customers',
            'token_key' => 'api_key',
            'ok_codes' => [200, 401, 403],
        ],
        // Yellow Card / Onafriq / Noah / Cashramp — Bearer / Basic générique
        'yellow_card' => [
            'mode' => 'bearer',
            'path' => '/business/channels',
            'token_key' => 'api_key',
            'ok_codes' => [200, 401, 403],
        ],
        'onfriq' => [
            'mode' => 'basic',
            'path' => '/health',
            'user_key' => 'api_key',
            'pass_key' => 'api_secret',
            'ok_codes' => [200, 401, 403, 404],
        ],
        'noah' => [
            'mode' => 'basic',
            'path' => '/v1/balances',
            'user_key' => 'api_key',
            'pass_key' => 'api_secret',
            'ok_codes' => [200, 401, 403],
        ],
        'cashramp' => [
            'mode' => 'bearer',
            'path' => '/merchant',
            'token_key' => 'secret_key',
            'ok_codes' => [200, 401, 403],
        ],
        // dLocal — X-Login + X-Trans-Key + HMAC Date
        'dlocal' => [
            'mode' => 'dlocal_hmac',
            'path' => '/payments',
            'ok_codes' => [200, 401, 403],
        ],
        // Swan OAuth
        'swan' => [
            'mode' => 'oauth_basic',
            'path' => '/oauth2/token',
            'method' => 'POST',
            'body' => 'grant_type=client_credentials',
            'content_type' => 'application/x-www-form-urlencoded',
            'user_key' => 'client_id',
            'pass_key' => 'client_secret',
            'ok_codes' => [200],
        ],
    ];

    public static function supports(string $slug): bool
    {
        return isset(self::PROFILES[$slug]) || $slug === 'sumsub';
    }

    /** @return list<string> */
    public static function supportedSlugs(): array
    {
        return array_keys(self::PROFILES);
    }

    /**
     * @param array<string,string>|null $credentials
     * @return array{status:string,message:string,tested_at:string}
     */
    public static function test(string $slug, string $environment, ?array $credentials = null): array
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        if ($slug === 'sumsub') {
            $creds = self::resolveCredentials($slug, $env, $credentials);
            return (new \Nexus\Kyc\SumsubAdapter())->testConnection(
                $env,
                $creds !== [] ? $creds : null
            );
        }
        $profile = self::PROFILES[$slug] ?? null;
        if ($profile === null) {
            return [
                'status'    => 'CONFIGURATION_ERROR',
                'message'   => 'Aucune sonde d\'authentification définie pour ce provider.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        $creds = self::resolveCredentials($slug, $env, $credentials);
        if ($creds === []) {
            return [
                'status'    => 'PROVIDER_NOT_CONFIGURED',
                'message'   => 'Credentials absentes : aucun appel envoyé.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        if (($profile['mode'] ?? '') === 'credentials_present') {
            return [
                'status'    => 'CONFIGURATION_ERROR',
                'message'   => 'Aucune sonde HTTP documentée : credentials stockables mais non testables automatiquement.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $built = self::buildRequest($slug, $env, $profile, $creds);
        } catch (Throwable $e) {
            return [
                'status'    => 'CONFIGURATION_ERROR',
                'message'   => 'Impossible de construire la requête d\'auth : champs incomplets.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        try {
            $res = self::http(
                $built['method'],
                $built['url'],
                $built['headers'],
                $built['body']
            );
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            return [
                'status'    => str_contains($msg, 'timeout') ? 'TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'message'   => 'API provider injoignable.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        $code = (int) ($res['status'] ?? 0);
        $okCodes = $profile['ok_codes'] ?? [200];

        // Pour certains profils, 401/403 dans ok_codes prouve seulement la
        // joignabilité — on les traite comme INVALID_CREDENTIALS / UNAUTHORIZED.
        if (in_array($code, [401], true)) {
            return [
                'status'    => 'INVALID_CREDENTIALS',
                'message'   => 'Authentification rejetée (401).',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }
        if (in_array($code, [403], true)) {
            return [
                'status'    => 'UNAUTHORIZED',
                'message'   => 'Credentials sans permission (403).',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }
        if (in_array($code, $okCodes, true) && $code < 400) {
            return [
                'status'    => 'CONNECTION_SUCCESS',
                'message'   => 'Connexion authentifiée acceptée (HTTP ' . $code . ').',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        // Réponse 2xx hors liste ou 404 sur health → configuration
        if ($code >= 200 && $code < 300) {
            return [
                'status'    => 'CONNECTION_SUCCESS',
                'message'   => 'Réponse HTTP ' . $code . ' — auth acceptée.',
                'tested_at' => gmdate(DATE_ATOM),
            ];
        }

        return [
            'status'    => 'CONFIGURATION_ERROR',
            'message'   => 'Réponse inattendue (HTTP ' . $code . ').',
            'tested_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,string> $creds
     * @return array{method:string,url:string,headers:list<string>,body:string}
     */
    private static function buildRequest(string $slug, string $env, array $profile, array $creds): array
    {
        $base = rtrim(ProviderConfig::baseUrl($slug, $env), '/');
        $path = (string) ($profile['path'] ?? '/');
        $method = strtoupper((string) ($profile['method'] ?? 'GET'));
        $body = (string) ($profile['body'] ?? '');
        $headers = ['Accept: application/json'];

        $mode = (string) ($profile['mode'] ?? '');

        switch ($mode) {
            case 'basic':
                $user = trim((string) ($creds[$profile['user_key'] ?? 'api_key'] ?? ''));
                $pass = trim((string) ($creds[$profile['pass_key'] ?? 'api_secret'] ?? ''));
                if ($user === '' || $pass === '') {
                    throw new \InvalidArgumentException('basic credentials');
                }
                $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
                break;

            case 'basic_user_only':
                $user = trim((string) ($creds[$profile['user_key'] ?? 'secret_key'] ?? ''));
                if ($user === '') {
                    throw new \InvalidArgumentException('secret');
                }
                $headers[] = 'Authorization: Basic ' . base64_encode($user . ':');
                break;

            case 'bearer':
                $token = trim((string) ($creds[$profile['token_key'] ?? 'api_key'] ?? ''));
                if ($token === '') {
                    throw new \InvalidArgumentException('token');
                }
                $headers[] = 'Authorization: Bearer ' . $token;
                break;

            case 'oauth_basic':
                $user = trim((string) ($creds[$profile['user_key'] ?? 'client_id'] ?? ''));
                $pass = trim((string) ($creds[$profile['pass_key'] ?? 'client_secret'] ?? ''));
                if ($user === '' || $pass === '') {
                    throw new \InvalidArgumentException('oauth');
                }
                $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
                if (!empty($profile['content_type'])) {
                    $headers[] = 'Content-Type: ' . $profile['content_type'];
                }
                break;

            case 'mtn_token':
                $sub = trim((string) ($creds['subscription_key'] ?? ''));
                $user = trim((string) ($creds['api_user'] ?? ''));
                $key = trim((string) ($creds['api_key'] ?? ''));
                if ($sub === '' || $user === '' || $key === '') {
                    throw new \InvalidArgumentException('mtn');
                }
                $headers[] = 'Ocp-Apim-Subscription-Key: ' . $sub;
                $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $key);
                $body = '';
                break;

            case 'mpesa_oauth':
                $ck = trim((string) ($creds['consumer_key'] ?? ''));
                $cs = trim((string) ($creds['consumer_secret'] ?? ''));
                if ($ck === '' || $cs === '') {
                    throw new \InvalidArgumentException('mpesa');
                }
                $headers[] = 'Authorization: Basic ' . base64_encode($ck . ':' . $cs);
                break;

            case 'currencycloud_login':
                $login = trim((string) ($creds['login_id'] ?? ''));
                $apiKey = trim((string) ($creds['api_key'] ?? ''));
                if ($login === '' || $apiKey === '') {
                    throw new \InvalidArgumentException('cc');
                }
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $body = http_build_query(['login_id' => $login, 'api_key' => $apiKey]);
                break;

            case 'modulr_hmac':
                $apiKey = trim((string) ($creds['api_key'] ?? ''));
                $secret = trim((string) ($creds['api_secret'] ?? ''));
                if ($apiKey === '' || $secret === '') {
                    throw new \InvalidArgumentException('modulr');
                }
                $date = gmdate('D, d M Y H:i:s T');
                $nonce = bin2hex(random_bytes(8));
                // Doc Modulr : Signature = Base64(HMAC-SHA1(secret, "date: " + Date + "\n" + "x-mod-nonce: " + nonce))
                $signString = "date: {$date}\nx-mod-nonce: {$nonce}";
                $sig = base64_encode(hash_hmac('sha1', $signString, $secret, true));
                $headers[] = 'Authorization: Signature keyId="' . $apiKey . '",algorithm="hmac-sha1",headers="date x-mod-nonce",signature="' . $sig . '"';
                $headers[] = 'Date: ' . $date;
                $headers[] = 'x-mod-nonce: ' . $nonce;
                break;

            case 'dlocal_hmac':
                $login = trim((string) ($creds['api_key'] ?? ''));
                $transKey = trim((string) ($creds['api_token'] ?? ''));
                $secret = trim((string) ($creds['api_secret'] ?? ''));
                if ($login === '' || $transKey === '' || $secret === '') {
                    throw new \InvalidArgumentException('dlocal');
                }
                $date = gmdate('Y-m-d\TH:i:s.\0\0\0\Z');
                // Signature simplifiée : présence des headers — sonde GET-like
                $headers[] = 'X-Login: ' . $login;
                $headers[] = 'X-Trans-Key: ' . $transKey;
                $headers[] = 'X-Date: ' . $date;
                $headers[] = 'Authorization: V2-HMAC-SHA256, Signature: ' . hash_hmac('sha256', $date . $login, $secret);
                break;

            case 'nium_headers':
                $cid = trim((string) ($creds['client_id'] ?? ''));
                $csec = trim((string) ($creds['client_secret'] ?? ''));
                if ($cid === '' || $csec === '') {
                    throw new \InvalidArgumentException('nium');
                }
                $path = str_replace('{client_id}', rawurlencode($cid), $path);
                $headers[] = 'client_id: ' . $cid;
                $headers[] = 'client_secret: ' . $csec;
                break;

            case 'ebanx_query':
                $ikey = trim((string) ($creds['integration_key'] ?? ''));
                if ($ikey === '') {
                    throw new \InvalidArgumentException('ebanx');
                }
                $headers[] = 'Content-Type: application/json';
                $body = json_encode([
                    'integration_key' => $ikey,
                    'hash' => 'nexus-probe-invalid',
                ], JSON_THROW_ON_ERROR);
                // 200 avec status ERROR = clé reconnue ; 403 = rejet
                break;

            default:
                throw new \InvalidArgumentException('mode');
        }

        return [
            'method'  => $method,
            'url'     => $base . $path,
            'headers' => $headers,
            'body'    => $body,
        ];
    }

    /**
     * @param array<string,string>|null $provided
     * @return array<string,string>
     */
    private static function resolveCredentials(string $slug, string $environment, ?array $provided): array
    {
        if (is_array($provided) && $provided !== []) {
            $out = [];
            foreach ($provided as $k => $v) {
                if (is_string($v) && $v !== '') {
                    $out[(string) $k] = $v;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        try {
            $managed = ProviderCredentialService::resolvePlatform(
                Database::getConnection(),
                $slug,
                $environment
            );
            if (is_array($managed) && $managed !== []) {
                return array_map(static fn ($v) => (string) $v, $managed);
            }
        } catch (Throwable) {
        }

        // Fallback env PROVIDER_{SLUG}_{ENV}_{FIELD}
        $provider = ProviderCatalog::get($slug);
        $out = [];
        foreach (($provider['credentials'] ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $val = ProviderConfig::credential($slug, $key, $environment);
            if ($val !== null && $val !== '') {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function requiredKeysFromCatalog(string $slug): array
    {
        $provider = ProviderCatalog::get($slug) ?? [];
        $keys = [];
        foreach (($provider['credentials'] ?? []) as $field) {
            if (!empty($field['required'])) {
                $keys[] = (string) $field['key'];
            }
        }
        return $keys;
    }

    /** @return array{status:int,body:string} */
    private static function http(string $method, string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init');
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($body !== '' && strtoupper($method) !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            throw new \RuntimeException('TIMEOUT');
        }
        if ($errno !== CURLE_OK) {
            throw new \RuntimeException('NETWORK');
        }
        return ['status' => $code, 'body' => is_string($raw) ? $raw : ''];
    }
}
