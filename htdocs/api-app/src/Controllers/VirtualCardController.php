<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Providers\MapleradIssuingAdapter;
use Nexus\Providers\ProviderRegistry;
use Nexus\Providers\StripeIssuingAdapter;
use Nexus\Services\LedgerService;
use Nexus\Services\ProviderCatalog;
use Nexus\Services\VirtualCardIssuancePolicy;
use Nexus\Services\WalletService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Cartes virtuelles — émission via deux partenaires :
 *   - stripe_issuing : continents couverts par Stripe (Europe, UK, Amérique du Nord)
 *   - maplerad       : extension Afrique (NG, GH, KE, CI, BJ, CM, UG, TZ)
 *
 * HONNÊTETÉ : aucun PAN/CVV n'est généré ni stocké.
 */
final class VirtualCardController
{
    /** Devises acceptées à l'émission (Stripe Issuing — XAF non supporté). */
    private const ALLOWED_CURRENCIES = ['EUR', 'USD', 'GBP'];

    /** GET /api/cards — liste des cartes / demandes du compte. */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId = (int) $request->attribute('user')['id'];
        $pdo = Database::getConnection();

        try {
            $stmt = $pdo->prepare(
                'SELECT id, label, currency, spend_limit, status, last4, brand,
                        issuer_provider, environment, created_at, updated_at
                   FROM virtual_cards
                  WHERE user_id = :uid
                  ORDER BY created_at DESC'
            );
            $stmt->execute(['uid' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            Response::serverError('Module cartes indisponible (schéma non migré).');
        }

        $cards = array_map(static fn (array $r): array => self::project($r), $rows);
        $issuance = self::issuanceStatus();
        $profile = self::loadCardholderProfile($pdo, $userId, $request->attribute('user'));
        $openCards = self::countOpenCards($rows);
        $issuerSlug = VirtualCardIssuancePolicy::issuerForCountry(
            (string) ($profile['country_of_residence'] ?? '')
        );
        $issuerReady = $issuerSlug !== null
            && in_array($issuerSlug, $issuance['ready_issuers'], true);

        $user = $request->attribute('user');
        Response::success([
            'cards'         => $cards,
            'issuance'      => $issuance,
            'quote'         => VirtualCardIssuancePolicy::quote(),
            'spend_policy'  => VirtualCardIssuancePolicy::spendPolicy(
                (string) ($profile['country_of_residence'] ?? ''),
                $user
            ),
            'country_caps'  => VirtualCardIssuancePolicy::capsByCountry($user),
            'eligibility'   => VirtualCardIssuancePolicy::evaluate(
                $user,
                $profile,
                $openCards,
                $issuerReady,
                VirtualCardIssuancePolicy::allIssuingCountries()
            ),
        ]);
    }

    /**
     * POST /api/cards — crée et, si possible, émet une carte virtuelle.
     *
     * Body : { label?, currency, spend_limit?, accept_terms, address?, city?, postal_code?, country_of_residence? }
     */
    public static function create(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $userId = (int) $user['id'];

        if (!VirtualCardIssuancePolicy::termsAccepted($request->input('accept_terms', false))) {
            Response::error(
                'Veuillez accepter les conditions d’utilisation de la carte.',
                400,
                'TERMS_REQUIRED'
            );
        }

        $currency = strtoupper(trim((string) $request->input('currency', 'EUR')));
        $label = trim((string) $request->input('label', ''));
        $spendLimitRaw = $request->input('spend_limit', null);

        if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            Response::badRequest('Devise non supportée pour une carte virtuelle. Utilisez EUR, USD ou GBP.');
        }

        if ($label === '') {
            $label = 'Carte virtuelle ' . $currency;
        }
        if (mb_strlen($label) > 120) {
            Response::badRequest('Le libellé ne peut pas dépasser 120 caractères.');
        }

        $env = StripeIssuingAdapter::runtimeEnvironment();

        $issuance = self::issuanceStatus();
        $pdo = Database::getConnection();
        $profile = self::mergeBillingFromRequest(
            self::loadCardholderProfile($pdo, $userId, $user),
            $request
        );
        $openCards = self::countOpenCardsForUser($pdo, $userId);
        $issuerSlug = VirtualCardIssuancePolicy::issuerForCountry(
            (string) ($profile['country_of_residence'] ?? '')
        );
        $issuerReady = $issuerSlug !== null
            && in_array($issuerSlug, $issuance['ready_issuers'], true);
        $eligibility = VirtualCardIssuancePolicy::evaluate(
            $user,
            $profile,
            $openCards,
            $issuerReady,
            VirtualCardIssuancePolicy::allIssuingCountries()
        );

        foreach ($eligibility['blockers'] as $blocker) {
            $code = (string) ($blocker['code'] ?? '');
            $message = (string) ($blocker['message'] ?? 'Émission refusée.');
            if ($code === 'KYC_REQUIRED' || $code === 'ACCOUNT_PENDING') {
                Response::error($message, 403, $code);
            }
            if ($code === 'CARD_LIMIT_REACHED') {
                Response::error($message, 409, $code);
            }
            Response::error($message, 400, $code !== '' ? $code : 'VALIDATION_ERROR');
        }

        self::persistBillingAddress($pdo, $userId, $profile);

        $id = self::uuid();

        $status = 'issuer_unavailable';
        $last4 = null;
        $brand = null;
        $issuerProvider = null;
        $issuerRef = null;
        $cardholderId = null;
        $message = 'Demande enregistrée. L’émission de cartes est temporairement indisponible.';

        if ($issuerSlug === 'maplerad') {
            $currency = 'USD';
        }

        $limitResult = VirtualCardIssuancePolicy::resolveSpendLimit(
            $spendLimitRaw,
            (string) ($profile['country_of_residence'] ?? ''),
            $currency,
            $user
        );
        if ($limitResult['ok'] !== true) {
            $max = $limitResult['max'];
            $code = (string) $limitResult['code'];
            if ($code === 'SPEND_LIMIT_EXCEEDED') {
                Response::error(
                    'Le plafond dépasse le maximum autorisé pour ce pays ('
                    . number_format((float) $max, 2, ',', ' ') . ' ' . $currency . ' / mois).',
                    400,
                    'SPEND_LIMIT_EXCEEDED'
                );
            }
            if ($code === 'SPEND_LIMIT_INVALID') {
                Response::badRequest('Le plafond doit être un montant positif.');
            }
            Response::badRequest('Aucun plafond réglementaire n’est défini pour cette devise dans ce pays.');
        }
        $spendLimit = (float) $limitResult['amount'];

        self::chargeIssuanceFee($userId, $id);

        $adapter = self::cardIssuingAdapter($issuerSlug);
        $canIssue = $adapter !== null && $adapter->hasCredentials($env);

        if ($canIssue) {
            $holder = array_merge($profile, [
                'account_type' => (string) ($user['account_type'] ?? 'personal'),
            ]);
            try {
                $issued = $adapter->issueVirtualCard($env, $holder, [
                    'currency'         => $currency,
                    'label'            => $label,
                    'spend_limit'      => $spendLimit,
                    'cardholder_id'    => self::existingCardholderId($pdo, $userId, (string) $issuerSlug),
                    'idempotency_key'  => 'nexus-vcard-' . $userId . '-' . $id,
                ]);
                $status = (string) $issued['status'];
                $last4 = $issued['last4'];
                $brand = $issued['brand'];
                $issuerProvider = (string) $issuerSlug;
                $issuerRef = (string) $issued['issuer_ref'];
                $cardholderId = (string) $issued['cardholder_id'];
                $message = $status === 'active'
                    ? 'Votre carte virtuelle est prête. Les paiements seront débités de votre solde.'
                    : 'Carte créée — statut : ' . $status . '.';
            } catch (RuntimeException $e) {
                $code = $e->getMessage();
                if ($code === 'CURRENCY_NOT_SUPPORTED_BY_ISSUER') {
                    Response::badRequest(
                        $issuerSlug === 'maplerad'
                            ? 'Les cartes Afrique (Maplerad) sont émises en USD.'
                            : 'Stripe Issuing ne prend pas en charge cette devise. Utilisez EUR, USD ou GBP.'
                    );
                }
                if ($code === 'COUNTRY_NOT_SUPPORTED_BY_ISSUER') {
                    Response::error(
                        'Ce pays n’est pas encore couvert pour l’émission de cartes virtuelles. Stripe couvre l’Europe, le Royaume-Uni, le Canada et les États-Unis ; Maplerad couvre plusieurs pays d’Afrique (Nigeria, Ghana, Kenya, Côte d’Ivoire, Bénin, Cameroun, Ouganda, Tanzanie).',
                        400,
                        'ISSUING_COUNTRY_UNSUPPORTED'
                    );
                }
                if ($code === 'CARDHOLDER_NAME_REQUIRED') {
                    Response::badRequest('Nom du titulaire requis pour émettre une carte.');
                }
                if ($code === 'CARDHOLDER_EMAIL_REQUIRED') {
                    Response::badRequest('Adresse e-mail requise pour émettre une carte.');
                }
                if ($code === 'CARDHOLDER_COUNTRY_REQUIRED') {
                    Response::badRequest(
                        'Pays de résidence requis. Complétez votre profil (Paramètres) avant d’émettre une carte.'
                    );
                }
                if ($code === 'CARDHOLDER_ADDRESS_REQUIRED') {
                    Response::badRequest(
                        'Adresse postale complète (rue, ville, code postal) requise. Complétez votre profil avant d’émettre une carte.'
                    );
                }
                if (in_array($code, ['CREDENTIALS_NOT_CONFIGURED', 'INVALID_CREDENTIALS', 'UNAUTHORIZED', 'ISSUER_UNAUTHORIZED'], true)) {
                    $status = 'issuer_unavailable';
                    $message = 'L’émission de cartes est temporairement indisponible. Votre demande a été enregistrée.';
                } elseif ($code === 'ISSUING_NOT_ENABLED') {
                    $status = 'issuer_unavailable';
                    $message = 'L’émission de cartes n’est pas encore active. Votre demande a été enregistrée.';
                } else {
                    $status = 'pending_issuer';
                    $message = 'Émission échouée (' . $code . '). Demande enregistrée pour suivi.';
                }
            } catch (Throwable $e) {
                $status = 'pending_issuer';
                $message = 'Émission indisponible. Demande enregistrée pour suivi.';
            }
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO virtual_cards
                    (id, user_id, label, currency, spend_limit, status, last4, brand,
                     issuer_provider, issuer_ref, issuer_cardholder_id, environment)
                 VALUES
                    (:id, :uid, :label, :cur, :lim, :st, :last4, :brand,
                     :ip, :iref, :ich, :env)'
            );
            $stmt->execute([
                'id'    => $id,
                'uid'   => $userId,
                'label' => $label,
                'cur'   => $currency,
                'lim'   => $spendLimit,
                'st'    => $status,
                'last4' => $last4,
                'brand' => $brand,
                'ip'    => $issuerProvider,
                'iref'  => $issuerRef,
                'ich'   => $cardholderId,
                'env'   => $env,
            ]);
        } catch (PDOException $e) {
            // Schéma sans issuer_cardholder_id (migration non appliquée) : repli.
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO virtual_cards
                        (id, user_id, label, currency, spend_limit, status, last4, brand,
                         issuer_provider, issuer_ref, environment)
                     VALUES
                        (:id, :uid, :label, :cur, :lim, :st, :last4, :brand,
                         :ip, :iref, :env)'
                );
                $stmt->execute([
                    'id'    => $id,
                    'uid'   => $userId,
                    'label' => $label,
                    'cur'   => $currency,
                    'lim'   => $spendLimit,
                    'st'    => $status,
                    'last4' => $last4,
                    'brand' => $brand,
                    'ip'    => $issuerProvider,
                    'iref'  => $issuerRef,
                    'env'   => $env,
                ]);
            } catch (PDOException $e2) {
                Response::serverError('Impossible d\'enregistrer la demande de carte.');
            }
        }

        $stmt = $pdo->prepare(
            'SELECT id, label, currency, spend_limit, status, last4, brand,
                    issuer_provider, environment, created_at, updated_at
               FROM virtual_cards WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $freshIssuance = self::issuanceStatus();
        Response::success([
            'card'         => self::project($row),
            'issuance'     => $freshIssuance,
            'quote'         => VirtualCardIssuancePolicy::quote(),
            'spend_policy'  => VirtualCardIssuancePolicy::spendPolicy(
                (string) ($profile['country_of_residence'] ?? ''),
                $user
            ),
            'eligibility'  => VirtualCardIssuancePolicy::evaluate(
                $user,
                $profile,
                $openCards + 1,
                (bool) $freshIssuance['ready']
            ),
            'message'      => $message,
        ], 201);
    }

    private static function chargeIssuanceFee(int $userId, string $cardId): void
    {
        $fee = VirtualCardIssuancePolicy::ISSUANCE_FEE;
        $ccy = VirtualCardIssuancePolicy::FEE_CURRENCY;
        $wallet = WalletService::getWallet($userId, $ccy);
        $available = is_array($wallet) ? (string) ($wallet['available_balance'] ?? '0') : '0';
        if ($wallet === null || bccomp($available, $fee, 8) < 0) {
            Response::error(
                '1,00 USD est requis sur votre solde USD pour générer une carte.',
                402,
                'CARD_ISSUANCE_FEE'
            );
        }
        try {
            LedgerService::debit(
                $userId,
                (int) $wallet['id'],
                $fee,
                $ccy,
                'fee',
                'vcard-fee-' . $cardId,
                'Frais de génération de carte virtuelle',
                ['card_id' => $cardId, 'kind' => 'virtual_card_issuance']
            );
        } catch (RuntimeException $e) {
            Response::error(
                '1,00 USD est requis sur votre solde USD pour générer une carte.',
                402,
                'CARD_ISSUANCE_FEE'
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function project(array $row): array
    {
        return [
            'id'              => (string) ($row['id'] ?? ''),
            'label'           => (string) ($row['label'] ?? ''),
            'currency'        => (string) ($row['currency'] ?? ''),
            'spend_limit'     => isset($row['spend_limit']) && $row['spend_limit'] !== null
                ? (float) $row['spend_limit']
                : null,
            'status'          => (string) ($row['status'] ?? 'pending_issuer'),
            'last4'           => $row['last4'] ?? null,
            'brand'           => $row['brand'] ?? null,
            'issuer_provider' => $row['issuer_provider'] ?? null,
            'environment'     => (string) ($row['environment'] ?? 'sandbox'),
            'created_at'      => (string) ($row['created_at'] ?? ''),
            'updated_at'      => (string) ($row['updated_at'] ?? ''),
            'pan_masked'      => isset($row['last4']) && is_string($row['last4']) && $row['last4'] !== ''
                ? '•••• •••• •••• ' . $row['last4']
                : '•••• •••• •••• ••••',
            'cvv_available'   => false,
        ];
    }

    /**
     * @return array{
     *   ready:bool,
     *   providers:list<string>,
     *   ready_issuers:list<string>,
     *   issuers:list<array{slug:string,ready:bool,role:string,currencies:list<string>,countries:list<string>}>,
     *   status:string,
     *   issuer:?string,
     *   environment:string,
     *   credential_source:?string,
     *   billing_countries:list<string>
     * }
     */
    private static function issuanceStatus(): array
    {
        $candidates = [];
        foreach (ProviderCatalog::all() as $slug => $meta) {
            if (($meta['category'] ?? '') === 'card_issuing') {
                $candidates[] = (string) $slug;
            }
        }

        $env = StripeIssuingAdapter::runtimeEnvironment();
        $stripe = ProviderRegistry::adapter('stripe_issuing');
        $maplerad = ProviderRegistry::adapter('maplerad');
        $stripeReady = $stripe instanceof StripeIssuingAdapter && $stripe->hasCredentials($env);
        $mapleradReady = $maplerad instanceof MapleradIssuingAdapter && $maplerad->hasCredentials($env);

        $readyIssuers = [];
        if ($stripeReady) {
            $readyIssuers[] = 'stripe_issuing';
        }
        if ($mapleradReady) {
            $readyIssuers[] = 'maplerad';
        }

        $source = $stripe instanceof StripeIssuingAdapter ? $stripe->credentialSource($env) : null;
        if ($source === 'provided') {
            $source = 'stripe_issuing';
        }

        $ready = $readyIssuers !== [];
        return [
            'ready'              => $ready,
            'providers'          => $candidates,
            'ready_issuers'      => $readyIssuers,
            'issuers'            => [
                [
                    'slug'       => 'stripe_issuing',
                    'ready'      => $stripeReady,
                    'role'       => 'primary',
                    'currencies' => StripeIssuingAdapter::SUPPORTED_CURRENCIES,
                    'countries'  => VirtualCardIssuancePolicy::ISSUING_BILLING_COUNTRIES,
                ],
                [
                    'slug'       => 'maplerad',
                    'ready'      => $mapleradReady,
                    'role'       => 'expansion',
                    'currencies' => MapleradIssuingAdapter::SUPPORTED_CURRENCIES,
                    'countries'  => VirtualCardIssuancePolicy::MAPLERAD_BILLING_COUNTRIES,
                ],
            ],
            'status'             => $ready ? 'CONFIGURED' : 'CREDENTIALS_NOT_CONFIGURED',
            'issuer'             => $stripeReady ? 'stripe_issuing' : ($mapleradReady ? 'maplerad' : null),
            'environment'        => $env,
            'credential_source'  => $source,
            'billing_countries'  => VirtualCardIssuancePolicy::allIssuingCountries(),
        ];
    }

    private static function cardIssuingAdapter(?string $slug): StripeIssuingAdapter|MapleradIssuingAdapter|null
    {
        if ($slug === 'stripe_issuing') {
            $adapter = ProviderRegistry::adapter('stripe_issuing');
            return $adapter instanceof StripeIssuingAdapter ? $adapter : null;
        }
        if ($slug === 'maplerad') {
            $adapter = ProviderRegistry::adapter('maplerad');
            return $adapter instanceof MapleradIssuingAdapter ? $adapter : null;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $sessionUser
     * @return array{
     *   full_name:?string,
     *   email:?string,
     *   phone:?string,
     *   address:?string,
     *   city:?string,
     *   postal_code:?string,
     *   country_of_residence:?string
     * }
     */
    private static function loadCardholderProfile(PDO $pdo, int $userId, array $sessionUser): array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT full_name, email, phone, address, city, postal_code, country_of_residence,
                        account_type, company_name
                   FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT full_name, email, phone, address, city, postal_code, country_of_residence,
                            account_type
                       FROM users WHERE id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e2) {
                $row = [];
            }
        }

        return [
            'full_name'             => (string) ($row['full_name'] ?? $sessionUser['full_name'] ?? ''),
            'email'                 => (string) ($row['email'] ?? $sessionUser['email'] ?? ''),
            'phone'                 => (string) ($row['phone'] ?? ''),
            'address'               => (string) ($row['address'] ?? ''),
            'city'                  => (string) ($row['city'] ?? ''),
            'postal_code'           => (string) ($row['postal_code'] ?? ''),
            'country_of_residence'  => (string) ($row['country_of_residence']
                ?? $sessionUser['country_of_residence'] ?? ''),
            'account_type'          => (string) ($row['account_type'] ?? $sessionUser['account_type'] ?? 'personal'),
            'company_name'          => (string) ($row['company_name'] ?? ''),
        ];
    }

    private static function existingCardholderId(PDO $pdo, int $userId, string $issuer): ?string
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT issuer_cardholder_id FROM virtual_cards
                  WHERE user_id = :uid
                    AND issuer_provider = :issuer
                    AND issuer_cardholder_id IS NOT NULL
                    AND issuer_cardholder_id <> ''
                  ORDER BY created_at DESC
                  LIMIT 1"
            );
            $stmt->execute(['uid' => $userId, 'issuer' => $issuer]);
            $id = $stmt->fetchColumn();
            if (!is_string($id) || $id === '') {
                return null;
            }
            if ($issuer === 'stripe_issuing') {
                return str_starts_with($id, 'ich_') ? $id : null;
            }
            if ($issuer === 'maplerad') {
                return preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? $id : null;
            }
            return null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function countOpenCards(array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'cancelled') {
                $n++;
            }
        }
        return $n;
    }

    private static function countOpenCardsForUser(PDO $pdo, int $userId): int
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM virtual_cards WHERE user_id = :uid AND status <> 'cancelled'"
            );
            $stmt->execute(['uid' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private static function mergeBillingFromRequest(array $profile, Request $request): array
    {
        foreach (['full_name', 'address', 'city', 'postal_code', 'country_of_residence'] as $field) {
            $raw = $request->input($field, null);
            if (!is_string($raw)) {
                continue;
            }
            $value = trim($raw);
            if ($value === '') {
                continue;
            }
            if ($field === 'country_of_residence') {
                $value = strtoupper($value);
                if (strlen($value) !== 2 || !preg_match('/^[A-Z]{2}$/', $value)) {
                    continue;
                }
            }
            if (mb_strlen($value) > 255) {
                $value = mb_substr($value, 0, 255);
            }
            $profile[$field] = $value;
        }
        return $profile;
    }

    /**
     * @param array<string,mixed> $profile
     */
    private static function persistBillingAddress(PDO $pdo, int $userId, array $profile): void
    {
        try {
            $stmt = $pdo->prepare(
                'UPDATE users
                    SET address = :address,
                        city = :city,
                        postal_code = :postal,
                        country_of_residence = :cc,
                        updated_at = NOW()
                  WHERE id = :id'
            );
            $stmt->execute([
                'address' => mb_substr((string) ($profile['address'] ?? ''), 0, 255),
                'city'    => mb_substr((string) ($profile['city'] ?? ''), 0, 100),
                'postal'  => mb_substr((string) ($profile['postal_code'] ?? ''), 0, 20),
                'cc'      => strtoupper(substr((string) ($profile['country_of_residence'] ?? ''), 0, 2)),
                'id'      => $userId,
            ]);
        } catch (PDOException $e) {
            // Colonnes d'adresse absentes : l'émetteur refusera CARDHOLDER_ADDRESS_REQUIRED.
        }
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
