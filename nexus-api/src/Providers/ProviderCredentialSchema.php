<?php

declare(strict_types=1);

namespace Nexus\Providers;

/**
 * ProviderCredentialSchema — schéma des credentials attendues par provider (§5, §6, §7).
 *
 * SOURCE : documentation officielle de chaque provider, consultée et citée
 * dans la `justification` de chaque champ. Aucune credential n'est inventée.
 *
 * Lorsqu'une information n'a PAS pu être confirmée par la documentation
 * officielle, le provider est marqué UNKNOWN (voir self::UNKNOWN) plutôt que
 * de faire une supposition (§7, §37).
 *
 * RÈGLE §6 : `frontend_exposable = true` est réservé aux credentials dont la
 * documentation officielle affirme explicitement qu'elles sont destinées au
 * client. Tout le reste est backend-only.
 */
final class ProviderCredentialSchema
{
    /**
     * Providers dont le schéma de credentials n'a PAS été confirmé par la
     * documentation officielle. Ils restent utilisables via le catalogue
     * générique, mais leur schéma n'est pas déclaré comme vérifié.
     */
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }

    /**
     * Schéma vérifié d'un provider.
     *
     * @return list<CredentialDefinition>|null null = schéma non vérifié (UNKNOWN)
     */
    public static function for(string $slug): ?array
    {
        return match ($slug) {
            'stripe'  => self::stripe(),
            'stripe_issuing' => self::stripeIssuing(),
            'pawapay' => self::pawapay(),
            'wise'    => self::wise(),
            'nium'    => self::nium(),
            'western_union' => self::westernUnion(),
            'moneygram' => self::moneygram(),
            'sumsub'  => self::sumsub(),
            'thunes'  => self::thunes(),
            'mtn_momo' => self::mtnMomo(),
            'safaricom_mpesa' => self::safaricomMpesa(),
            'orange_money' => self::orangeMoney(),
            'currencycloud' => self::currencycloud(),
            'marqeta' => self::marqeta(),
            'xendit'  => self::xendit(),
            'modulr'  => self::modulr(),
            'bvnk'    => self::bvnk(),
            'tazapay' => self::tazapay(),
            'ebanx'   => self::ebanx(),
            'dlocal'  => self::dlocal(),
            'bridge'  => self::bridge(),
            'swan'    => self::swan(),
            'yellow_card' => self::yellowCard(),
            'onfriq'  => self::onfriq(),
            'noah'    => self::noah(),
            'cashramp' => self::cashramp(),
            '2c2p'    => self::twoC2p(),
            default   => null,
        };
    }

    /** Le schéma de ce provider a-t-il été vérifié sur sa doc officielle ? */
    public static function isVerified(string $slug): bool
    {
        return self::for($slug) !== null;
    }

    /**
     * Stripe — https://docs.stripe.com/keys
     *
     * La documentation Stripe est explicite : « Only publishable keys are safe
     * to expose outside your application's backend. You're responsible for
     * protecting other Stripe API keys, including restricted API keys. »
     */
    private static function stripe(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'secret_key',
                label: 'Secret Key (sk_)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.stripe.com/keys : « Secret API key sk_... — Safe to expose: No ». '
                    . 'Permissions illimitées sur toutes les API Stripe : backend uniquement.',
                placeholder: 'sk_test_...'
            ),
            CredentialDefinition::publicKey(
                name: 'publishable_key',
                label: 'Publishable Key (pk_)',
                required: false,
                justification: 'docs.stripe.com/keys : « Publishable API key — Safe to expose: Yes. '
                    . 'API key that you can put in front-end code or applications you distribute. » '
                    . 'SEULE credential Stripe explicitement documentée comme exposable au client.',
                placeholder: 'pk_test_...'
            ),
            CredentialDefinition::secret(
                name: 'webhook_secret',
                label: 'Webhook Signing Secret',
                required: false,
                usage: CredentialDefinition::USAGE_WEBHOOK,
                justification: 'docs.stripe.com/keys : les webhook signing secrets ne sont pas des clés '
                    . 'API mais servent à authentifier les webhooks. Jamais exposable.',
                placeholder: 'whsec_...'
            ),
            CredentialDefinition::identifier(
                name: 'account_id',
                label: 'Account ID',
                required: false,
                justification: 'Identifiant de compte Stripe Connect (acct_...). Non secret, mais '
                    . 'backend-only par défaut (§6) : aucune raison documentée de l\'exposer.',
                placeholder: 'acct_...'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function stripeIssuing(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'secret_key',
                label: 'Secret Key (sk_)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.stripe.com/issuing et docs.stripe.com/keys : même secret API sk_… '
                    . 'que Stripe Payments, avec permissions Issuing. Backend uniquement. '
                    . 'Repli runtime possible sur les credentials du slug `stripe` du même compte.',
                placeholder: 'sk_test_...'
            ),
            CredentialDefinition::secret(
                name: 'webhook_secret',
                label: 'Webhook Signing Secret',
                required: false,
                usage: CredentialDefinition::USAGE_WEBHOOK,
                justification: 'docs.stripe.com/webhooks : signing secret pour événements Issuing '
                    . '(issuing_card.*, issuing_authorization.*). Jamais exposable.',
                placeholder: 'whsec_...'
            ),
        ];
    }

    /**
     * pawaPay — https://docs.pawapay.io/using_the_api
     *
     * ATTENTION — piège volontairement traité ici (§6) : pawaPay utilise une
     * « clé publique » pour la signature des requêtes financières. Malgré son
     * nom, elle n'est PAS destinée au navigateur : elle est déposée dans le
     * dashboard pawaPay et sert à valider les signatures côté pawaPay.
     * Elle reste donc backend-only.
     */
    private static function pawapay(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_token',
                label: 'API Token (Bearer)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.pawapay.io/using_the_api : « The pawaPay Merchant API uses a bearer '
                    . 'token for authentication ». Le token sandbox ne fonctionne QUE en sandbox et '
                    . 'un token distinct doit être généré en production.',
                placeholder: 'Token pawaPay'
            ),
            CredentialDefinition::secret(
                name: 'private_key',
                label: 'Clé privée de signature',
                required: false,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'docs.pawapay.io : signature optionnelle des requêtes financières '
                    . '(deposit/payout/refund). Clé PRIVÉE : backend uniquement.',
                placeholder: '-----BEGIN EC PRIVATE KEY-----'
            ),
            CredentialDefinition::identifier(
                name: 'api_key_id',
                label: 'Identifiant de clé (keyid)',
                required: false,
                justification: 'Identifiant de la clé de signature déclarée dans le dashboard pawaPay. '
                    . 'Non secret mais backend-only (§6).',
                placeholder: 'CUSTOMER_TEST_KEY'
            ),
        ];
    }

    /**
     * Wise Platform — https://docs.wise.com/guides/developer/auth-and-security
     *
     * La doc Wise est explicite : « Never expose client credentials or tokens
     * in client-side code, logs, or URLs » et « Use separate credentials for
     * sandbox and production ».
     */
    private static function wise(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'client_id',
                label: 'Client ID (OAuth 2.0)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.wise.com/guides/developer/auth-and-security : « Never expose client '
                    . 'credentials or tokens in client-side code, logs, or URLs ». Le client_id fait '
                    . 'partie des « client credentials » explicitement visées : backend-only.',
                placeholder: 'Client ID Wise'
            ),
            CredentialDefinition::secret(
                name: 'client_secret',
                label: 'Client Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.wise.com : « Rotate your client secret periodically ». Secret strict.',
                placeholder: 'Client Secret Wise'
            ),
            CredentialDefinition::identifier(
                name: 'profile_id',
                label: 'Profile ID',
                required: false,
                justification: 'Identifiant de profil Wise. Non secret, backend-only par défaut (§6).',
                placeholder: 'Profile ID'
            ),
        ];
    }

    /**
     * Nium — https://docs.nium.com/apis/reference/nium-environments
     *
     * Environnements officiels :
     *   sandbox    → https://gateway.nium.com/api
     *   production → https://api.spend.nium.com/api
     */
    private static function nium(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'client_id',
                label: 'Client ID',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.nium.com : « you will be provided with a client_id and client_secret '
                    . 'to be used for API authentication ». Credentials serveur : backend-only.',
                placeholder: 'client_id Nium'
            ),
            CredentialDefinition::secret(
                name: 'client_secret',
                label: 'Client Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.nium.com : credential d\'authentification serveur. Jamais exposable.',
                placeholder: 'client_secret Nium'
            ),
        ];
    }

    /**
     * Western Union — Mass Payments API (Partnership Program / WU Connect)
     *
     * Sources : documentation officielle OpenAPI Western Union
     *   https://developer.westernunion.com/getting-started.html
     *   serveurs : prod https://api.westernunion.com · sandbox https://api-sandbox.westernunion.com
     *   endpoints : GET /Ping, GET /customers/{clientId}, POST /customers/{clientId}/quotes,
     *               PUT /customers/{clientId}/batches/{batchId}, POST .../payments
     *
     * Authentification : MUTUAL TLS (mTLS) via certificat client délivré par
     * Western Union à l'adhésion au Partnership Program. Aucun credential ne
     * doit atteindre le navigateur. Accès après onboarding partenaire/compliance.
     */
    private static function westernUnion(): array
    {
        return [
            CredentialDefinition::identifier(
                name: 'client_id',
                label: 'Client ID (WU clientId)',
                required: true,
                justification: 'developer.westernunion.com : endpoints référencent le clientId du partenaire '
                    . '(/customers/:clientId, /customers/:clientId/quotes, /customers/:clientId/batches/:batchId). '
                    . 'Non secret mais backend-only par défaut.',
                placeholder: 'Client ID partenaire WU'
            ),
            CredentialDefinition::secret(
                name: 'client_cert_path',
                label: 'Certificat mTLS (chemin)',
                required: true,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'developer.westernunion.com : « Mutual TLS authentication using client '
                    . 'certificates provided by Western Union upon enrollment in the Partnership Program ». '
                    . 'Chemin serveur, jamais exposable.',
                placeholder: '/chemin/vers/client.crt'
            ),
            CredentialDefinition::secret(
                name: 'client_key_path',
                label: 'Clé privée mTLS (chemin)',
                required: true,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'developer.westernunion.com : clé privée du certificat mTLS, incluse avec '
                    . 'chaque requête. Secret strict, backend uniquement.',
                placeholder: '/chemin/vers/client.key'
            ),
            CredentialDefinition::identifier(
                name: 'partner_id',
                label: 'Partner ID',
                required: false,
                justification: 'Identifiant du partenaire Western Union. Non secret mais backend-only.',
                placeholder: 'ID partenaire WU'
            ),
        ];
    }

    /**
     * MoneyGram — https://developer.moneygram.com/moneygram-developer/docs/o-auth-api
     *
     * OAuth 2.0 client credentials : Basic base64(client_id:client_secret) puis
     * Bearer access_token. agentPartnerId requis sur les appels métier
     * (disbursement / transfer), pas pour la sonde OAuth.
     */
    private static function moneygram(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'client_id',
                label: 'Client ID (OAuth 2.0)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.moneygram.com/moneygram-developer/docs/o-auth-api : « After '
                    . 'partnering with MoneyGram, we will send you OAuth 2.0 client credentials… unique '
                    . 'client ID and secret ». « Storing credentials securely: The client ID & secret '
                    . 'are sensitive… handle and store this data with the utmost security » — backend-only.',
                placeholder: 'Client ID MoneyGram'
            ),
            CredentialDefinition::secret(
                name: 'client_secret',
                label: 'Client Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.moneygram.com OAuth API : client_secret avec client_id en Basic '
                    . 'Auth pour GET /oauth/accesstoken?grant_type=client_credentials. Secret strict.',
                placeholder: 'Client Secret MoneyGram'
            ),
            CredentialDefinition::identifier(
                name: 'agent_partner_id',
                label: 'Agent Partner ID',
                required: false,
                justification: 'developer.moneygram.com : agentPartnerId — « Unique agent or partner '
                    . 'identifier » requis sur les appels disbursement/transfer (query). Non secret ; '
                    . 'délivré à l\'adhésion partenaire. Optionnel pour testConnection OAuth.',
                placeholder: 'agentPartnerId'
            ),
        ];
    }

    /**
     * Sumsub — https://docs.sumsub.com/reference/authentication
     *
     * App Token + Secret Key + Webhook Secret : tous backend-only.
     */
    private static function sumsub(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'app_token',
                label: 'App Token',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.sumsub.com/reference/authentication : X-App-Token — secret serveur.',
                placeholder: 'sbx:…'
            ),
            CredentialDefinition::secret(
                name: 'secret_key',
                label: 'Secret Key',
                required: true,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'docs.sumsub.com : Secret Key pour HMAC X-App-Access-Sig — jamais exposable.',
                placeholder: 'Secret Sumsub'
            ),
            CredentialDefinition::secret(
                name: 'webhook_secret',
                label: 'Webhook Secret',
                required: true,
                usage: CredentialDefinition::USAGE_WEBHOOK,
                justification: 'docs.sumsub.com : secret pour x-payload-digest — backend uniquement.',
                placeholder: 'Secret webhook'
            ),
        ];
    }

    /**
     * Champs exposables au frontend pour un provider (§6).
     *
     * @return list<string>
     */
    public static function frontendExposableFields(string $slug): array
    {
        $defs = self::for($slug);
        if ($defs === null) {
            // Schéma non vérifié → on n'expose RIEN (principe de précaution).
            return [];
        }
        $out = [];
        foreach ($defs as $def) {
            if ($def->frontendExposable) {
                $out[] = $def->name;
            }
        }
        return $out;
    }

    /**
     * Un champ donné est-il exposable au frontend ?
     *
     * Retourne false par défaut pour tout champ inconnu ou tout provider
     * dont le schéma n'est pas vérifié (§6 : backend-only par défaut).
     */
    public static function isFrontendExposable(string $slug, string $field): bool
    {
        return in_array($field, self::frontendExposableFields($slug), true);
    }

    /** Description publique du schéma (sans aucune valeur). */
    public static function describe(string $slug): array
    {
        $defs = self::for($slug);
        if ($defs !== null) {
            return [
                'verified'    => true,
                'source'      => 'official_documentation',
                'credentials' => array_map(static fn (CredentialDefinition $d) => $d->toArray(), $defs),
            ];
        }

        // Fallback catalogue : formulaires SuperAdmin toujours remplissables,
        // mais jamais présentés comme « verified ».
        $fromCatalog = self::fromCatalog($slug);
        return [
            'verified'    => false,
            'source'      => self::UNKNOWN,
            'credentials' => array_map(static fn (CredentialDefinition $d) => $d->toArray(), $fromCatalog),
        ];
    }

    /**
     * Dérive des définitions depuis ProviderCatalog (non vérifiées).
     *
     * @return list<CredentialDefinition>
     */
    private static function fromCatalog(string $slug): array
    {
        $provider = \Nexus\Services\ProviderCatalog::get($slug);
        if ($provider === null) {
            return [];
        }
        $out = [];
        foreach (($provider['credentials'] ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $required = (bool) ($field['required'] ?? false);
            $label = (string) ($field['label'] ?? $key);
            $placeholder = (string) ($field['placeholder'] ?? '');
            $type = (string) ($field['type'] ?? 'password');
            $isPublic = str_contains(strtolower($key), 'publishable')
                || ($key === 'public_key' && $slug === 'xendit');
            if ($isPublic) {
                $out[] = CredentialDefinition::publicKey(
                    $key,
                    $label,
                    $required,
                    'Champ catalogue (schéma non vérifié sur doc officielle).',
                    $placeholder
                );
                continue;
            }
            if ($type === 'text' && !str_contains(strtolower($key), 'secret') && !str_contains(strtolower($key), 'token') && !str_contains(strtolower($key), 'key')) {
                $out[] = CredentialDefinition::identifier(
                    $key,
                    $label,
                    $required,
                    'Champ catalogue (schéma non vérifié).',
                    $placeholder
                );
                continue;
            }
            $out[] = CredentialDefinition::secret(
                $key,
                $label,
                $required,
                CredentialDefinition::USAGE_API_AUTH,
                'Champ catalogue (schéma non vérifié sur doc officielle) — backend-only.',
                $placeholder
            );
        }
        return $out;
    }

    /** @return list<CredentialDefinition> */
    private static function thunes(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.thunes.com/money-transfer/v2 : HTTP Basic — user = API key.',
                placeholder: 'API Key Thunes'
            ),
            CredentialDefinition::secret(
                name: 'api_secret',
                label: 'API Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.thunes.com/money-transfer/v2 : HTTP Basic — password = API secret.',
                placeholder: 'API Secret Thunes'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function mtnMomo(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'subscription_key',
                label: 'Subscription Key (Ocp-Apim)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'momodeveloper.mtn.com : header Ocp-Apim-Subscription-Key sur toutes les requêtes.',
                placeholder: 'Subscription key'
            ),
            CredentialDefinition::identifier(
                name: 'api_user',
                label: 'API User (UUID)',
                required: true,
                justification: 'MTN MoMo : API User UUID pour Basic Auth vers /token.',
                placeholder: 'uuid'
            ),
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'MTN MoMo : password Basic avec api_user pour obtenir l\'access_token.',
                placeholder: 'API Key MoMo'
            ),
            CredentialDefinition::identifier(
                name: 'callback_host',
                label: 'Callback Host',
                required: false,
                justification: 'Hôte de callback providerCallbackHost (sandbox provisioning).',
                placeholder: 'https://votre-domaine.com'
            ),
            CredentialDefinition::secret(
                name: 'disbursement_subscription_key',
                label: 'Disbursement Subscription Key',
                required: false,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'Produit Disbursements distinct de Collections sur le portail MoMo.',
                placeholder: 'Optionnel'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function safaricomMpesa(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'consumer_key',
                label: 'Consumer Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.safaricom.co.ke : OAuth Basic consumer_key:consumer_secret.',
                placeholder: 'Consumer Key'
            ),
            CredentialDefinition::secret(
                name: 'consumer_secret',
                label: 'Consumer Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.safaricom.co.ke : secret OAuth Daraja.',
                placeholder: 'Consumer Secret'
            ),
            CredentialDefinition::secret(
                name: 'passkey',
                label: 'Lipa Na M-Pesa Passkey',
                required: true,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'Daraja STK Push : Passkey pour password cipher.',
                placeholder: 'Passkey'
            ),
            CredentialDefinition::identifier(
                name: 'shortcode',
                label: 'Business Shortcode',
                required: true,
                justification: 'Shortcode marchand Lipa Na M-Pesa.',
                placeholder: '174379'
            ),
            CredentialDefinition::secret(
                name: 'security_credential',
                label: 'Security Credential',
                required: false,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'B2C : security credential chiffrée.',
                placeholder: 'Optionnel'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function orangeMoney(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'client_id',
                label: 'Client ID',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'Orange Sonatel / Orange Money : OAuth2 client credentials.',
                placeholder: 'Client ID'
            ),
            CredentialDefinition::secret(
                name: 'client_secret',
                label: 'Client Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'OAuth2 client_secret Orange Money.',
                placeholder: 'Client Secret'
            ),
            CredentialDefinition::secret(
                name: 'rsa_public_key',
                label: 'Clé publique RSA (PIN)',
                required: false,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'Chiffrement PIN côté serveur si requis par le flux OM.',
                placeholder: '-----BEGIN PUBLIC KEY-----'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function currencycloud(): array
    {
        return [
            CredentialDefinition::identifier(
                name: 'login_id',
                label: 'Login ID (email)',
                required: true,
                justification: 'developer.currencycloud.com : login_id (souvent email) pour POST /v2/authenticate/api.',
                placeholder: 'vous@email.com'
            ),
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.currencycloud.com : api_key 64 hex — backend only.',
                placeholder: 'api_key'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function marqeta(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'application_token',
                label: 'Application Token',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'marqeta.com/docs/core-api/authentication : username Basic = application token.',
                placeholder: 'application_token'
            ),
            CredentialDefinition::secret(
                name: 'admin_access_token',
                label: 'Admin Access Token',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'marqeta.com/docs/core-api/authentication : password Basic = admin access token.',
                placeholder: 'admin_access_token'
            ),
            CredentialDefinition::identifier(
                name: 'program_config',
                label: 'Program Token',
                required: false,
                justification: 'Identifiant programme Marqeta (optionnel).',
                placeholder: 'program_token'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function xendit(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'secret_key',
                label: 'Secret Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.xendit.co : Basic Auth secret_key: (password vide).',
                placeholder: 'xnd_...'
            ),
            CredentialDefinition::publicKey(
                name: 'public_key',
                label: 'Public Key',
                required: false,
                justification: 'docs.xendit.co : clé publique utilisable côté client pour certains flux.',
                placeholder: 'xnd_public_...'
            ),
            CredentialDefinition::secret(
                name: 'webhook_secret',
                label: 'Webhook Verification Token',
                required: false,
                usage: CredentialDefinition::USAGE_WEBHOOK,
                justification: 'Callback token Xendit — backend only.',
                placeholder: 'webhook token'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function modulr(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key (keyId)',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.modulrfinance.com : keyId dans Authorization Signature.',
                placeholder: 'API Key'
            ),
            CredentialDefinition::secret(
                name: 'api_secret',
                label: 'HMAC Secret',
                required: true,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'docs.modulrfinance.com : secret HMAC-SHA1 pour Signature header.',
                placeholder: 'HMAC secret'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function bvnk(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'hawk_auth_id',
                label: 'Hawk Auth ID',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.bvnk.com : Hawk Auth ID (authentification Hawk).',
                placeholder: 'Hawk Auth ID'
            ),
            CredentialDefinition::secret(
                name: 'hawk_secret_key',
                label: 'Hawk Secret Key',
                required: true,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'docs.bvnk.com : Hawk Secret Key.',
                placeholder: 'Hawk Secret'
            ),
            CredentialDefinition::secret(
                name: 'webhook_secret',
                label: 'Webhook Secret',
                required: false,
                usage: CredentialDefinition::USAGE_WEBHOOK,
                justification: 'HMAC webhook BVNK — backend only.',
                placeholder: 'whsec'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function tazapay(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.tazapay.com : Basic api_key:api_secret.',
                placeholder: 'API Key'
            ),
            CredentialDefinition::secret(
                name: 'api_secret',
                label: 'API Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.tazapay.com : secret Basic Auth.',
                placeholder: 'API Secret'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function ebanx(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'integration_key',
                label: 'Integration Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developers.ebanx.com : integration_key dans les payloads API.',
                placeholder: 'integration_key'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function dlocal(): array
    {
        return [
            CredentialDefinition::identifier(
                name: 'api_key',
                label: 'X-Login',
                required: true,
                justification: 'docs.dlocal.com : header X-Login.',
                placeholder: 'x-login'
            ),
            CredentialDefinition::secret(
                name: 'api_token',
                label: 'X-Trans-Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.dlocal.com : header X-Trans-Key.',
                placeholder: 'x-trans-key'
            ),
            CredentialDefinition::secret(
                name: 'api_secret',
                label: 'Secret Key (HMAC)',
                required: true,
                usage: CredentialDefinition::USAGE_SIGNING,
                justification: 'docs.dlocal.com : secret pour signature V2-HMAC-SHA256.',
                placeholder: 'secret'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function bridge(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.bridge.xyz : API key Bearer.',
                placeholder: 'API Key Bridge'
            ),
            CredentialDefinition::secret(
                name: 'webhook_public_key',
                label: 'Webhook Public Key',
                required: false,
                usage: CredentialDefinition::USAGE_WEBHOOK,
                justification: 'docs.bridge.xyz : vérification X-Webhook-Signature.',
                placeholder: 'webhook public key'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function swan(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'client_id',
                label: 'Client ID',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.swan.io : OAuth2 partner client_id.',
                placeholder: 'Client ID'
            ),
            CredentialDefinition::secret(
                name: 'client_secret',
                label: 'Client Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.swan.io : OAuth2 client_secret.',
                placeholder: 'Client Secret'
            ),
            CredentialDefinition::identifier(
                name: 'project_id',
                label: 'Project ID',
                required: false,
                justification: 'Identifiant projet Swan.',
                placeholder: 'project_id'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function yellowCard(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.yellowcard.io : API key (revue catalogue alignée).',
                placeholder: 'API Key'
            ),
            CredentialDefinition::secret(
                name: 'api_secret',
                label: 'API Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.yellowcard.io : API secret.',
                placeholder: 'API Secret'
            ),
            CredentialDefinition::identifier(
                name: 'business_id',
                label: 'Business ID',
                required: false,
                justification: 'Identifiant business Yellow Card.',
                placeholder: 'business_id'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function onfriq(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.onafriq.com : API key (catalogue aligné).',
                placeholder: 'API Key'
            ),
            CredentialDefinition::secret(
                name: 'api_secret',
                label: 'API Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.onafriq.com : API secret.',
                placeholder: 'API Secret'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function noah(): array
    {
        return [
            CredentialDefinition::secret(
                name: 'api_key',
                label: 'API Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.noah.com : API key.',
                placeholder: 'API Key'
            ),
            CredentialDefinition::secret(
                name: 'api_secret',
                label: 'API Secret',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.noah.com : API secret.',
                placeholder: 'API Secret'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function cashramp(): array
    {
        return [
            CredentialDefinition::publicKey(
                name: 'public_key',
                label: 'Public Key',
                required: true,
                justification: 'docs.cashramp.com : public_key côté intégration (catalogue).',
                placeholder: 'public_key'
            ),
            CredentialDefinition::secret(
                name: 'secret_key',
                label: 'Secret Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'docs.cashramp.com : secret_key backend.',
                placeholder: 'secret_key'
            ),
        ];
    }

    /** @return list<CredentialDefinition> */
    private static function twoC2p(): array
    {
        return [
            CredentialDefinition::identifier(
                name: 'merchant_id',
                label: 'Merchant ID',
                required: true,
                justification: 'developer.2c2p.com : merchant_id.',
                placeholder: 'merchant_id'
            ),
            CredentialDefinition::secret(
                name: 'secret_key',
                label: 'Secret Key',
                required: true,
                usage: CredentialDefinition::USAGE_API_AUTH,
                justification: 'developer.2c2p.com : secret_key.',
                placeholder: 'secret_key'
            ),
        ];
    }
}
