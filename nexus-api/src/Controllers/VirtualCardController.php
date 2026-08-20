<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderRegistry;
use Nexus\Providers\StripeIssuingAdapter;
use Nexus\Services\ProviderCatalog;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Cartes virtuelles — demandes utilisateur + émission Stripe Issuing.
 *
 * HONNÊTETÉ : aucun PAN/CVV n'est généré ni stocké. Seuls last4 / brand /
 * issuer_ref (ic_…) sont persistés après un appel réel à Stripe Issuing.
 */
final class VirtualCardController
{
    /** Devises acceptées à la demande ; l'émetteur peut en refuser certaines (ex. XAF). */
    private const ALLOWED_CURRENCIES = ['EUR', 'USD', 'GBP', 'XAF'];

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

        Response::success([
            'cards' => $cards,
            'issuance' => self::issuanceStatus(),
        ]);
    }

    /**
     * POST /api/cards — crée et, si possible, émet une carte virtuelle.
     *
     * Body : { label?, currency, spend_limit? }
     */
    public static function create(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        $userId = (int) $user['id'];

        $currency = strtoupper(trim((string) $request->input('currency', 'EUR')));
        $label = trim((string) $request->input('label', ''));
        $spendLimitRaw = $request->input('spend_limit', null);

        if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            Response::badRequest('Devise non supportée pour une carte virtuelle.');
        }

        if ($label === '') {
            $label = 'Carte virtuelle ' . $currency;
        }
        if (mb_strlen($label) > 120) {
            Response::badRequest('Le libellé ne peut pas dépasser 120 caractères.');
        }

        $spendLimit = null;
        if ($spendLimitRaw !== null && $spendLimitRaw !== '') {
            if (!is_numeric($spendLimitRaw) || (float) $spendLimitRaw <= 0) {
                Response::badRequest('Le plafond doit être un montant positif.');
            }
            $spendLimit = round((float) $spendLimitRaw, 2);
        }

        // Fail-closed : émission réelle uniquement en sandbox hors production API.
        $env = 'sandbox';
        if (ProviderConfig::isProduction()) {
            $env = 'production';
        }

        $issuance = self::issuanceStatus();
        $id = self::uuid();
        $pdo = Database::getConnection();

        $status = 'issuer_unavailable';
        $last4 = null;
        $brand = null;
        $issuerProvider = null;
        $issuerRef = null;
        $cardholderId = null;
        $message = 'Demande enregistrée. Aucun émetteur de cartes opérationnel pour le moment.';

        if ($issuance['ready']) {
            $holder = self::loadCardholderProfile($pdo, $userId, $user);
            try {
                /** @var StripeIssuingAdapter $adapter */
                $adapter = ProviderRegistry::adapter('stripe_issuing');
                if (!$adapter instanceof StripeIssuingAdapter) {
                    throw new RuntimeException('ISSUER_ADAPTER_MISSING');
                }
                $issued = $adapter->issueVirtualCard($env, $holder, [
                    'currency'    => $currency,
                    'label'       => $label,
                    'spend_limit' => $spendLimit,
                ]);
                $status = (string) $issued['status'];
                $last4 = $issued['last4'];
                $brand = $issued['brand'];
                $issuerProvider = 'stripe_issuing';
                $issuerRef = (string) $issued['issuer_ref'];
                $cardholderId = (string) $issued['cardholder_id'];
                $message = $status === 'active'
                    ? 'Carte virtuelle émise via Stripe Issuing (last4 uniquement — pas de PAN/CVV stocké).'
                    : 'Carte créée chez Stripe Issuing — statut : ' . $status . '.';
            } catch (RuntimeException $e) {
                $code = $e->getMessage();
                if ($code === 'CURRENCY_NOT_SUPPORTED_BY_ISSUER') {
                    Response::badRequest(
                        'Stripe Issuing ne prend pas en charge cette devise. Utilisez EUR, USD ou GBP.'
                    );
                }
                if ($code === 'CARDHOLDER_NAME_REQUIRED') {
                    Response::badRequest('Nom du titulaire requis pour émettre une carte.');
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
                if (in_array($code, ['CREDENTIALS_NOT_CONFIGURED', 'INVALID_CREDENTIALS', 'UNAUTHORIZED'], true)) {
                    $status = 'issuer_unavailable';
                    $message = 'Émetteur Stripe Issuing non utilisable (credentials / permissions). Demande enregistrée sans carte active.';
                } elseif ($code === 'ISSUING_NOT_ENABLED') {
                    $status = 'issuer_unavailable';
                    $message = 'Stripe Issuing n’est pas activé sur ce compte Stripe. Demande enregistrée sans carte active.';
                } else {
                    $status = 'pending_issuer';
                    $message = 'Émission Stripe Issuing échouée (' . $code . '). Demande enregistrée pour suivi.';
                }
            } catch (Throwable $e) {
                $status = 'pending_issuer';
                $message = 'Émission Stripe Issuing indisponible. Demande enregistrée pour suivi.';
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

        Response::success([
            'card' => self::project($row),
            'issuance' => self::issuanceStatus(),
            'message' => $message,
        ], 201);
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
     * @return array{ready:bool,providers:list<string>,status:string,issuer:?string}
     */
    private static function issuanceStatus(): array
    {
        $candidates = [];
        foreach (ProviderCatalog::all() as $slug => $meta) {
            if (($meta['category'] ?? '') === 'card_issuing') {
                $candidates[] = (string) $slug;
            }
        }

        $adapter = ProviderRegistry::adapter('stripe_issuing');
        $ready = $adapter instanceof StripeIssuingAdapter
            && $adapter->hasCredentials(ProviderConfig::isProduction() ? 'production' : 'sandbox');

        return [
            'ready'     => $ready,
            'providers' => $candidates,
            'status'    => $ready ? 'CONFIGURED' : 'CREDENTIALS_NOT_CONFIGURED',
            'issuer'    => 'stripe_issuing',
        ];
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
                'SELECT full_name, email, phone, address, city, postal_code, country_of_residence
                   FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $row = [];
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
        ];
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
