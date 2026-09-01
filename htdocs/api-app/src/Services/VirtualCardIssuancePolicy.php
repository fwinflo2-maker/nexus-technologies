<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * Règles d'émission d'une carte virtuelle :
 *
 *   - identité vérifiée (KYC documentaire / KYB) avant toute émission ;
 *   - adresse de facturation complète (exigence Stripe Issuing) ;
 *   - 1,00 USD débité du solde à chaque génération (anti-abus) ;
 *   - plafond mensuel obligatoire, calé sur le cadre AML / changes du pays ;
 *   - les paiements restent débités du solde (pas de préchargement).
 *
 * Les plafonds ne prétendent pas coller à un article de loi unique (la plupart
 * des pays n'imposent pas de cap statutaire par carte après KYC complet). Ils
 * reprennent les plafonds opérationnels usuels : barème KYC UE (PolicyEngine),
 * vélocité AML US/CA/UK, et plafonds FX plus serrés en Afrique (CBN, BCEAO,
 * BEAC, BoG, CBK).
 */
final class VirtualCardIssuancePolicy
{
    /** Cartes non annulées max par compte (virtuelles). */
    public const MAX_OPEN_CARDS = 5;

    /** Frais de génération — 1 USD, pour éviter les émissions gratuites en série. */
    public const ISSUANCE_FEE = '1.00';

    public const FEE_CURRENCY = 'USD';

    /**
     * Pays de facturation Stripe Issuing (ISO-2).
     * Doc : https://docs.stripe.com/issuing — US, UK, EEE (+ CH/NO/LI/IS).
     *
     * @var list<string>
     */
    public const ISSUING_BILLING_COUNTRIES = [
        'AT', 'BE', 'BG', 'CA', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GB', 'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MT',
        'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'US',
    ];

    /**
     * Pays d'émission Maplerad (ISO-2) — BaaS Afrique, pas Paysika (B2C).
     * Couverture publique : NG, GH, KE, CI, BJ, CM, UG, TZ.
     *
     * @var list<string>
     */
    public const MAPLERAD_BILLING_COUNTRIES = [
        'BJ', 'CI', 'CM', 'GH', 'KE', 'NG', 'TZ', 'UG',
    ];

    /** @var list<string> */
    public const BILLING_FIELDS = [
        'full_name',
        'country_of_residence',
        'address',
        'city',
        'postal_code',
    ];

    private function __construct() {}

    /**
     * Plafonds mensuels (devise de la carte) — standard / advanced.
     * EEE : barème PolicyEngine (2 000 / 10 000 EUR) ; USD/GBP : même ordre.
     * Afrique : USD uniquement, plafonds FX plus bas.
     *
     * @var array<string, array{EUR?:array{0:float,1:float},USD?:array{0:float,1:float},GBP?:array{0:float,1:float},basis:string}>
     */
    private const COUNTRY_MONTHLY_CAPS = [
        'US' => ['USD' => [2500.0, 10000.0], 'EUR' => [2000.0, 10000.0], 'GBP' => [2000.0, 8500.0], 'basis' => 'US_AML'],
        'CA' => ['USD' => [2500.0, 10000.0], 'EUR' => [2000.0, 10000.0], 'GBP' => [2000.0, 8500.0], 'basis' => 'CA_FINTRAC'],
        'GB' => ['GBP' => [1750.0, 8500.0], 'EUR' => [2000.0, 10000.0], 'USD' => [2500.0, 10000.0], 'basis' => 'UK_FCA'],
        'NG' => ['USD' => [1000.0, 2500.0], 'basis' => 'NG_CBN'],
        'CM' => ['USD' => [1000.0, 2500.0], 'basis' => 'CM_CEMAC'],
        'BJ' => ['USD' => [1500.0, 3000.0], 'basis' => 'BJ_UEMOA'],
        'CI' => ['USD' => [1500.0, 3000.0], 'basis' => 'CI_UEMOA'],
        'GH' => ['USD' => [2000.0, 5000.0], 'basis' => 'GH_BOG'],
        'KE' => ['USD' => [2000.0, 5000.0], 'basis' => 'KE_CBK'],
        'UG' => ['USD' => [1500.0, 3000.0], 'basis' => 'UG_BOU'],
        'TZ' => ['USD' => [1500.0, 3000.0], 'basis' => 'TZ_BOT'],
    ];

    /** @var array{EUR:array{0:float,1:float},USD:array{0:float,1:float},GBP:array{0:float,1:float},basis:string} */
    private const EEA_MONTHLY_CAPS = [
        'EUR'   => [2000.0, 10000.0],
        'USD'   => [2200.0, 11000.0],
        'GBP'   => [1700.0, 8500.0],
        'basis' => 'EU_AML_KYC',
    ];

    /**
     * Devis d'émission : 1,00 USD prélevé sur le solde USD.
     *
     * @return array{
     *   product:string,
     *   issuance_fee:float,
     *   extra_card_fee:float,
     *   currency:string,
     *   spends_from:string,
     *   requires_preload:bool,
     *   instant:bool
     * }
     */
    public static function quote(): array
    {
        return [
            'product'           => 'virtual',
            'issuance_fee'      => (float) self::ISSUANCE_FEE,
            'extra_card_fee'    => (float) self::ISSUANCE_FEE,
            'currency'          => self::FEE_CURRENCY,
            'spends_from'       => 'wallet',
            'requires_preload'  => false,
            'instant'           => true,
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array{
     *   country:string,
     *   kyc_tier:string,
     *   basis:string,
     *   max_by_currency:array<string,float>
     * }
     */
    public static function spendPolicy(string $country, array $user): array
    {
        $cc = strtoupper(trim($country));
        $tier = self::capTier($user);
        $row = self::countryCapRow($cc);
        $maxByCurrency = [];
        if ($row !== null) {
            foreach (['EUR', 'USD', 'GBP'] as $ccy) {
                if (isset($row[$ccy]) && is_array($row[$ccy])) {
                    $pair = $row[$ccy];
                    $maxByCurrency[$ccy] = $tier === 'advanced' ? (float) $pair[1] : (float) $pair[0];
                }
            }
        }
        return [
            'country'          => $cc,
            'kyc_tier'         => $tier,
            'basis'            => is_array($row) ? (string) ($row['basis'] ?? '') : '',
            'max_by_currency'  => $maxByCurrency,
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string, array{basis:string, max_by_currency:array<string,float>}>
     */
    public static function capsByCountry(array $user): array
    {
        $out = [];
        foreach (self::allIssuingCountries() as $cc) {
            $policy = self::spendPolicy($cc, $user);
            $out[$cc] = [
                'basis'           => $policy['basis'],
                'max_by_currency' => $policy['max_by_currency'],
            ];
        }
        return $out;
    }

    public static function monthlyCap(string $country, string $currency, array $user): ?float
    {
        $policy = self::spendPolicy($country, $user);
        $ccy = strtoupper(trim($currency));
        return $policy['max_by_currency'][$ccy] ?? null;
    }

    /**
     * Plafond obligatoire : vide → max pays ; au-dessus du max → refus.
     *
     * @param array<string,mixed> $user
     * @return array{ok:true, amount:float, max:float}|array{ok:false, code:string, max:?float}
     */
    public static function resolveSpendLimit(mixed $requested, string $country, string $currency, array $user): array
    {
        $max = self::monthlyCap($country, $currency, $user);
        if ($max === null) {
            return ['ok' => false, 'code' => 'SPEND_LIMIT_CURRENCY', 'max' => null];
        }
        if ($requested === null || $requested === '') {
            return ['ok' => true, 'amount' => $max, 'max' => $max];
        }
        if (!is_numeric($requested) || (float) $requested <= 0) {
            return ['ok' => false, 'code' => 'SPEND_LIMIT_INVALID', 'max' => $max];
        }
        $amount = round((float) $requested, 2);
        if (bccomp(sprintf('%.2F', $amount), sprintf('%.2F', $max), 2) > 0) {
            return ['ok' => false, 'code' => 'SPEND_LIMIT_EXCEEDED', 'max' => $max];
        }
        return ['ok' => true, 'amount' => $amount, 'max' => $max];
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function capTier(array $user): string
    {
        $kyc = strtolower(trim((string) ($user['kyc_level'] ?? 'standard')));
        return $kyc === 'advanced' ? 'advanced' : 'standard';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function countryCapRow(string $country): ?array
    {
        if ($country === '' || strlen($country) !== 2) {
            return null;
        }
        if (isset(self::COUNTRY_MONTHLY_CAPS[$country])) {
            return self::COUNTRY_MONTHLY_CAPS[$country];
        }
        if (self::isStripeIssuingCountry($country) && !in_array($country, ['US', 'GB', 'CA'], true)) {
            return self::EEA_MONTHLY_CAPS;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return list<string>
     */
    public static function profileMissingFields(array $profile): array
    {
        $missing = [];
        if (trim((string) ($profile['full_name'] ?? '')) === '') {
            $missing[] = 'full_name';
        }
        $cc = strtoupper(trim((string) ($profile['country_of_residence'] ?? '')));
        if (strlen($cc) !== 2) {
            $missing[] = 'country_of_residence';
        }
        if (trim((string) ($profile['address'] ?? '')) === '') {
            $missing[] = 'address';
        }
        if (trim((string) ($profile['city'] ?? '')) === '') {
            $missing[] = 'city';
        }
        if (trim((string) ($profile['postal_code'] ?? '')) === '') {
            $missing[] = 'postal_code';
        }
        return $missing;
    }

    public static function isIssuingBillingCountry(string $country): bool
    {
        return self::issuerForCountry($country) !== null;
    }

    public static function isStripeIssuingCountry(string $country): bool
    {
        return in_array(strtoupper(trim($country)), self::ISSUING_BILLING_COUNTRIES, true);
    }

    public static function isMapleradIssuingCountry(string $country): bool
    {
        return in_array(strtoupper(trim($country)), self::MAPLERAD_BILLING_COUNTRIES, true);
    }

    /**
     * @return list<string>
     */
    public static function allIssuingCountries(): array
    {
        $merged = array_values(array_unique(array_merge(
            self::ISSUING_BILLING_COUNTRIES,
            self::MAPLERAD_BILLING_COUNTRIES
        )));
        sort($merged);
        return $merged;
    }

    /**
     * @param list<string> $readySlugs  Émetteurs configurés ; vide = tous les émetteurs implémentés
     */
    public static function issuerForCountry(string $country, array $readySlugs = []): ?string
    {
        $cc = strtoupper(trim($country));
        if (strlen($cc) !== 2) {
            return null;
        }
        $candidates = [];
        // Stripe d'abord : continents déjà couverts par Issuing (Am. Nord + Europe).
        if (self::isStripeIssuingCountry($cc)) {
            $candidates[] = 'stripe_issuing';
        }
        if (self::isMapleradIssuingCountry($cc)) {
            $candidates[] = 'maplerad';
        }
        if ($candidates === []) {
            return null;
        }
        if ($readySlugs === []) {
            return $candidates[0];
        }
        foreach ($candidates as $slug) {
            if (in_array($slug, $readySlugs, true)) {
                return $slug;
            }
        }
        return $candidates[0];
    }

    /**
     * @param list<string> $slugs
     * @return list<string>
     */
    public static function billingCountriesFor(array $slugs): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $list = match ($slug) {
                'maplerad' => self::MAPLERAD_BILLING_COUNTRIES,
                'stripe_issuing' => self::ISSUING_BILLING_COUNTRIES,
                default => [],
            };
            foreach ($list as $cc) {
                $out[$cc] = true;
            }
        }
        $keys = array_keys($out);
        sort($keys);
        return $keys;
    }

    public static function termsAccepted(mixed $raw): bool
    {
        return $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
    }

    /**
     * @param array<string,mixed> $user  Session user (kyc_level, kyb_status, account_type, status)
     * @param array<string,mixed> $profile Cardholder fields
     * @return array{
     *   eligible:bool,
     *   can_issue:bool,
     *   identity_verified:bool,
     *   profile_complete:bool,
     *   account_active:bool,
     *   under_card_limit:bool,
     *   cards_open:int,
     *   max_cards:int,
     *   missing_fields:list<string>,
     *   blockers:list<array{code:string,message:string}>
     * }
     * @param list<string>|null $allowedCountries  null = tous les émetteurs implémentés
     */
    public static function evaluate(
        array $user,
        array $profile,
        int $openCards,
        bool $issuerReady,
        ?array $allowedCountries = null
    ): array {
        $openCards = max(0, $openCards);
        $identity = PolicyEngine::isVerified($user);
        $status = strtoupper(trim((string) ($user['status'] ?? '')));
        $accountActive = $status === 'ACTIVE';
        $missing = self::profileMissingFields($profile);
        $profileComplete = $missing === [];
        $underLimit = $openCards < self::MAX_OPEN_CARDS;
        $allowed = $allowedCountries ?? self::allIssuingCountries();

        $blockers = [];
        if (!$identity) {
            $blockers[] = [
                'code'    => 'KYC_REQUIRED',
                'message' => 'Vérifiez votre identité pour obtenir une carte virtuelle.',
            ];
        }
        if (!$accountActive) {
            $blockers[] = [
                'code'    => 'ACCOUNT_PENDING',
                'message' => 'Votre compte doit être actif pour émettre une carte.',
            ];
        }
        if (!$profileComplete) {
            $blockers[] = [
                'code'    => 'PROFILE_INCOMPLETE',
                'message' => 'Adresse de facturation incomplète (rue, ville, code postal, pays).',
            ];
        } elseif (!in_array(strtoupper(trim((string) ($profile['country_of_residence'] ?? ''))), $allowed, true)) {
            $blockers[] = [
                'code'    => 'ISSUING_COUNTRY_UNSUPPORTED',
                'message' => 'Ce pays n’est pas encore couvert pour l’émission de cartes virtuelles. Nos partenaires couvrent l’Union européenne, le Royaume-Uni, les États-Unis, et plusieurs pays d’Afrique (Nigeria, Ghana, Kenya, Côte d’Ivoire, Bénin, Cameroun, Ouganda, Tanzanie). Le service sera ouvert dans votre pays dès que la couverture le permettra.',
            ];
        }
        if (!$underLimit) {
            $blockers[] = [
                'code'    => 'CARD_LIMIT_REACHED',
                'message' => 'Vous avez atteint le nombre maximum de cartes virtuelles.',
            ];
        }

        $eligible = $blockers === [];

        return [
            'eligible'           => $eligible,
            'can_issue'          => $eligible && $issuerReady,
            'identity_verified'  => $identity,
            'profile_complete'   => $profileComplete,
            'account_active'     => $accountActive,
            'under_card_limit'   => $underLimit,
            'cards_open'         => $openCards,
            'max_cards'          => self::MAX_OPEN_CARDS,
            'missing_fields'     => $missing,
            'blockers'           => $blockers,
        ];
    }
}
