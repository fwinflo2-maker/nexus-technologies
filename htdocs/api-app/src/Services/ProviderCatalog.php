<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * Catalogue des providers externes — métadonnées, champs de credentials,
 * URLs de base et documentation.
 *
 * Utilisé par ProviderCredentialController pour valider, stocker et
 * décrire les identifiants API de chaque partenaire.
 *
 * Les slugs correspondent aux valeurs stockées en base (`provider_slug`).
 */
final class ProviderCatalog
{
    /**
     * Entrée complète d'un provider.
     *
     * @var array<string, array{
     *     name: string, category: string, icon: string,
     *     auth_type: string, base_url: string, sandbox_url: string|null,
     *     credentials: list<array{key: string, label: string, placeholder: string, required: bool, type: string}>,
     *     doc_url: string, countries: list<string>,
     * }>
     */
    private const PROVIDERS = [
        // ── Mobile Money Aggregators ──────────────────────────────────────
        'pawapay' => [
            'name'        => 'pawaPay',
            'category'    => 'mobile_money',
            'icon'        => '📱',
            'auth_type'   => 'bearer_token',
            'base_url'    => 'https://api.pawapay.io',
            'sandbox_url' => 'https://api.sandbox.pawapay.io',
            'credentials' => [
                ['key' => 'api_token',     'label' => 'API Token',     'placeholder' => 'Votre token pawaPay', 'required' => true, 'type' => 'password'],
                ['key' => 'api_key_id',    'label' => 'Clé API (keyid)', 'placeholder' => 'CUSTOMER_TEST_KEY', 'required' => false, 'type' => 'text'],
                ['key' => 'private_key',   'label' => 'Clé privée (signatures)', 'placeholder' => '-----BEGIN EC PRIVATE KEY-----', 'required' => false, 'type' => 'textarea'],
            ],
            'doc_url'    => 'https://docs.pawapay.io/using_the_api',
            'countries'  => ['NG','CD','CM','CG','UG','TZ','ZM','GH','KE','RW','NE','TG','BJ','SN','CI','ML','BF'],
        ],
        'thunes' => [
            'name'        => 'Thunes',
            'category'    => 'payout_network',
            'icon'        => '🌐',
            'auth_type'   => 'basic',
            'base_url'    => 'https://api.thunes.com',
            'sandbox_url' => 'https://sandbox.thunes.com',
            'credentials' => [
                ['key' => 'api_key',    'label' => 'API Key',    'placeholder' => 'Votre clé API Thunes',   'required' => true, 'type' => 'password'],
                ['key' => 'api_secret', 'label' => 'API Secret', 'placeholder' => 'Votre secret Thunes',    'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.thunes.com/money-transfer/v1',
            'countries'  => ['US','GB','NG','GH','CD','CM','CG','KE','UG','TZ','ZA'],
        ],
        'orange_money' => [
            'name'        => 'Orange Money',
            'category'    => 'mobile_money',
            'icon'        => '🟠',
            'auth_type'   => 'oauth2',
            'base_url'    => 'https://api.orange-sonatel.com',
            'sandbox_url' => 'https://api.sandbox.orange-sonatel.com',
            'credentials' => [
                ['key' => 'client_id',     'label' => 'Client ID',     'placeholder' => 'Votre client_id Orange',    'required' => true, 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'placeholder' => 'Votre client_secret Orange', 'required' => true, 'type' => 'password'],
                ['key' => 'rsa_public_key','label' => 'Clé publique RSA (chiffrement PIN)', 'placeholder' => '-----BEGIN PUBLIC KEY-----', 'required' => false, 'type' => 'textarea'],
            ],
            'doc_url'    => 'https://developer.orange-sonatel.com/dev/docs/orange-money',
            'countries'  => ['SN','CI','CM','ML','BF','GN','NE','CG','GA','GQ','JO','GW'],
        ],
        'mtn_momo' => [
            'name'        => 'MTN MoMo',
            'category'    => 'mobile_money',
            'icon'        => '🟡',
            'auth_type'   => 'oauth2',
            'base_url'    => 'https://proxy.momoapi.mtn.com',
            'sandbox_url' => 'https://sandbox.momodeveloper.mtn.com',
            'credentials' => [
                ['key' => 'subscription_key', 'label' => 'Subscription Key (Ocp-Apim)', 'placeholder' => 'd484a1f0d34f4301916d0f2c9e9106a2', 'required' => true, 'type' => 'password'],
                ['key' => 'api_user',         'label' => 'API User (UUID)',              'placeholder' => 'c72025f5-5cd1-4630-99e4-8ba4722fad56', 'required' => true, 'type' => 'text'],
                ['key' => 'api_key',          'label' => 'API Key',                     'placeholder' => 'f1db798c98df4bcf83b538175893bbf0',     'required' => true, 'type' => 'password'],
                ['key' => 'callback_host',    'label' => 'Callback Host',               'placeholder' => 'https://nexus-corp.com',               'required' => false, 'type' => 'text'],
                ['key' => 'disbursement_subscription_key', 'label' => 'Disbursement Subscription Key', 'placeholder' => 'Optionnel', 'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://momodeveloper.mtn.com/api-documentation',
            'countries'  => ['UG','GH','CM','CI','CG','ZM','RW','SN','NE'],
        ],
        'safaricom_mpesa' => [
            'name'        => 'Safaricom M-Pesa',
            'category'    => 'mobile_money',
            'icon'        => '🇰🇪',
            'auth_type'   => 'oauth2',
            'base_url'    => 'https://api.safaricom.co.ke',
            'sandbox_url' => 'https://sandbox.safaricom.co.ke',
            'credentials' => [
                ['key' => 'consumer_key',      'label' => 'Consumer Key',      'placeholder' => 'Votre consumer key Daraja',    'required' => true, 'type' => 'text'],
                ['key' => 'consumer_secret',   'label' => 'Consumer Secret',   'placeholder' => 'Votre consumer secret Daraja', 'required' => true, 'type' => 'password'],
                ['key' => 'passkey',           'label' => 'Lipa Na M-Pesa Passkey', 'placeholder' => 'bfb279f9aa9bdbcf...',  'required' => true, 'type' => 'password'],
                ['key' => 'shortcode',         'label' => 'Business Shortcode','placeholder' => '174379',                       'required' => true, 'type' => 'text'],
                ['key' => 'security_credential','label' => 'Security Credential','placeholder' => 'Encrypté avec certificat',   'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://developer.safaricom.co.ke/apis',
            'countries'  => ['KE'],
        ],

        // ── Banking / BaaS ────────────────────────────────────────────────
        'swan' => [
            'name'        => 'Swan',
            'category'    => 'banking',
            'icon'        => '🏦',
            'auth_type'   => 'oauth2',
            'base_url'    => 'https://api.swan.io/live-partner/graphql',
            'sandbox_url' => 'https://api.swan.io/sandbox-partner/graphql',
            'credentials' => [
                ['key' => 'client_id',     'label' => 'Client ID (Partner)', 'placeholder' => 'Votre Client ID Swan',  'required' => true, 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret',       'placeholder' => 'Votre Client Secret',   'required' => true, 'type' => 'password'],
                ['key' => 'project_id',    'label' => 'Project ID',          'placeholder' => 'ID du projet Swan',      'required' => false, 'type' => 'text'],
            ],
            'doc_url'    => 'https://docs.swan.io/developers/using-api/authentication/',
            'countries'  => ['FR','DE','ES','IT','NL','BE','LU','AT','PT','IE','FI','GR'],
        ],
        'modulr' => [
            'name'        => 'Modulr',
            'category'    => 'banking',
            'icon'        => '💳',
            'auth_type'   => 'hmac',
            'base_url'    => 'https://api.modulrfinance.com',
            'sandbox_url' => 'https://api-sandbox.modulrfinance.com',
            'credentials' => [
                ['key' => 'api_key',    'label' => 'API Key (keyId)', 'placeholder' => 'Votre API Key Modulr', 'required' => true, 'type' => 'password'],
                ['key' => 'api_secret', 'label' => 'HMAC Secret',     'placeholder' => 'Votre secret HMAC',    'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.modulrfinance.com/',
            'countries'  => ['US','GB'],
        ],
        'bvnk' => [
            'name'        => 'BVNK',
            'category'    => 'banking',
            'icon'        => '🏦',
            'auth_type'   => 'hawk',
            'base_url'    => 'https://api.bvnk.com',
            'sandbox_url' => 'https://sandbox.bvnk.com',
            'credentials' => [
                ['key' => 'hawk_auth_id',    'label' => 'Hawk Auth ID',    'placeholder' => 'Hawk Auth ID', 'required' => true, 'type' => 'password'],
                ['key' => 'hawk_secret_key', 'label' => 'Hawk Secret Key', 'placeholder' => 'Hawk Secret',  'required' => true, 'type' => 'password'],
                ['key' => 'webhook_secret',  'label' => 'Webhook Secret',  'placeholder' => 'whsec...',     'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.bvnk.com/',
            'countries'  => ['US','EU'],
        ],

        // ── FX / Cross-border ─────────────────────────────────────────────
        'currencycloud' => [
            'name'        => 'Currencycloud',
            'category'    => 'fx',
            'icon'        => '💱',
            'auth_type'   => 'custom',
            'base_url'    => 'https://api.currencycloud.com/v2',
            'sandbox_url' => 'https://devapi.currencycloud.com/v2',
            'credentials' => [
                ['key' => 'login_id', 'label' => 'Login ID (email)', 'placeholder' => 'votre@email.com',   'required' => true, 'type' => 'text'],
                ['key' => 'api_key',  'label' => 'API Key',         'placeholder' => '1f6a3e944f8c...',    'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://developer.currencycloud.com/guides/integration-guides/authentication/',
            'countries'  => ['EU','GB','US'],
        ],
        'wise' => [
            'name'        => 'Wise Platform',
            'category'    => 'fx',
            'icon'        => '💚',
            'auth_type'   => 'oauth2',
            'base_url'    => 'https://api.wise.com',
            'sandbox_url' => 'https://api.wise-sandbox.com',
            'credentials' => [
                ['key' => 'client_id',     'label' => 'Client ID',     'placeholder' => 'Votre Client ID Wise',  'required' => true, 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'placeholder' => 'Votre Client Secret',   'required' => true, 'type' => 'password'],
                ['key' => 'profile_id',    'label' => 'Profile ID',    'placeholder' => 'Profile (optionnel)',    'required' => false, 'type' => 'text'],
            ],
            'doc_url'    => 'https://docs.wise.com/guides/developer',
            'countries'  => ['EU','GB','US','AU','SG'],
        ],
        'western_union' => [
            'name'        => 'Western Union',
            'category'    => 'payout_network',
            'icon'        => '🔵',
            'auth_type'   => 'mutual_tls',
            'base_url'    => 'https://api.westernunion.com',
            'sandbox_url' => 'https://api-sandbox.westernunion.com',
            'credentials' => [
                ['key' => 'client_id',        'label' => 'Client ID (WU clientId)', 'placeholder' => 'Votre Client ID partenaire WU', 'required' => true,  'type' => 'text'],
                ['key' => 'client_cert_path', 'label' => 'Certificat mTLS (chemin)', 'placeholder' => '/chemin/vers/client.crt',     'required' => true,  'type' => 'password'],
                ['key' => 'client_key_path',  'label' => 'Clé privée mTLS (chemin)', 'placeholder' => '/chemin/vers/client.key',    'required' => true,  'type' => 'password'],
                ['key' => 'partner_id',       'label' => 'Partner ID',            'placeholder' => 'Votre ID partenaire WU',       'required' => false, 'type' => 'text'],
            ],
            'doc_url'    => 'https://developer.westernunion.com/getting-started.html',
            'countries'  => ['US','GB','FR','DE','ES','IT','NG','KE','IN','MX','PH','CA','AU','ZA','BR','AR','EG','TR','PK','BD','CM','SN','CI','GH','MA','TN','DZ','CG','GA','CD','BF','BJ','ML','NE','TG','UG','RW','TZ','ZM','AE','SA','CN','JP','KR','SG','TH','VN','ID','PL','NL','BE','PT','IE','AT','CH','SE','NO','DK','FI','GR','RO','HU','CZ','EU'],
        ],
        'moneygram' => [
            'name'        => 'MoneyGram',
            'category'    => 'payout_network',
            'icon'        => '🟢',
            'auth_type'   => 'oauth2',
            'base_url'    => 'https://api.moneygram.com',
            'sandbox_url' => 'https://sandboxapi.moneygram.com',
            'credentials' => [
                ['key' => 'client_id',         'label' => 'Client ID (OAuth)',     'placeholder' => 'Client ID MoneyGram',        'required' => true,  'type' => 'text'],
                ['key' => 'client_secret',     'label' => 'Client Secret',        'placeholder' => 'Client Secret MoneyGram',    'required' => true,  'type' => 'password'],
                ['key' => 'agent_partner_id',  'label' => 'Agent Partner ID',     'placeholder' => 'agentPartnerId (partenariat)', 'required' => false, 'type' => 'text'],
            ],
            'doc_url'    => 'https://developer.moneygram.com/moneygram-developer/docs/o-auth-api',
            'countries'  => ['US','GB','FR','DE','ES','IT','NG','KE','IN','MX','PH','CA','AU','ZA','BR','AR','EG','TR','PK','BD','CM','SN','CI','GH','MA','TN','DZ','CG','GA','CD','BF','BJ','ML','NE','TG','UG','RW','TZ','ZM','AE','SA','CN','JP','KR','SG','TH','VN','ID','PL','NL','BE','PT','IE','AT','CH','SE','NO','DK','FI','GR','RO','HU','CZ','EU'],
        ],

        // ── Cartes / Issuing ──────────────────────────────────────────────
        'stripe' => [
            'name'        => 'Stripe',
            'category'    => 'cards',
            'icon'        => '💳',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.stripe.com/v1',
            'sandbox_url' => 'https://api.stripe.com/v1',
            'credentials' => [
                ['key' => 'publishable_key', 'label' => 'Publishable Key (pk_)', 'placeholder' => 'pk_test_...', 'required' => false, 'type' => 'password'],
                ['key' => 'secret_key',      'label' => 'Secret Key (sk_)',       'placeholder' => 'sk_test_...', 'required' => true,  'type' => 'password'],
                ['key' => 'webhook_secret',  'label' => 'Webhook Signing Secret','placeholder' => 'whsec_...',   'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.stripe.com/api/authentication',
            'countries'  => ['US','EU','GB','CA','AU','SG','JP','BR'],
        ],
        'stripe_issuing' => [
            'name'        => 'Stripe Issuing',
            'category'    => 'card_issuing',
            'icon'        => '💎',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.stripe.com/v1',
            'sandbox_url' => 'https://api.stripe.com/v1',
            'credentials' => [
                ['key' => 'secret_key',     'label' => 'Secret Key (sk_)', 'placeholder' => 'sk_test_...', 'required' => true, 'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Signing Secret', 'placeholder' => 'whsec_...', 'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.stripe.com/issuing',
            'countries'  => ['US','CA','GB','FR','DE','ES','IT','NL','BE','PT','IE','AT','FI','GR','LU','LT','LV','EE','SK','SI','MT','CY','CZ','PL','SE','DK','HU','RO','BG','HR','NO','CH','IS','LI'],
        ],
        'maplerad' => [
            'name'        => 'Maplerad',
            'category'    => 'card_issuing',
            'icon'        => '🌍',
            'auth_type'   => 'bearer_token',
            'base_url'    => 'https://api.maplerad.com/v1',
            'sandbox_url' => 'https://sandbox.api.maplerad.com/v1',
            'credentials' => [
                ['key' => 'secret_key',     'label' => 'Secret Key', 'placeholder' => 'sk_test_… / clé secrète dashboard', 'required' => true,  'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'placeholder' => 'secret de signature webhook', 'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://maplerad.dev/docs/issuing',
            'countries'  => ['NG','GH','KE','CI','BJ','CM','UG','TZ'],
        ],
        'marqeta' => [
            'name'        => 'Marqeta',
            'category'    => 'card_issuing',
            'icon'        => '💎',
            'auth_type'   => 'basic',
            'base_url'    => 'https://sandbox-api.marqeta.com/v3',
            'sandbox_url' => 'https://sandbox-api.marqeta.com/v3',
            'credentials' => [
                ['key' => 'application_token',  'label' => 'Application Token',  'placeholder' => 'application_token', 'required' => true, 'type' => 'password'],
                ['key' => 'admin_access_token', 'label' => 'Admin Access Token', 'placeholder' => 'admin_access_token', 'required' => true, 'type' => 'password'],
                ['key' => 'program_config',     'label' => 'Program Token',      'placeholder' => 'program_token',      'required' => false, 'type' => 'text'],
            ],
            'doc_url'    => 'https://www.marqeta.com/docs/core-api/getting-started',
            'countries'  => ['US','AU'],
        ],

        // ── Crypto / Stablecoins ──────────────────────────────────────────
        'bridge' => [
            'name'        => 'Bridge',
            'category'    => 'crypto',
            'icon'        => '🔗',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.bridge.xyz',
            'sandbox_url' => 'https://sandbox.api.bridge.xyz',
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API Key', 'placeholder' => 'Votre clé API Bridge', 'required' => true, 'type' => 'password'],
                ['key' => 'webhook_public_key', 'label' => 'Webhook Public Key', 'placeholder' => 'Clé vérif webhook', 'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.bridge.xyz/',
            'countries'  => ['US','EU'],
        ],
        'yellow_card' => [
            'name'        => 'Yellow Card',
            'category'    => 'mobile_money',
            'icon'        => '🟡',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.yellowcard.io',
            'sandbox_url' => 'https://sandboxapi.yellowcard.io',
            'credentials' => [
                ['key' => 'api_key',    'label' => 'API Key',    'placeholder' => 'Votre clé API Yellow Card', 'required' => true,  'type' => 'password'],
                ['key' => 'api_secret', 'label' => 'API Secret', 'placeholder' => 'Votre secret Yellow Card',  'required' => true,  'type' => 'password'],
                ['key' => 'business_id','label' => 'Business ID', 'placeholder' => 'Votre business ID',        'required' => false, 'type' => 'text'],
            ],
            'doc_url'    => 'https://docs.yellowcard.io/',
            'countries'  => ['NG','KE','GH'],
        ],

        // ── Payout / Emerging Markets ─────────────────────────────────────
        'onfriq' => [
            'name'        => 'Onafriq (ex-Onfriq)',
            'category'    => 'payout_network',
            'icon'        => '🌍',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.onafriq.com',
            'sandbox_url' => 'https://sandbox-api.onafriq.com',
            'credentials' => [
                ['key' => 'api_key',    'label' => 'API Key',    'placeholder' => 'Votre clé API Onafriq', 'required' => true, 'type' => 'password'],
                ['key' => 'api_secret', 'label' => 'API Secret', 'placeholder' => 'Votre secret Onafriq',  'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://developer.onafriq.com/',
            'countries'  => ['NG','CD'],
        ],
        'dlocal' => [
            'name'        => 'dLocal',
            'category'    => 'payout_network',
            'icon'        => '🌎',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.dlocal.com',
            'sandbox_url' => 'https://sandbox-api.dlocal.com',
            'credentials' => [
                ['key' => 'api_key',    'label' => 'API Key (X-Login)',  'placeholder' => 'Votre X-Login dLocal',  'required' => true, 'type' => 'text'],
                ['key' => 'api_secret', 'label' => 'API Secret',         'placeholder' => 'Votre secret dLocal',   'required' => true, 'type' => 'password'],
                ['key' => 'api_token',  'label' => 'API Token (X-Trans-Key)', 'placeholder' => 'Votre X-Trans-Key',  'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.dlocal.com/',
            'countries'  => ['BR','MX','CO','AR','CL','PE','UY'],
        ],
        'ebanx' => [
            'name'        => 'EBANX',
            'category'    => 'payout_network',
            'icon'        => '🌎',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.ebanx.com',
            'sandbox_url' => 'https://sandbox.ebanx.com',
            'credentials' => [
                ['key' => 'integration_key', 'label' => 'Integration Key', 'placeholder' => 'Votre clé EBANX', 'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://developers.ebanx.com/',
            'countries'  => ['BR','MX','CL','CO'],
        ],
        'xendit' => [
            'name'        => 'Xendit',
            'category'    => 'payout_network',
            'icon'        => '🌏',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.xendit.co',
            'sandbox_url' => 'https://api.xendit.co',
            'credentials' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'placeholder' => 'Votre clé secrète Xendit', 'required' => true, 'type' => 'password'],
                ['key' => 'public_key','label' => 'Public Key',  'placeholder' => 'Votre clé publique Xendit', 'required' => false, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.xendit.co/',
            'countries'  => ['ID','PH','SG','MY','TH'],
        ],
        'tazapay' => [
            'name'        => 'Tazapay',
            'category'    => 'payout_network',
            'icon'        => '🌏',
            'auth_type'   => 'api_key',
            // Schéma catalogue : champs courants documentés côté Tazapay.
            // ProviderCredentialSchema::for('tazapay') reste null (UNKNOWN)
            // tant qu'une revue officielle champ-par-champ n'est pas faite.
            'base_url'    => 'https://api.tazapay.com',
            'sandbox_url' => 'https://api-sandbox.tazapay.com',
            'credentials' => [
                ['key' => 'api_key',    'label' => 'API Key',    'placeholder' => 'Votre clé API Tazapay', 'required' => true, 'type' => 'password'],
                ['key' => 'api_secret', 'label' => 'API Secret', 'placeholder' => 'Votre secret Tazapay',  'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.tazapay.com/',
            'countries'  => ['IN','SG','AE','GB','US'],
        ],
        '2c2p' => [
            'name'        => '2C2P',
            'category'    => 'cards',
            'icon'        => '💳',
            'auth_type'   => 'api_key',
            // Schéma catalogue ; ProviderCredentialSchema non vérifié (UNKNOWN).
            'base_url'    => 'https://pgw.2c2p.com',
            'sandbox_url' => 'https://sandbox-pgw.2c2p.com',
            'credentials' => [
                ['key' => 'merchant_id', 'label' => 'Merchant ID', 'placeholder' => 'Votre merchant ID 2C2P', 'required' => true, 'type' => 'text'],
                ['key' => 'secret_key',  'label' => 'Secret Key',  'placeholder' => 'Votre secret 2C2P',      'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://developer.2c2p.com/',
            'countries'  => ['TH','SG','MY','ID','PH','VN'],
        ],
        'nium' => [
            'name'        => 'Nium',
            'category'    => 'payout_network',
            'icon'        => '🌏',
            'auth_type'   => 'api_key',
            // Source : docs.nium.com/apis/reference/nium-environments
            // (l'entrée pointait par erreur vers les URLs d'Airwallex).
            'base_url'    => 'https://api.spend.nium.com/api',
            'sandbox_url' => 'https://gateway.nium.com/api',
            'credentials' => [
                ['key' => 'client_id',     'label' => 'Client ID',     'placeholder' => 'Votre client_id Nium',    'required' => true, 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'placeholder' => 'Votre client_secret Nium', 'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.nium.com/',
            'countries'  => ['US','GB','AU'],
        ],
        'noah' => [
            'name'        => 'NOAH',
            'category'    => 'wallet',
            'icon'        => '🟢',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.noah.com',
            'sandbox_url' => 'https://sandbox-api.noah.com',
            'credentials' => [
                ['key' => 'api_key',    'label' => 'API Key',    'placeholder' => 'Votre clé API NOAH',  'required' => true, 'type' => 'password'],
                ['key' => 'api_secret', 'label' => 'API Secret', 'placeholder' => 'Votre secret NOAH',   'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.noah.com/',
            'countries'  => ['NG','US'],
        ],
        'cashramp' => [
            'name'        => 'CashRamp',
            'category'    => 'onramp',
            'icon'        => '🔀',
            'auth_type'   => 'api_key',
            'base_url'    => 'https://api.cashramp.com',
            'sandbox_url' => 'https://sandbox-api.cashramp.com',
            'credentials' => [
                ['key' => 'public_key',  'label' => 'Public Key',  'placeholder' => 'pk_...',  'required' => true, 'type' => 'text'],
                ['key' => 'secret_key',  'label' => 'Secret Key',  'placeholder' => 'sk_...',   'required' => true, 'type' => 'password'],
            ],
            'doc_url'    => 'https://docs.cashramp.com/',
            'countries'  => ['NG'],
        ],
        'sumsub' => [
            'name'        => 'Sumsub',
            'category'    => 'compliance',
            'icon'        => '🛡️',
            'auth_type'   => 'app_token',
            'base_url'    => 'https://api.sumsub.com',
            'sandbox_url' => 'https://api.sumsub.com',
            'credentials' => [
                ['key' => 'app_token',      'label' => 'App Token',      'placeholder' => 'sbx:…', 'required' => true, 'type' => 'password'],
                ['key' => 'secret_key',     'label' => 'Secret Key',     'placeholder' => 'Secret Sumsub', 'required' => true, 'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'placeholder' => 'Secret webhook', 'required' => false, 'type' => 'password'],
                ['key' => 'level_name',     'label' => 'Niveau KYC (WebSDK)', 'placeholder' => 'id-and-liveness', 'required' => false, 'type' => 'text'],
                ['key' => 'level_name_kyb', 'label' => 'Niveau KYB (entreprise)', 'placeholder' => 'company-verification', 'required' => false, 'type' => 'text'],
            ],
            'doc_url'    => 'https://docs.sumsub.com/',
            'countries'  => ['*'],
        ],
    ];

    /** Catégories de providers et leurs labels d'affichage. */
    public const CATEGORIES = [
        'mobile_money'  => ['label' => 'Mobile Money',     'icon' => '📱', 'description' => 'Paiements mobiles Africa'],
        'banking'       => ['label' => 'Banking / BaaS',    'icon' => '🏦', 'description' => 'Comptes bancaires & IBAN'],
        'fx'            => ['label' => 'FX / Cross-border', 'icon' => '💱', 'description' => 'Conversion de devises'],
        'cards'         => ['label' => 'Cards / Paiements', 'icon' => '💳', 'description' => 'Paiement par carte'],
        'card_issuing'  => ['label' => 'Card Issuing',      'icon' => '💎', 'description' => 'Émission de cartes virtuelles'],
        'crypto'        => ['label' => 'Crypto / Stablecoins','icon' => '🔗','description' => 'Blockchain & USDT/USDC'],
        'payout_network'=> ['label' => 'Payout Network',   'icon' => '🌍', 'description' => 'Réseaux de paiement émergents'],
        'wallet'        => ['label' => 'Wallet',             'icon' => '🟢', 'description' => 'Portefeuilles numériques'],
        'onramp'        => ['label' => 'On/Off Ramp',        'icon' => '🔀', 'description' => 'Pont fiat ↔ crypto'],
        'compliance'    => ['label' => 'Compliance / KYC',  'icon' => '🛡️', 'description' => 'Vérification d\'identité'],
    ];

    private function __construct() {}

    /** Retourne la liste complète des providers (pour le catalogue). */
    public static function all(): array
    {
        return self::PROVIDERS;
    }

    /** Retourne un provider par son slug. */
    public static function get(string $slug): ?array
    {
        return self::PROVIDERS[$slug] ?? null;
    }

    /** Vérifie qu'un slug de provider existe dans le catalogue. */
    public static function exists(string $slug): bool
    {
        return isset(self::PROVIDERS[$slug]);
    }

    /** Retourne les slugs d'un provider triés par catégorie. */
    public static function slugsByCategory(): array
    {
        $grouped = [];
        foreach (self::PROVIDERS as $slug => $provider) {
            $cat = $provider['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $slug;
        }
        return $grouped;
    }
}
