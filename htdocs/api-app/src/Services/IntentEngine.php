<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Currency;

/**
 * Moteur d'intention — source de vérité centralisée pour le formulaire /send.
 *
 * Fournit la couverture mondiale des providers (pays supportés, devises,
 * modes de réception, opérateurs Mobile Money) et les taux de conversion
 * utilisés pour les estimations dans le formulaire multi-étapes.
 *
 * Architecture :
 *   Providers (ProviderCatalog)
 *     → Couverture par pays (countries[])
 *     → Extension « EU » vers pays individuels
 *     → Devises par pays (données de référence)
 *     → Modes de réception (catégorie provider → type de réception)
 *     → Opérateurs (MOBILE_MONEY_OPERATORS, réseaux crypto)
 *     → Taux de conversion (estimation)
 */
final class IntentEngine
{
    // ── Mapping catégorie provider → modes de réception supportés ──────────
    private const CATEGORY_TO_METHODS = [
        'mobile_money'   => ['mobile_money'],
        'banking'        => ['bank'],
        'fx'             => ['bank'],
        'cards'          => ['bank'],
        'card_issuing'   => [],
        'crypto'         => ['crypto'],
        'payout_network' => ['bank', 'cash_pickup'],
        'wallet'         => ['mobile_money'],
        'onramp'         => ['crypto'],
    ];

    private const METHOD_LABELS = [
        'mobile_money' => 'Mobile Money',
        'bank'         => 'Banque / compte bancaire',
        'crypto'       => 'Crypto',
        'cash_pickup'  => 'Cash Pickup',
    ];

    private const METHOD_ICONS = [
        'mobile_money' => '📱',
        'bank'         => '🏦',
        'crypto'       => '₿',
        'cash_pickup'  => '💵',
    ];

    /** Actifs crypto proposés comme devises de réception (en plus de la devise locale). */
    private const CRYPTO_DEST_ASSETS = [
        ['code' => 'USDT', 'name' => 'Tether USD', 'symbol' => 'USDT'],
        ['code' => 'USDC', 'name' => 'USD Coin',   'symbol' => 'USDC'],
        ['code' => 'ETH',  'name' => 'Ethereum',   'symbol' => 'ETH'],
        ['code' => 'BTC',  'name' => 'Bitcoin',    'symbol' => 'BTC'],
    ];

    // ── Opérateurs Mobile Money par pays (source : AccountController) ──────
    private const MOBILE_MONEY_OPERATORS = [
        'CG' => ['Airtel Money', 'MTN Mobile Money', 'Moov Africa'],
        'CD' => ['Airtel Money', 'M-Pesa', 'Orange Money', 'Vodacom M-Pesa'],
        'CM' => ['MTN Mobile Money', 'Orange Money'],
        'GA' => ['Airtel Money', 'Moov Africa'],
        'GQ' => ['MuniMovi'],
        'SN' => ['Orange Money', 'Wave', 'Free Money'],
        'CI' => ['Orange Money', 'MTN Mobile Money', 'Moov Money'],
        'ML' => ['Orange Money', 'Moov Africa'],
        'BF' => ['Orange Money', 'Moov Africa'],
        'NE' => ['Airtel Money', 'Moov Africa', 'Zamani Telecom'],
        'TG' => ['Togocel Money', 'Moov Africa'],
        'BJ' => ['MTN Mobile Money', 'Moov Africa'],
        'GW' => ['Orange Money'],
        'NG' => ['MTN Mobile Money', 'Airtel Money'],
        'GH' => ['MTN Mobile Money', 'Vodafone Cash', 'AirtelTigo Money'],
        'KE' => ['M-Pesa', 'Airtel Money', 'T-Kash'],
        'TZ' => ['M-Pesa', 'Airtel Money', 'Tigo Pesa'],
        'UG' => ['MTN Mobile Money', 'Airtel Money'],
        'RW' => ['MTN Mobile Money', 'Airtel Money'],
        'ZM' => ['MTN Mobile Money', 'Airtel Money', 'Zamtel Kwacha'],
        'ZA' => ['MTN MoMo', 'Vodacom M-Pesa'],
        'MA' => ['Inwi Money', 'Orange Money'],
        'TN' => ['Orange Money', 'Ooredoo Money'],
    ];

    // ── Expansion de « EU » vers les pays membres individuels ─────────────
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
        'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    // ── Données de référence : pays (nom FR, drapeau, indicatif, devise) ──
    public const COUNTRY_DATA = [
        // ── Afrique de l'Ouest ──────────────────────
        'NG' => ['name' => 'Nigéria',              'dial' => '+234', 'currency' => 'NGN', 'currency_name' => 'Naira nigérian',           'symbol' => '₦'],
        'GH' => ['name' => 'Ghana',                'dial' => '+233', 'currency' => 'GHS', 'currency_name' => 'Cedi ghanéen',             'symbol' => 'GH₵'],
        'SN' => ['name' => 'Sénégal',              'dial' => '+221', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'CI' => ['name' => "Côte d'Ivoire",        'dial' => '+225', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'ML' => ['name' => 'Mali',                 'dial' => '+223', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'BF' => ['name' => 'Burkina Faso',         'dial' => '+226', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'NE' => ['name' => 'Niger',                'dial' => '+227', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'TG' => ['name' => 'Togo',                 'dial' => '+228', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'BJ' => ['name' => 'Bénin',                'dial' => '+229', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'GW' => ['name' => 'Guinée-Bissau',        'dial' => '+245', 'currency' => 'XOF', 'currency_name' => 'Franc CFA (UEMOA)',       'symbol' => 'FCFA'],
        'GN' => ['name' => 'Guinée',               'dial' => '+224', 'currency' => 'GNF', 'currency_name' => 'Franc guinéen',           'symbol' => 'FG'],
        // ── Afrique Centrale ───────────────────────
        'CM' => ['name' => 'Cameroun',             'dial' => '+237', 'currency' => 'XAF', 'currency_name' => 'Franc CFA (CEMAC)',       'symbol' => 'FCFA'],
        'CG' => ['name' => 'Congo',                'dial' => '+242', 'currency' => 'XAF', 'currency_name' => 'Franc CFA (CEMAC)',       'symbol' => 'FCFA'],
        'CD' => ['name' => 'République démocratique du Congo', 'dial' => '+243', 'currency' => 'XAF', 'currency_name' => 'Franc CFA (CEMAC)', 'symbol' => 'FCFA'],
        'GA' => ['name' => 'Gabon',                'dial' => '+241', 'currency' => 'XAF', 'currency_name' => 'Franc CFA (CEMAC)',       'symbol' => 'FCFA'],
        'GQ' => ['name' => 'Guinée équatoriale',   'dial' => '+240', 'currency' => 'XAF', 'currency_name' => 'Franc CFA (CEMAC)',       'symbol' => 'FCFA'],
        // ── Afrique de l'Est ───────────────────────
        'KE' => ['name' => 'Kenya',                'dial' => '+254', 'currency' => 'KES', 'currency_name' => 'Shilling kényan',         'symbol' => 'KSh'],
        'UG' => ['name' => 'Ouganda',              'dial' => '+256', 'currency' => 'UGX', 'currency_name' => 'Shilling ougandais',      'symbol' => 'UGX'],
        'TZ' => ['name' => 'Tanzanie',             'dial' => '+255', 'currency' => 'TZS', 'currency_name' => 'Shilling tanzanien',      'symbol' => 'TSh'],
        'RW' => ['name' => 'Rwanda',               'dial' => '+250', 'currency' => 'RWF', 'currency_name' => 'Franc rwandais',          'symbol' => 'FRw'],
        'ZM' => ['name' => 'Zambie',               'dial' => '+260', 'currency' => 'ZMW', 'currency_name' => 'Kwacha zambien',          'symbol' => 'ZK'],
        // ── Afrique Australe ───────────────────────
        'ZA' => ['name' => 'Afrique du Sud',       'dial' => '+27',  'currency' => 'ZAR', 'currency_name' => 'Rand sud-africain',       'symbol' => 'R'],
        // ── Maghreb ────────────────────────────────
        'MA' => ['name' => 'Maroc',                'dial' => '+212', 'currency' => 'MAD', 'currency_name' => 'Dirham marocain',         'symbol' => 'MAD'],
        'TN' => ['name' => 'Tunisie',              'dial' => '+216', 'currency' => 'TND', 'currency_name' => 'Dinar tunisien',          'symbol' => 'DT'],
        // ── Moyen-Orient ───────────────────────────
        'JO' => ['name' => 'Jordanie',             'dial' => '+962', 'currency' => 'JOD', 'currency_name' => 'Dinar jordanien',         'symbol' => 'JOD'],
        // ── Europe (directe) ───────────────────────
        'FR' => ['name' => 'France',               'dial' => '+33',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'DE' => ['name' => 'Allemagne',            'dial' => '+49',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'ES' => ['name' => 'Espagne',              'dial' => '+34',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'IT' => ['name' => 'Italie',               'dial' => '+39',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'NL' => ['name' => 'Pays-Bas',             'dial' => '+31',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'BE' => ['name' => 'Belgique',             'dial' => '+32',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'LU' => ['name' => 'Luxembourg',           'dial' => '+352', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'AT' => ['name' => 'Autriche',             'dial' => '+43',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'PT' => ['name' => 'Portugal',             'dial' => '+351', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'IE' => ['name' => 'Irlande',              'dial' => '+353', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'FI' => ['name' => 'Finlande',             'dial' => '+358', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'GR' => ['name' => 'Grèce',                'dial' => '+30',  'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'GB' => ['name' => 'Royaume-Uni',          'dial' => '+44',  'currency' => 'GBP', 'currency_name' => 'Livre sterling',          'symbol' => '£'],
        // ── Europe (expansion EU) ──────────────────
        'CY' => ['name' => 'Chypre',               'dial' => '+357', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'CZ' => ['name' => 'République tchèque',   'dial' => '+420', 'currency' => 'CZK', 'currency_name' => 'Couronne tchèque',        'symbol' => 'CZK'],
        'DK' => ['name' => 'Danemark',             'dial' => '+45',  'currency' => 'DKK', 'currency_name' => 'Couronne danoise',        'symbol' => 'DKK'],
        'EE' => ['name' => 'Estonie',              'dial' => '+372', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'HR' => ['name' => 'Croatie',              'dial' => '+385', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'HU' => ['name' => 'Hongrie',              'dial' => '+36',  'currency' => 'HUF', 'currency_name' => 'Forint hongrois',         'symbol' => 'Ft'],
        'LT' => ['name' => 'Lituanie',             'dial' => '+370', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'LV' => ['name' => 'Lettonie',             'dial' => '+371', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'MT' => ['name' => 'Malte',                'dial' => '+356', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'PL' => ['name' => 'Pologne',              'dial' => '+48',  'currency' => 'PLN', 'currency_name' => 'Zloty polonais',          'symbol' => 'zł'],
        'RO' => ['name' => 'Roumanie',             'dial' => '+40',  'currency' => 'RON', 'currency_name' => 'Leu roumain',             'symbol' => 'lei'],
        'SE' => ['name' => 'Suède',                'dial' => '+46',  'currency' => 'SEK', 'currency_name' => 'Couronne suédoise',       'symbol' => 'SEK'],
        'SI' => ['name' => 'Slovénie',             'dial' => '+386', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'SK' => ['name' => 'Slovaquie',            'dial' => '+421', 'currency' => 'EUR', 'currency_name' => 'Euro',                   'symbol' => '€'],
        'BG' => ['name' => 'Bulgarie',             'dial' => '+359', 'currency' => 'BGN', 'currency_name' => 'Lev bulgare',             'symbol' => 'лв'],
        // ── Amériques ──────────────────────────────
        'US' => ['name' => 'États-Unis',           'dial' => '+1',   'currency' => 'USD', 'currency_name' => 'Dollar américain',        'symbol' => '$'],
        'CA' => ['name' => 'Canada',               'dial' => '+1',   'currency' => 'CAD', 'currency_name' => 'Dollar canadien',         'symbol' => 'CA$'],
        'BR' => ['name' => 'Brésil',               'dial' => '+55',  'currency' => 'BRL', 'currency_name' => 'Réal brésilien',          'symbol' => 'R$'],
        'MX' => ['name' => 'Mexique',              'dial' => '+52',  'currency' => 'MXN', 'currency_name' => 'Peso mexicain',           'symbol' => 'MX$'],
        'CO' => ['name' => 'Colombie',             'dial' => '+57',  'currency' => 'COP', 'currency_name' => 'Peso colombien',          'symbol' => 'CO$'],
        'AR' => ['name' => 'Argentine',            'dial' => '+54',  'currency' => 'ARS', 'currency_name' => 'Peso argentin',           'symbol' => 'AR$'],
        'CL' => ['name' => 'Chili',                'dial' => '+56',  'currency' => 'CLP', 'currency_name' => 'Peso chilien',            'symbol' => 'CL$'],
        'PE' => ['name' => 'Pérou',                'dial' => '+51',  'currency' => 'PEN', 'currency_name' => 'Sol péruvien',            'symbol' => 'S/'],
        'UY' => ['name' => 'Uruguay',              'dial' => '+598', 'currency' => 'UYU', 'currency_name' => 'Peso uruguayen',          'symbol' => '$U'],
        // ── Asie-Pacifique ─────────────────────────
        'AU' => ['name' => 'Australie',            'dial' => '+61',  'currency' => 'AUD', 'currency_name' => 'Dollar australien',       'symbol' => 'A$'],
        'SG' => ['name' => 'Singapour',            'dial' => '+65',  'currency' => 'SGD', 'currency_name' => 'Dollar de Singapour',     'symbol' => 'S$'],
        'CN' => ['name' => 'Chine',                'dial' => '+86',  'currency' => 'CNY', 'currency_name' => 'Yuan chinois',            'symbol' => '¥'],
        'JP' => ['name' => 'Japon',                'dial' => '+81',  'currency' => 'JPY', 'currency_name' => 'Yen japonais',            'symbol' => '¥'],
        'ID' => ['name' => 'Indonésie',            'dial' => '+62',  'currency' => 'IDR', 'currency_name' => 'Rupiah indonésien',       'symbol' => 'Rp'],
        'PH' => ['name' => 'Philippines',          'dial' => '+63',  'currency' => 'PHP', 'currency_name' => 'Peso philippin',          'symbol' => '₱'],
        'MY' => ['name' => 'Malaisie',             'dial' => '+60',  'currency' => 'MYR', 'currency_name' => 'Ringgit malaisien',       'symbol' => 'RM'],
        'TH' => ['name' => 'Thaïlande',            'dial' => '+66',  'currency' => 'THB', 'currency_name' => 'Baht thaïlandais',        'symbol' => '฿'],
    ];

    private function __construct() {}

    /**
     * Retourne la couverture complète des providers : pays, devises,
     * modes de réception, opérateurs et taux de conversion.
     *
     * AUCUN TAUX DE DÉMONSTRATION (§7) : `rates` ne contient que les taux
     * RÉELS disponibles dans l'environnement donné (devises de référence du
     * portefeuille, EUR = identité). Une devise sans taux n'y figure pas ;
     * l'estimation du formulaire affiche alors « non disponible » jusqu'au
     * calcul réel des routes.
     *
     * @return array{countries: list<array>, source_currencies: list<string>, crypto_networks: list<string>, rates: array<string, float>}
     */
    public static function coverage(?\Nexus\Execution\ExecutionEnvironment $environment = null): array
    {
        // 1. Collecter la couverture de chaque provider (avec expansion EU)
        $countryProviders = []; // countryCode => [slug => providerInfo]
        foreach (ProviderCatalog::all() as $slug => $provider) {
            $expandedCountries = self::expandCountries($provider['countries']);
            foreach ($expandedCountries as $cc) {
                if (!isset($countryProviders[$cc])) {
                    $countryProviders[$cc] = [];
                }
                $countryProviders[$cc][$slug] = [
                    'name'     => $provider['name'],
                    'category' => $provider['category'],
                ];
            }
        }

        // 2. Construire la liste des pays avec données de référence
        $countries = [];
        foreach (array_keys($countryProviders) as $cc) {
            $ref = self::COUNTRY_DATA[$cc] ?? null;
            if ($ref === null) {
                // Pays couvert par un provider mais sans données de référence
                // → on génère un nom par défaut à partir du code
                $ref = [
                    'name'          => $cc,
                    'dial'          => '',
                    'currency'      => 'USD',
                    'currency_name' => 'Dollar',
                    'symbol'        => '$',
                ];
            }

            // Devise légale locale + actifs crypto (USDT / USDC / ETH / BTC).
            $methods = self::getMethodsForCountry($cc, $countryProviders[$cc]);
            $currencies = [[
                'code'    => $ref['currency'],
                'name'    => $ref['currency_name'],
                'symbol'  => $ref['symbol'],
                'methods' => $methods,
            ]];

            $cryptoMethod = null;
            foreach ($methods as $method) {
                if (($method['type'] ?? '') === 'crypto') {
                    $cryptoMethod = $method;
                    break;
                }
            }
            if ($cryptoMethod === null) {
                $cryptoMethod = [
                    'type'      => 'crypto',
                    'label'     => self::METHOD_LABELS['crypto'],
                    'icon'      => self::METHOD_ICONS['crypto'],
                    'providers' => [],
                    'operators' => null,
                ];
            }

            $existingCodes = [$ref['currency']];
            foreach (self::CRYPTO_DEST_ASSETS as $asset) {
                if (in_array($asset['code'], $existingCodes, true)) {
                    continue;
                }
                $currencies[] = [
                    'code'    => $asset['code'],
                    'name'    => $asset['name'],
                    'symbol'  => $asset['symbol'],
                    'methods' => [$cryptoMethod],
                ];
                $existingCodes[] = $asset['code'];
            }

            // Si le pays a une monnaie CFA, on pourrait aussi proposer USD
            // comme alternative pour les corridors internationaux.
            // Pour le MVP, on garde la devise locale + cryptos.

            $countries[] = [
                'code'      => $cc,
                'name'      => $ref['name'],
                'flag'      => self::flagEmoji($cc),
                'dial'      => $ref['dial'],
                'currencies' => $currencies,
            ];
        }

        // Tri alphabétique par nom
        usort($countries, static fn (array $a, array $b): int => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        // Taux RÉELS de l'environnement (jamais de tableau statique, §7) :
        // seules les paires résolues par la source FX y figurent.
        $environment ??= \Nexus\Execution\ExecutionEnvironment::fromString(
            \Nexus\Providers\ProviderConfig::defaultEnvironment()
        );
        $rates = [];
        // Les devises proposées à la réception doivent pouvoir afficher un
        // taux réel quand le cache FX en contient un, même si l'actif n'est
        // pas (encore) un wallet Nexus. Sinon ETH/BTC étaient proposés sans
        // jamais pouvoir refléter leur taux disponible.
        $rateCurrencies = Currency::WALLET_CURRENCIES;
        foreach (self::CRYPTO_DEST_ASSETS as $asset) {
            $rateCurrencies[] = $asset['code'];
        }
        foreach (array_values(array_unique($rateCurrencies)) as $currency) {
            $rate = \Nexus\Services\FXService::rateToRef($currency, $environment);
            if ($rate !== null) {
                $rates[$currency] = $rate;
            }
        }

        return [
            'countries'         => $countries,
            'source_currencies' => Currency::WALLET_CURRENCIES,
            'crypto_networks'   => [
                'Ethereum', 'Polygon', 'Arbitrum', 'Optimism',
                'BNB Smart Chain', 'Tron', 'Solana', 'Bitcoin', 'Base',
            ],
            'rates'             => $rates,
        ];
    }

    /** Un actif crypto est global : il ne dépend pas de la devise locale du pays. */
    public static function isCryptoDestination(string $currency): bool
    {
        $currency = strtoupper(trim($currency));
        foreach (self::CRYPTO_DEST_ASSETS as $asset) {
            if ($currency === $asset['code']) {
                return true;
            }
        }
        return false;
    }

    // ── Méthodes privées ─────────────────────────────────────────────────

    /**
     * Déplie les codes « EU » en pays individuels (EU-27).
     *
     * @param list<string> $codes
     * @return list<string>
     */
    private static function expandCountries(array $codes): array
    {
        $expanded = [];
        foreach ($codes as $code) {
            if ($code === 'EU') {
                foreach (self::EU_COUNTRIES as $eu) {
                    $expanded[$eu] = true;
                }
            } else {
                $expanded[$code] = true;
            }
        }
        return array_keys($expanded);
    }

    /**
     * Calcule les modes de réception disponibles pour un pays donné,
     * en fonction des providers qui le couvrent.
     *
     * @param array<string, array{name: string, category: string}> $providers
     * @return list<array{type: string, label: string, icon: string, providers: list<string>, operators?: list<string>}>
     */
    private static function getMethodsForCountry(string $countryCode, array $providers): array
    {
        $methods = []; // type => info

        foreach ($providers as $slug => $provider) {
            $category = $provider['category'];
            $methodTypes = self::CATEGORY_TO_METHODS[$category] ?? [];

            foreach ($methodTypes as $methodType) {
                if (!isset($methods[$methodType])) {
                    $operators = null;
                    if ($methodType === 'mobile_money') {
                        $operators = self::MOBILE_MONEY_OPERATORS[$countryCode] ?? null;
                    }
                    $methods[$methodType] = [
                        'type'      => $methodType,
                        'label'     => self::METHOD_LABELS[$methodType] ?? $methodType,
                        'icon'      => self::METHOD_ICONS[$methodType] ?? '🌐',
                        'providers' => [],
                        'operators' => $operators,
                    ];
                }
                $methods[$methodType]['providers'][] = $provider['name'];
            }
        }

        return array_values($methods);
    }

    /**
     * Génère l'emoji drapeau à partir d'un code ISO-3166-1 alpha-2.
     *
     * Utilise les symboles régionaux Unicode (Regional Indicator Symbols).
     */
    private static function flagEmoji(string $code): string
    {
        $code = strtoupper($code);
        if (strlen($code) !== 2) {
            return '🌍';
        }
        $first  = mb_chr(ord($code[0]) - ord('A') + 0x1F1E6);
        $second = mb_chr(ord($code[1]) - ord('A') + 0x1F1E6);
        return ($first !== false && $second !== false) ? $first . $second : '🌍';
    }
}
