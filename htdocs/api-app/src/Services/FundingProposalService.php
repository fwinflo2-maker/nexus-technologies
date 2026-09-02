<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\HttpException;
use Nexus\Providers\ProviderConfig;

/**
 * FundingProposalService — rails de collecte dérivés du ProviderCatalog NEXUS.
 *
 * Règle d’or : un pays ne voit QUE les providers dont `countries` le couvre.
 * Ex. France → Swan / Wise / Stripe / SEPA — JAMAIS MTN ou Orange MoMo
 * (réservés à l’Afrique via mobile money / Orange Money).
 *
 * Sources vérifiées :
 *   - Mobile money : Afrique uniquement (pas EU/FR)
 *   - SEPA / cartes EU : Swan, Wise, Stripe, Currencycloud, BVNK
 */
final class FundingProposalService
{
    /**
     * Catégories du catalogue autorisées pour un DÉPÔT (pay-in) utilisateur.
     * Exclut compliance, card_issuing, payout_network (souvent sortant).
     */
    private const DEPOSIT_CATEGORIES = [
        'mobile_money'   => true,
        'banking'        => true,
        'fx'             => true,
        'payout_network' => true, // Western Union cash pickup / cashout
        'cards'          => true,
        'crypto'         => true,
        'onramp'         => true,
        'wallet'         => true,
    ];

    /**
     * Opérateurs MoMo par pays (ISO-2) pour enrichir MTN / Orange / Airtel.
     * Codes ISO-2 : COG=CG, COD=CD, CIV=CI…
     *
     * @var array<string, list<array{operator: string, label: string, currency: string}>>
     */
    private const MOMO_OPERATORS = [
        'CG' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'XAF'],
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'XAF'],
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'XAF'],
        ],
        'CD' => [
            ['operator' => 'Vodacom', 'label' => 'M-Pesa (Vodacom)', 'currency' => 'CDF'],
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'CDF'],
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'CDF'],
        ],
        'CM' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'XAF'],
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'XAF'],
        ],
        'GA' => [
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'XAF'],
        ],
        'CI' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'XOF'],
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'XOF'],
            ['operator' => 'Wave', 'label' => 'Wave', 'currency' => 'XOF'],
        ],
        'SN' => [
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'XOF'],
            ['operator' => 'Wave', 'label' => 'Wave', 'currency' => 'XOF'],
            ['operator' => 'Free', 'label' => 'Free Money', 'currency' => 'XOF'],
        ],
        'BF' => [
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'XOF'],
            ['operator' => 'Moov', 'label' => 'Moov Money', 'currency' => 'XOF'],
        ],
        'BJ' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'XOF'],
            ['operator' => 'Moov', 'label' => 'Moov Money', 'currency' => 'XOF'],
        ],
        'GH' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'GHS'],
            ['operator' => 'Vodafone', 'label' => 'Vodafone Cash', 'currency' => 'GHS'],
            ['operator' => 'AirtelTigo', 'label' => 'AirtelTigo Money', 'currency' => 'GHS'],
        ],
        'NG' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'NGN'],
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'NGN'],
        ],
        'KE' => [
            ['operator' => 'Safaricom', 'label' => 'M-Pesa', 'currency' => 'KES'],
        ],
        'UG' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'UGX'],
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'UGX'],
        ],
        'RW' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'RWF'],
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'RWF'],
        ],
        'ZM' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'ZMW'],
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'ZMW'],
        ],
        'TZ' => [
            ['operator' => 'Vodacom', 'label' => 'M-Pesa', 'currency' => 'TZS'],
            ['operator' => 'Airtel', 'label' => 'Airtel Money', 'currency' => 'TZS'],
            ['operator' => 'Tigo', 'label' => 'Tigo Pesa', 'currency' => 'TZS'],
        ],
        'NE' => [
            ['operator' => 'MTN', 'label' => 'MTN Mobile Money', 'currency' => 'XOF'],
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'XOF'],
        ],
        'ML' => [
            ['operator' => 'Orange', 'label' => 'Orange Money', 'currency' => 'XOF'],
        ],
        'TG' => [
            ['operator' => 'Moov', 'label' => 'Moov Money', 'currency' => 'XOF'],
        ],
    ];

    /** Devise locale par défaut si non fournie par l’opérateur. */
    private const COUNTRY_CURRENCY = [
        'FR' => 'EUR', 'DE' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR', 'BE' => 'EUR',
        'NL' => 'EUR', 'PT' => 'EUR', 'IE' => 'EUR', 'AT' => 'EUR', 'LU' => 'EUR',
        'FI' => 'EUR', 'GR' => 'EUR', 'GB' => 'GBP', 'US' => 'USD', 'CA' => 'CAD',
        'CG' => 'XAF', 'CM' => 'XAF', 'GA' => 'XAF', 'CD' => 'CDF',
        'SN' => 'XOF', 'CI' => 'XOF', 'BF' => 'XOF', 'BJ' => 'XOF', 'ML' => 'XOF',
        'NE' => 'XOF', 'TG' => 'XOF', 'NG' => 'NGN', 'GH' => 'GHS', 'KE' => 'KES',
        'UG' => 'UGX', 'RW' => 'RWF', 'TZ' => 'TZS', 'ZM' => 'ZMW', 'ZA' => 'ZAR',
        'BR' => 'BRL', 'MX' => 'MXN', 'IN' => 'INR', 'SG' => 'SGD', 'AU' => 'AUD',
        'AE' => 'AED', 'CN' => 'CNY', 'JP' => 'JPY',
    ];

    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
        'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    private const CEMAC = ['CG', 'CM', 'GA', 'GQ', 'CF', 'TD'];
    private const UEMOA = ['SN', 'CI', 'BF', 'BJ', 'ML', 'NE', 'TG', 'GW'];
    private const CRYPTO_DEPOSIT = ['USDT', 'USDC', 'ETH', 'BTC'];

    private function __construct()
    {
    }

    /**
     * Devises de dépôt autorisées pour un pays d’enregistrement.
     * FR → EUR/USD/GBP + crypto (pas XAF/XOF).
     *
     * @return list<string>
     */
    public static function depositCurrenciesForCountry(string $country): array
    {
        $cc = strtoupper(trim($country));
        if (strlen($cc) !== 2) {
            return array_merge(['EUR', 'USD'], self::CRYPTO_DEPOSIT);
        }

        $fiat = [];
        if (in_array($cc, self::EU_COUNTRIES, true)) {
            $fiat = ['EUR', 'USD', 'GBP'];
        } elseif ($cc === 'GB') {
            $fiat = ['GBP', 'EUR', 'USD'];
        } elseif ($cc === 'US' || $cc === 'CA') {
            $fiat = ['USD', 'EUR'];
        } elseif (in_array($cc, self::CEMAC, true) || $cc === 'CD') {
            $fiat = [$cc === 'CD' ? 'CDF' : 'XAF', 'EUR', 'USD'];
        } elseif (in_array($cc, self::UEMOA, true)) {
            $fiat = ['XOF', 'EUR', 'USD'];
        } elseif ($cc === 'NG') {
            $fiat = ['NGN', 'USD', 'EUR'];
        } elseif ($cc === 'GH') {
            $fiat = ['GHS', 'USD', 'EUR'];
        } elseif ($cc === 'KE') {
            $fiat = ['KES', 'USD', 'EUR'];
        } elseif ($cc === 'ZA') {
            $fiat = ['ZAR', 'USD', 'EUR'];
        } else {
            $local = self::COUNTRY_CURRENCY[$cc] ?? null;
            if ($local !== null) {
                $fiat[] = $local;
            }
            $fiat[] = 'USD';
            $fiat[] = 'EUR';
        }

        return array_values(array_unique(array_merge($fiat, self::CRYPTO_DEPOSIT)));
    }

    public static function isDepositCurrencyAllowed(string $country, string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), self::depositCurrenciesForCountry($country), true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function registrationCountry(array $user): ?string
    {
        $cc = strtoupper(trim((string) ($user['country_of_residence'] ?? '')));
        return strlen($cc) === 2 ? $cc : null;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{
     *   country: string|null,
     *   currency_requested: string|null,
     *   default_currency: string|null,
     *   deposit_currencies: list<string>,
     *   sandbox: bool,
     *   message: string|null,
     *   proposals: list<array<string, mixed>>
     * }
     */
    public static function listForUser(array $user, ?string $currencyRequested = null): array
    {
        $country = self::registrationCountry($user);
        $currency = $currencyRequested !== null && $currencyRequested !== ''
            ? strtoupper(trim($currencyRequested))
            : null;
        $sandbox = self::isSandbox();

        if ($country === null) {
            return [
                'country'             => null,
                'currency_requested'  => $currency,
                'default_currency'    => null,
                'deposit_currencies'  => [],
                'sandbox'             => $sandbox,
                'message'             => 'Complétez le pays d’enregistrement (KYC) pour voir les moyens de dépôt.',
                'proposals'           => [],
            ];
        }

        $depositCurrencies = self::depositCurrenciesForCountry($country);
        $defaultCurrency   = self::COUNTRY_CURRENCY[$country]
            ?? (in_array($country, self::EU_COUNTRIES, true) ? 'EUR' : 'USD');

        if ($currency !== null && !self::isDepositCurrencyAllowed($country, $currency)) {
            return [
                'country'             => $country,
                'currency_requested'  => $currency,
                'default_currency'    => $defaultCurrency,
                'deposit_currencies'  => $depositCurrencies,
                'sandbox'             => $sandbox,
                'message'             => sprintf(
                    'La devise %s n’est pas disponible pour un dépôt depuis %s.',
                    $currency,
                    $country
                ),
                'proposals'           => [],
            ];
        }

        $proposals = self::buildProposalsForCountry($country, $currency, $sandbox);

        // Fiat → rails bancaires / MoMo / carte ; crypto → rails crypto uniquement.
        if ($currency !== null) {
            $wantCrypto = in_array($currency, self::CRYPTO_DEPOSIT, true);
            $proposals = array_values(array_filter(
                $proposals,
                static function (array $p) use ($wantCrypto): bool {
                    $method = (string) ($p['method'] ?? '');
                    return $wantCrypto ? $method === 'crypto' : $method !== 'crypto';
                }
            ));
        }

        return [
            'country'             => $country,
            'currency_requested'  => $currency,
            'default_currency'    => $defaultCurrency,
            'deposit_currencies'  => $depositCurrencies,
            'sandbox'             => $sandbox,
            'message'             => count($proposals) === 0
                ? sprintf('Aucun rail de dépôt NEXUS n’est disponible pour %s parmi vos providers.', $country)
                : null,
            'proposals'           => $proposals,
        ];
    }

    /**
     * Modes de paiement / kinds de compte autorisés pour un pays
     * (création de compte ou pays saisi dans un formulaire).
     *
     * @return array{
     *   country: string,
     *   methods: list<string>,
     *   account_kinds: array{source: list<string>, destination: list<string>},
     *   default_currency: string,
     *   has_mobile_money: bool
     * }
     */
    public static function availablePaymentModes(string $country): array
    {
        $country = strtoupper(trim($country));
        $proposals = self::buildProposalsForCountry($country, null, self::isSandbox());
        $methods = [];
        foreach ($proposals as $p) {
            $m = (string) ($p['method'] ?? '');
            if ($m !== '') {
                $methods[$m] = true;
            }
        }
        // Crypto toujours proposable comme wallet destination / source optionnelle.
        $methods['crypto'] = true;

        $hasMomo = isset($methods['mobile_money']);
        $hasBank = isset($methods['bank']);
        $hasCard = isset($methods['card']);

        $sourceKinds = [];
        $destKinds = [];
        if ($hasBank) {
            $sourceKinds[] = 'bank_iban';
            $sourceKinds[] = 'virtual_iban';
            $destKinds[] = 'bank_iban';
        }
        if ($hasMomo) {
            $sourceKinds[] = 'mobile_money';
            $destKinds[] = 'mobile_money';
        }
        if ($hasCard) {
            $sourceKinds[] = 'card';
        }
        $sourceKinds[] = 'crypto_wallet';
        $destKinds[] = 'crypto_wallet';

        // Cash pickup / cashout : Western Union (et réseaux agents) — disponible
        // dès qu'un provider payout_network/fx cash-pickup couvre le pays, ou
        // en secours pour les corridors hors banque pure.
        $hasCashPickup = isset($methods['cash_pickup']);
        if (!$hasCashPickup) {
            // Toujours proposer le rail cash_pickup en destination : WU couvre
            // la plupart des marchés (catalogue western_union).
            $methods['cash_pickup'] = true;
            $hasCashPickup = true;
        }
        if ($hasCashPickup) {
            $destKinds[] = 'cash_pickup';
            // Cashout wallet → agent : source cash_pickup pour retirer en espèces.
            $sourceKinds[] = 'cash_pickup';
        }

        // Fallback minimal si aucun provider ne couvre le pays.
        if ($sourceKinds === [] || $sourceKinds === ['crypto_wallet']) {
            $sourceKinds = ['bank_iban', 'crypto_wallet'];
            if (!in_array('bank_iban', $destKinds, true)) {
                $destKinds[] = 'bank_iban';
            }
            $methods['bank'] = true;
        }

        return [
            'country'          => $country,
            'methods'          => array_values(array_keys($methods)),
            'account_kinds'    => [
                'source'      => array_values(array_unique($sourceKinds)),
                'destination' => array_values(array_unique($destKinds)),
            ],
            'default_currency' => self::COUNTRY_CURRENCY[$country] ?? 'EUR',
            'has_mobile_money' => $hasMomo,
        ];
    }

    /** Le kind de compte payment_accounts est-il autorisé pour ce pays ? */
    public static function isAccountKindAllowed(string $country, string $kind, string $role = 'source'): bool
    {
        $modes = self::availablePaymentModes($country);
        $list = $modes['account_kinds'][$role === 'destination' ? 'destination' : 'source'] ?? [];
        return in_array($kind, $list, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function resolveForUser(array $user, string $proposalId): array
    {
        $list = self::listForUser($user);
        foreach ($list['proposals'] as $p) {
            if (($p['id'] ?? '') === $proposalId) {
                return $p;
            }
        }
        throw new HttpException(404, 'Proposition de dépôt introuvable pour votre pays.', 'FUNDING_PROPOSAL_NOT_FOUND');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildProposalsForCountry(string $country, ?string $walletCurrency, bool $sandbox): array
    {
        $proposals = [];
        $seen = [];

        foreach (ProviderCatalog::all() as $slug => $provider) {
            $category = (string) ($provider['category'] ?? '');
            if (!isset(self::DEPOSIT_CATEGORIES[$category])) {
                continue;
            }
            if (!self::providerCoversCountry($provider['countries'] ?? [], $country)) {
                continue;
            }

            // MoMo : détailler les opérateurs locaux (pas un seul tile générique).
            if ($category === 'mobile_money' && isset(self::MOMO_OPERATORS[$country])) {
                foreach (self::MOMO_OPERATORS[$country] as $op) {
                    // Filtrer opérateurs selon le provider (MTN slug → MTN only, etc.).
                    if (!self::operatorMatchesProvider($slug, (string) $op['operator'])) {
                        continue;
                    }
                    $id = $slug . '_' . strtolower((string) $op['operator']) . '_' . strtolower($country);
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $proposals[] = self::formatProposal([
                        'id'             => $id,
                        'provider_slug'  => $slug,
                        'method'         => 'mobile_money',
                        'label'          => $op['label'] . ' · ' . ($provider['name'] ?? $slug),
                        'operator'       => $op['operator'],
                        'local_currency' => $op['currency'],
                        'fee_pct'        => 1.5,
                        'eta'            => 5,
                    ], $sandbox, $walletCurrency);
                }
                continue;
            }

            $method = self::methodForCategory($category, $slug);
            $id = $slug . '_' . strtolower($country);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $proposals[] = self::formatProposal([
                'id'             => $id,
                'provider_slug'  => $slug,
                'method'         => $method,
                'label'          => self::labelForProvider($slug, $provider, $method, $country),
                'operator'       => null,
                'local_currency' => self::COUNTRY_CURRENCY[$country] ?? ($walletCurrency ?? 'EUR'),
                'fee_pct'        => self::defaultFee($method),
                'eta'            => self::defaultEta($method),
            ], $sandbox, $walletCurrency);
        }

        // Ordre UX : banque / SEPA d’abord en Europe, MoMo d’abord en Afrique.
        usort($proposals, static function (array $a, array $b) use ($country): int {
            $prio = static function (array $p) use ($country): int {
                $m = $p['method'] ?? '';
                if (in_array($country, self::EU_COUNTRIES, true) || $country === 'GB' || $country === 'CH') {
                    return match ($m) {
                        'bank' => 0,
                        'card' => 1,
                        'crypto' => 2,
                        default => 9,
                    };
                }
                return match ($m) {
                    'mobile_money' => 0,
                    'bank' => 1,
                    'card' => 2,
                    default => 9,
                };
            };
            return $prio($a) <=> $prio($b);
        });

        return $proposals;
    }

    /**
     * @param list<string> $codes
     */
    private static function providerCoversCountry(array $codes, string $country): bool
    {
        foreach ($codes as $code) {
            $code = strtoupper((string) $code);
            if ($code === $country) {
                return true;
            }
            if ($code === 'EU' && in_array($country, self::EU_COUNTRIES, true)) {
                return true;
            }
        }
        return false;
    }

    private static function operatorMatchesProvider(string $slug, string $operator): bool
    {
        $op = strtoupper($operator);
        return match ($slug) {
            'mtn_momo' => $op === 'MTN',
            'orange_money' => $op === 'ORANGE',
            'safaricom_mpesa' => in_array($op, ['SAFARICOM', 'MPESA', 'VODACOM'], true),
            'yellow_card' => true,
            default => true,
        };
    }

    private static function methodForCategory(string $category, string $slug): string
    {
        if ($slug === 'western_union' || $slug === 'moneygram') {
            return 'cash_pickup';
        }
        return match ($category) {
            'mobile_money' => 'mobile_money',
            'payout_network' => 'cash_pickup',
            'banking', 'fx' => 'bank',
            'cards' => 'card',
            'crypto', 'onramp' => 'crypto',
            'wallet' => 'bank',
            default => 'bank',
        };
    }

    /**
     * @param array<string, mixed> $provider
     */
    private static function labelForProvider(string $slug, array $provider, string $method, string $country): string
    {
        $name = (string) ($provider['name'] ?? $slug);
        if ($slug === 'western_union' || $slug === 'moneygram' || $method === 'cash_pickup') {
            return 'Cash pickup / cashout · ' . $name;
        }
        if ($method === 'bank' && (in_array($country, self::EU_COUNTRIES, true) || $country === 'GB')) {
            return match ($slug) {
                'swan' => 'Virement SEPA · Swan',
                'wise' => 'Compte local / SEPA · Wise',
                'currencycloud' => 'Virement · Currencycloud',
                'bvnk' => 'Compte bancaire · BVNK',
                'modulr' => 'Virement · Modulr',
                default => 'Virement bancaire · ' . $name,
            };
        }
        if ($method === 'card') {
            return 'Carte bancaire · ' . $name;
        }
        if ($method === 'crypto') {
            return 'Crypto / stablecoin · ' . $name;
        }
        return $name;
    }

    private static function defaultFee(string $method): float
    {
        return match ($method) {
            'mobile_money' => 1.5,
            'cash_pickup' => 2.5,
            'card' => 1.8,
            'crypto' => 1.0,
            default => 0.3,
        };
    }

    private static function defaultEta(string $method): int
    {
        return match ($method) {
            'mobile_money' => 5,
            'card' => 2,
            'crypto' => 15,
            default => 60,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatProposal(array $row, bool $sandbox, ?string $walletCurrency): array
    {
        $method = (string) $row['method'];
        return [
            'id'                 => (string) $row['id'],
            'provider_slug'      => (string) $row['provider_slug'],
            'method'             => $method,
            'label'              => (string) $row['label'],
            'operator'           => $row['operator'] ?? null,
            'local_currency'     => (string) $row['local_currency'],
            'wallet_currency'    => $walletCurrency,
            'estimated_fee_pct'  => (float) $row['fee_pct'],
            'eta_minutes'        => (int) $row['eta'],
            'sandbox'            => $sandbox,
            'requires_reference' => in_array($method, ['mobile_money', 'bank'], true),
        ];
    }

    private static function isSandbox(): bool
    {
        $appEnv = defined('APP_ENV') ? (string) APP_ENV : (string) (getenv('APP_ENV') ?: '');
        return strtolower(trim($appEnv)) !== 'production'
            && ProviderConfig::defaultEnvironment() !== 'production';
    }
}
