<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Crypto;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\FundingSourceEngine;

/**
 * Comptes (Sources & Destinations) — CRUD scopé par user_id.
 *
 *  - GET    /api/accounts              → liste des comptes de l'utilisateur ;
 *  - POST   /api/accounts              → création ;
 *  - PUT    /api/accounts/{id}         → mise à jour ;
 *  - DELETE /api/accounts/{id}         → suppression ;
 *  - POST   /api/accounts/{id}/default → définit par défaut (dé-sélectionne les autres).
 *
 * Sécurité : les données sensibles (IBAN, téléphone, PAN, adresse blockchain)
 * sont chiffrées en AES-256-GCM (clé APP_KEY) avant écriture, et l'API ne
 * renvoie JAMAIS la valeur en clair — uniquement la version masquée
 * (`iban_masked`, `phone_masked`, `pan_masked`, `address_masked`).
 *
 * RLS simulée : toutes les requêtes sont contraintes par `user_id`.
 * Un utilisateur ne peut pas accéder aux comptes d'un autre (404 systématique).
 */
final class AccountController
{
    /** Rôles autorisés. */
    private const ALLOWED_ROLES = ['source', 'destination'];

    /** Types de comptes autorisés (source ou destination). */
    private const ALLOWED_KINDS = [
        'bank_iban', 'mobile_money', 'crypto_wallet', 'card', 'virtual_iban', 'cash_pickup',
    ];

    /** Pays ISO-2 valides (autorisation sommaire — référentiel complet ailleurs). */
    private const ALLOWED_COUNTRIES = [
        'FR','DE','ES','IT','PT','BE','NL','LU','IE','AT','FI','GR','CH','GB','US','CA',
        'CG','CD','CM','GA','GQ','CF','TD','SN','CI','ML','BF','NE','TG','BJ','GA','CG',
        'MA','DZ','TN','LY','EG','ZA','NG','KE','GH','TZ','UG','RW','ZM','ZW',
        'AE','SA','QA','KW','BH','OM','JO','LB','IL','TR','IN','PK','BD','CN','JP','KR','SG','ID','PH','TH','VN',
        'BR','MX','AR','CO','CL','PE','VE','UY','CR','PA','DO',
        'AU','NZ',
    ];

    /** Réseaux / agents cash pickup & cashout (Western Union prioritaire). */
    private const CASH_PICKUP_NETWORKS = [
        'Western Union',
        'MoneyGram',
    ];

    /** Mapping réseau cash pickup → provider_slug catalogue. */
    private const CASH_PICKUP_PROVIDER_SLUG = [
        'Western Union' => 'western_union',
        'MoneyGram'     => 'moneygram',
    ];

    /** Suffixes JSON / table de référence des opérateurs Mobile Money par pays. */
    private const MOBILE_MONEY_OPERATORS = [
        'CG' => ['Airtel Money', 'MTN Mobile Money', 'Moov Africa'],
        'CD' => ['Airtel Money', 'M-Pesa', 'Orange Money', 'Vodacom M-Pesa'],
        'CM' => ['MTN Mobile Money', 'Orange Money'],
        'GA' => ['Airtel Money', 'Moov Africa'],
        'GQ' => ['MuniMovi'],
        'CF' => ['Telecel Money', 'Orange Money'],
        'TD' => ['Airtel Money', 'Moov Africa'],
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
        'ET' => ['Telebirr', 'M-Pesa'],
        'ZA' => ['MTN MoMo', 'Vodacom M-Pesa'],
        'MZ' => ['M-Pesa', 'e-Mola'],
        'BW' => ['Orange Money'],
        'ZW' => ['EcoCash'],
        'MA' => ['Inwi Money', 'Orange Money'],
        'TN' => ['Orange Money', 'Ooredoo Money'],
    ];

    /** Réseaux blockchain principaux. */
    private const ALLOWED_NETWORKS = [
        'Ethereum', 'Polygon', 'Arbitrum', 'Optimism', 'BNB Smart Chain',
        'Tron', 'Solana', 'Bitcoin', 'Base',
    ];

    /**
     * GET /api/accounts?role=source|destination
     */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];

        $role = strtolower((string) $request->query('role', ''));
        if ($role !== '' && !in_array($role, self::ALLOWED_ROLES, true)) {
            Response::badRequest('Rôle invalide (attendu : source, destination).');
        }

        $pdo = Database::getConnection();

        $sql = 'SELECT id, user_id, role, kind, label, holder_name, country, currency, operator,
                       network, city, iban_enc, bic, phone_enc, pan_enc, expiry, address_enc,
                       is_default, verification_status, supported_for_transfer, status, provider_slug,
                       created_at, updated_at
                FROM payment_accounts
                WHERE user_id = :uid';
        $params = ['uid' => $userId];
        if ($role !== '') {
            $sql .= ' AND role = :role';
            $params['role'] = $role;
        }
        $sql .= ' ORDER BY is_default DESC, created_at DESC, id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $items = array_map([self::class, 'toApi'], $stmt->fetchAll());

        Response::success([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * POST /api/accounts
     */
    public static function create(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];

        $payload = self::validatePayload($request->body(), null);
        $payload['user_id'] = $userId;

        $pdo = Database::getConnection();

        // Premier compte du rôle → automatiquement par défaut.
        $check = $pdo->prepare('SELECT COUNT(*) FROM payment_accounts WHERE user_id = :uid AND role = :role');
        $check->execute(['uid' => $userId, 'role' => $payload['role']]);
        $forceDefault = ((int) $check->fetchColumn() === 0);

        if ($forceDefault) {
            $payload['is_default'] = 1;
        }

        $pdo->beginTransaction();
        try {
            if (!empty($payload['is_default'])) {
                self::clearDefault($pdo, $userId, $payload['role']);
            }

            $id = self::insert($pdo, $payload);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $account = self::fetchOne($pdo, $userId, $id);
        Response::success(self::toApi($account), 201);
    }

    /**
     * PUT /api/accounts/{id}
     */
    public static function update(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];
        $id      = (int) $request->param('id', '0');

        if ($id <= 0) {
            Response::badRequest('Identifiant de compte invalide.');
        }

        $pdo = Database::getConnection();
        $existing = self::fetchOne($pdo, $userId, $id);
        if ($existing === null) {
            Response::notFound('Compte introuvable.');
        }

        $payload = self::validatePayload($request->body(), $existing);
        // Rôle et kind ne sont pas modifiables (risque de corruption sémantique).
        $payload['role'] = $existing['role'];
        $payload['kind'] = $existing['kind'];

        $pdo->beginTransaction();
        try {
            if (!empty($payload['is_default'])) {
                self::clearDefault($pdo, $userId, $existing['role']);
            }
            self::updateRow($pdo, $userId, $id, $payload);

            // Si on désactive le défaut, promouvoir le plus récent du même rôle.
            if (!empty($existing['is_default']) && empty($payload['is_default'])) {
                $next = $pdo->prepare(
                    'SELECT id FROM payment_accounts
                     WHERE user_id = :uid AND role = :role AND id != :id
                     ORDER BY created_at DESC, id DESC LIMIT 1'
                );
                $next->execute(['uid' => $userId, 'role' => $existing['role'], 'id' => $id]);
                $nextId = $next->fetchColumn();
                if ($nextId !== false) {
                    $pdo->prepare('UPDATE payment_accounts SET is_default = 1 WHERE user_id = :uid AND id = :id')
                        ->execute(['uid' => $userId, 'id' => (int) $nextId]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $account = self::fetchOne($pdo, $userId, $id);
        Response::success(self::toApi($account));
    }

    /**
     * DELETE /api/accounts/{id}
     */
    public static function delete(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];
        $id      = (int) $request->param('id', '0');

        if ($id <= 0) {
            Response::badRequest('Identifiant de compte invalide.');
        }

        $pdo = Database::getConnection();
        $existing = self::fetchOne($pdo, $userId, $id);
        if ($existing === null) {
            Response::notFound('Compte introuvable.');
        }

        $pdo->prepare('DELETE FROM payment_accounts WHERE user_id = :uid AND id = :id')
            ->execute(['uid' => $userId, 'id' => $id]);

        // Si on a supprimé le compte par défaut, promouvoir le plus récent.
        if ((int) $existing['is_default'] === 1) {
            $next = $pdo->prepare(
                'SELECT id FROM payment_accounts WHERE user_id = :uid AND role = :role
                 ORDER BY created_at DESC, id DESC LIMIT 1'
            );
            $next->execute(['uid' => $userId, 'role' => $existing['role']]);
            $nextId = $next->fetchColumn();
            if ($nextId !== false) {
                $pdo->prepare('UPDATE payment_accounts SET is_default = 1 WHERE user_id = :uid AND id = :id')
                    ->execute(['uid' => $userId, 'id' => (int) $nextId]);
            }
        }

        Response::success(['id' => $id, 'deleted' => true]);
    }

    /**
     * POST /api/accounts/{id}/default
     */
    public static function setDefault(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];
        $id      = (int) $request->param('id', '0');

        if ($id <= 0) {
            Response::badRequest('Identifiant de compte invalide.');
        }

        $pdo = Database::getConnection();
        $existing = self::fetchOne($pdo, $userId, $id);
        if ($existing === null) {
            Response::notFound('Compte introuvable.');
        }

        $pdo->beginTransaction();
        try {
            self::clearDefault($pdo, $userId, $existing['role']);
            $pdo->prepare('UPDATE payment_accounts SET is_default = 1
                           WHERE user_id = :uid AND id = :id')
                ->execute(['uid' => $userId, 'id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::success([
            'id'         => $id,
            'role'       => $existing['role'],
            'is_default' => true,
        ]);
    }

    // --- Validation / normalisation -----------------------------------------

    /**
     * Valide et normalise le payload. Retourne un tableau prêt à l'insertion.
     * @param array $body
     * @param array|null $existing compte existant (PUT) ou null (POST)
     */
    private static function validatePayload(array $body, ?array $existing): array
    {
        $role = strtolower(trim((string) ($body['role'] ?? '')));
        $kind = strtolower(trim((string) ($body['kind'] ?? '')));

        if ($existing === null) {
            if (!in_array($role, self::ALLOWED_ROLES, true)) {
                Response::badRequest('Le rôle doit être « source » ou « destination ».');
            }
            if (!in_array($kind, self::ALLOWED_KINDS, true)) {
                Response::badRequest('Type de compte invalide.');
            }
        }

        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            Response::badRequest('Le libellé est requis (120 caractères maximum).');
        }

        $country = strtoupper(trim((string) ($body['country'] ?? '')));
        if ($country !== '' && strlen($country) !== 2) {
            Response::badRequest('Le pays doit être un code ISO-2.');
        }
        if ($country !== '' && !in_array($country, self::ALLOWED_COUNTRIES, true)) {
            Response::badRequest('Pays non supporté.');
        }

        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));
        if ($currency !== '' && strlen($currency) > 5) {
            Response::badRequest('Devise invalide.');
        }

        $holder = trim((string) ($body['holder_name'] ?? ''));
        if (mb_strlen($holder) > 190) {
            Response::badRequest('Nom du titulaire trop long (190 caractères maximum).');
        }

        $data = [
            'role'        => $role,
            'kind'        => $kind,
            'label'       => $label,
            'holder_name' => $holder !== '' ? $holder : null,
            'country'     => $country !== '' ? $country : null,
            'currency'    => $currency !== '' ? $currency : null,
            'is_default'  => !empty($body['is_default']) ? 1 : 0,
        ];

        $existingKind = $existing['kind'] ?? $kind;
        $isUpdate = $existing !== null;
        $has = static fn (string $key): bool => array_key_exists($key, $body) && $body[$key] !== null && $body[$key] !== '';

        switch ($existingKind) {
            case 'bank_iban':
            case 'virtual_iban':
                if ($has('iban')) {
                    $iban = self::normalizeIban((string) $body['iban']);
                    if (!self::isValidIban($iban)) {
                        Response::badRequest('IBAN invalide (format et clé de contrôle).');
                    }
                    $data['iban_enc'] = Crypto::encrypt($iban);
                } elseif (!$isUpdate) {
                    Response::badRequest('L\'IBAN est requis.');
                } else {
                    // Conserver l'IBAN existant (PUT sans ressaisie).
                    $data['iban_enc'] = $existing['iban_enc'] ?? null;
                }

                if ($has('bic')) {
                    $bic = strtoupper(trim((string) $body['bic']));
                    if (!preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
                        Response::badRequest('BIC invalide.');
                    }
                    $data['bic'] = $bic !== '' ? $bic : null;
                } elseif ($isUpdate) {
                    $data['bic'] = $existing['bic'] ?? null;
                }
                break;

            case 'mobile_money':
                if ($country === '' && !$isUpdate) {
                    Response::badRequest('Le pays est requis pour Mobile Money.');
                }
                if ($country === '' && $isUpdate) {
                    $country = (string) ($existing['country'] ?? '');
                }

                if ($has('operator')) {
                    $operator = trim((string) $body['operator']);
                    $allowedOp = self::MOBILE_MONEY_OPERATORS[$country] ?? [];
                    if ($operator === '') {
                        Response::badRequest('L\'opérateur Mobile Money est requis.');
                    }
                    if (!empty($allowedOp) && !in_array($operator, $allowedOp, true)) {
                        Response::conflict('Opérateur non disponible pour ce pays.');
                    }
                    $data['operator'] = $operator;
                } elseif ($isUpdate) {
                    $data['operator'] = $existing['operator'] ?? null;
                } elseif (!$isUpdate) {
                    Response::badRequest('L\'opérateur Mobile Money est requis.');
                }

                if ($has('phone')) {
                    $phone = preg_replace('/\s+/', '', (string) $body['phone']);
                    if (!self::isValidPhone($phone)) {
                        Response::badRequest('Numéro de mobile invalide (chiffres uniquement, 8 à 15).');
                    }
                    $data['phone_enc'] = Crypto::encrypt($phone);
                } elseif (!$isUpdate) {
                    Response::badRequest('Le numéro de mobile est requis.');
                } else {
                    // Conserver le téléphone existant (PUT sans ressaisie).
                    $data['phone_enc'] = $existing['phone_enc'] ?? null;
                }
                break;

            case 'crypto_wallet':
                if ($has('network')) {
                    $network = trim((string) $body['network']);
                    if ($network === '' || !in_array($network, self::ALLOWED_NETWORKS, true)) {
                        Response::badRequest('Réseau blockchain invalide.');
                    }
                    $data['network'] = $network;
                } elseif ($isUpdate) {
                    $data['network'] = $existing['network'] ?? null;
                } elseif (!$isUpdate) {
                    Response::badRequest('Le réseau blockchain est requis.');
                }

                $effectiveNetwork = (string) ($data['network'] ?? '');
                if ($has('address')) {
                    $address = trim((string) $body['address']);
                    if (!self::isValidCryptoAddress($effectiveNetwork, $address)) {
                        Response::badRequest('Adresse blockchain invalide pour ce réseau.');
                    }
                    $data['address_enc'] = Crypto::encrypt($address);
                } elseif (!$isUpdate) {
                    Response::badRequest('L\'adresse du wallet est requise.');
                } else {
                    // Conserver l'adresse existante (PUT sans ressaisie).
                    $data['address_enc'] = $existing['address_enc'] ?? null;
                }
                break;

            case 'card':
                if ($has('pan')) {
                    $pan = preg_replace('/\s+/', '', (string) $body['pan']);
                    if (!self::isValidPan($pan)) {
                        Response::badRequest('Numéro de carte invalide (Luhn).');
                    }
                    $data['pan_enc'] = Crypto::encrypt($pan);
                } elseif (!$isUpdate) {
                    Response::badRequest('Le numéro de carte est requis.');
                } else {
                    $data['pan_enc'] = $existing['pan_enc'] ?? null;
                }

                if ($has('expiry')) {
                    $expiry = trim((string) $body['expiry']);
                    if (!preg_match('/^(0[1-9]|1[0-2])\/?([0-9]{2})$/', $expiry)) {
                        Response::badRequest('Date d\'expiration invalide (MM/AA).');
                    }
                    $data['expiry'] = $expiry;
                } elseif ($isUpdate) {
                    $data['expiry'] = $existing['expiry'] ?? null;
                }
                break;

            case 'cash_pickup':
                if ($has('operator')) {
                    $operator = trim((string) $body['operator']);
                    if ($operator === '' || !in_array($operator, self::CASH_PICKUP_NETWORKS, true)) {
                        Response::badRequest('Réseau cash pickup invalide. Choisissez Western Union ou MoneyGram.');
                    }
                    $data['operator'] = $operator;
                    $data['provider_slug'] = self::CASH_PICKUP_PROVIDER_SLUG[$operator] ?? null;
                } elseif ($isUpdate) {
                    $data['operator'] = $existing['operator'] ?? null;
                    $data['provider_slug'] = $existing['provider_slug'] ?? null;
                } else {
                    // Défaut : Western Union (moyen de paiement cash pickup / cashout).
                    $data['operator'] = 'Western Union';
                    $data['provider_slug'] = 'western_union';
                }

                $cityValue = $has('city') ? trim((string) $body['city']) : '';
                if ($cityValue === '') {
                    if ($isUpdate) {
                        $data['city'] = $existing['city'] ?? null;
                    } else {
                        Response::badRequest('La ville est requise pour un cash pickup.');
                    }
                } else {
                    if (mb_strlen($cityValue) > 120) {
                        Response::badRequest('Nom de ville trop long.');
                    }
                    $data['city'] = $cityValue;
                }
                break;
        }

        return $data;
    }

    /** Nettoie un IBAN : uppercase, retire les espaces, retire les séparateurs. */
    private static function normalizeIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '');
        return $iban;
    }

    /** Validation IBAN : longueur + clé de contrôle modulo 97. */
    private static function isValidIban(string $iban): bool
    {
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }
        $reordered = substr($iban, 4) . substr($iban, 0, 4);
        $expanded = '';
        for ($i = 0; $i < strlen($reordered); $i++) {
            $c = $reordered[$i];
            $expanded .= ctype_digit($c) ? $c : (string) (ord($c) - 55);
        }
        // Modulo 97 sur un nombre arbitrairement long.
        $mod = 0;
        for ($i = 0, $n = strlen($expanded); $i < $n; $i++) {
            $mod = ((int) ($mod . $expanded[$i])) % 97;
        }
        return $mod === 1;
    }

    private static function isValidPhone(string $phone): bool
    {
        return preg_match('/^\+?[0-9]{8,15}$/', $phone) === 1;
    }

    private static function isValidPan(string $pan): bool
    {
        if (!preg_match('/^[0-9]{13,19}$/', $pan)) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = strlen($pan) - 1; $i >= 0; $i--) {
            $n = (int) $pan[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) $n -= 9;
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    private static function isValidCryptoAddress(string $network, string $address): bool
    {
        $address = trim($address);
        if ($address === '') return false;
        switch ($network) {
            case 'Ethereum':
            case 'Polygon':
            case 'Arbitrum':
            case 'Optimism':
            case 'BNB Smart Chain':
            case 'Base':
                return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $address);
            case 'Bitcoin':
                return (bool) preg_match('/^(bc1[0-9a-z]{25,87}|[13][a-km-zA-HJ-NP-Z1-9]{25,34})$/', $address);
            case 'Tron':
                return (bool) preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address);
            case 'Solana':
                return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
        }
        // Réseau inconnu : on accepte mais on log.
        return strlen($address) >= 20 && strlen($address) <= 100;
    }

    // --- Helpers CRUD ---------------------------------------------------------

    private static function clearDefault(\PDO $pdo, int $userId, string $role): void
    {
        $pdo->prepare('UPDATE payment_accounts SET is_default = 0
                       WHERE user_id = :uid AND role = :role')
            ->execute(['uid' => $userId, 'role' => $role]);
    }

    private static function insert(\PDO $pdo, array $d): int
    {
        $cols = ['user_id','role','kind','label','holder_name','country','currency',
                 'operator','network','city','iban_enc','bic','phone_enc',
                 'pan_enc','expiry','address_enc','is_default',
                 'verification_status','supported_for_transfer','status','provider_slug'];
        $placeholders = ':' . implode(', :', $cols);
        $sql = "INSERT INTO payment_accounts (".implode(',', $cols).")
                VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $params = [
            'user_id'                => $d['user_id'],
            'role'                   => $d['role'],
            'kind'                   => $d['kind'],
            'label'                  => $d['label'],
            'holder_name'            => $d['holder_name'] ?? null,
            'country'                => $d['country'] ?? null,
            'currency'               => $d['currency'] ?? null,
            'operator'               => $d['operator'] ?? null,
            'network'                => $d['network'] ?? null,
            'city'                   => $d['city'] ?? null,
            'iban_enc'               => $d['iban_enc'] ?? null,
            'bic'                    => $d['bic'] ?? null,
            'phone_enc'              => $d['phone_enc'] ?? null,
            'pan_enc'                => $d['pan_enc'] ?? null,
            'expiry'                 => $d['expiry'] ?? null,
            'address_enc'            => $d['address_enc'] ?? null,
            'is_default'             => !empty($d['is_default']) ? 1 : 0,
            'verification_status'    => $d['verification_status'] ?? 'unverified',
            'supported_for_transfer' => $d['supported_for_transfer'] ?? 0,
            'status'                 => $d['status'] ?? 'active',
            'provider_slug'          => $d['provider_slug'] ?? null,
        ];
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    private static function updateRow(\PDO $pdo, int $userId, int $id, array $d): void
    {
        $sql = 'UPDATE payment_accounts SET
                  label = :label,
                  holder_name = :holder_name,
                  country = :country,
                  currency = :currency,
                  operator = :operator,
                  network = :network,
                  city = :city,
                  iban_enc = :iban_enc,
                  bic = :bic,
                  phone_enc = :phone_enc,
                  pan_enc = :pan_enc,
                  expiry = :expiry,
                  address_enc = :address_enc,
                  is_default = :is_default
                WHERE user_id = :uid AND id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'label'       => $d['label'],
            'holder_name' => $d['holder_name'] ?? null,
            'country'     => $d['country'] ?? null,
            'currency'    => $d['currency'] ?? null,
            'operator'    => $d['operator'] ?? null,
            'network'     => $d['network'] ?? null,
            'city'        => $d['city'] ?? null,
            'iban_enc'    => $d['iban_enc'] ?? null,
            'bic'         => $d['bic'] ?? null,
            'phone_enc'   => $d['phone_enc'] ?? null,
            'pan_enc'     => $d['pan_enc'] ?? null,
            'expiry'      => $d['expiry'] ?? null,
            'address_enc' => $d['address_enc'] ?? null,
            'is_default'  => !empty($d['is_default']) ? 1 : 0,
            'uid'         => $userId,
            'id'          => $id,
        ]);
    }

    /**
     * Récupère un compte de l'utilisateur (RLS forcée par user_id).
     */
    private static function fetchOne(\PDO $pdo, int $userId, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, role, kind, label, holder_name, country, currency, operator,
                    network, city, iban_enc, bic, phone_enc, pan_enc, expiry, address_enc,
                    is_default, verification_status, supported_for_transfer, status, provider_slug,
                    created_at, updated_at
             FROM payment_accounts
             WHERE user_id = :uid AND id = :id
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Convertit une ligne MySQL en représentation API publique
     * (jamais de valeur chiffrée en clair, jamais de PAN complet).
     */
    private static function toApi(array $row): array
    {
        $iban    = Crypto::decrypt($row['iban_enc'] ?? null);
        $phone   = Crypto::decrypt($row['phone_enc'] ?? null);
        $pan     = Crypto::decrypt($row['pan_enc'] ?? null);
        $address = Crypto::decrypt($row['address_enc'] ?? null);

        return [
            'id'                     => (int) $row['id'],
            'role'                   => $row['role'],
            'kind'                   => $row['kind'],
            'label'                  => $row['label'],
            'holder_name'            => $row['holder_name'],
            'country'                => $row['country'],
            'currency'               => $row['currency'],
            'operator'               => $row['operator'],
            'network'                => $row['network'],
            'city'                   => $row['city'],
            'bic'                    => $row['bic'],
            'expiry'                 => $row['expiry'],
            'is_default'             => (int) $row['is_default'] === 1,
            'verification_status'    => $row['verification_status'] ?? 'unverified',
            'supported_for_transfer' => ((int) ($row['supported_for_transfer'] ?? 0)) === 1,
            'status'                 => $row['status'] ?? 'active',
            'provider_slug'          => $row['provider_slug'] ?? null,
            'iban_masked'            => $iban   !== null ? self::maskIban($iban) : null,
            'phone_masked'           => $phone  !== null ? self::maskPhone($phone) : null,
            'pan_masked'             => $pan    !== null ? self::maskPan($pan) : null,
            'address_masked'         => $address!== null ? self::maskAddress($address) : null,
            'created_at'             => self::toIso8601($row['created_at']),
            'updated_at'             => self::toIso8601($row['updated_at']),
        ];
    }

    private static function maskIban(string $iban): string
    {
        $clean = preg_replace('/\s+/', '', $iban);
        if (strlen($clean) <= 8) {
            return str_repeat('•', strlen($clean));
        }
        return substr($clean, 0, 4) . ' ' . str_repeat('•', 4) . ' ' . str_repeat('•', 4) . ' ' . substr($clean, -4);
    }

    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 6) {
            return str_repeat('•', strlen($phone));
        }
        return substr($phone, 0, 3) . str_repeat('•', strlen($phone) - 6) . substr($phone, -3);
    }

    private static function maskPan(string $pan): string
    {
        $len = strlen($pan);
        if ($len <= 8) return str_repeat('•', $len);
        return substr($pan, 0, 4) . ' ' . str_repeat('•', 4) . ' ' . str_repeat('•', $len - 8) . ' ' . substr($pan, -4);
    }

    private static function maskAddress(string $address): string
    {
        $len = strlen($address);
        if ($len <= 12) return substr($address, 0, 4) . '…' . substr($address, -4);
        return substr($address, 0, 6) . '…' . substr($address, -6);
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? $mysqlDatetime : gmdate('c', $ts);
    }

    // --- Données de référence exposées en lecture seule ----------------------

    /**
     * GET /api/accounts/operators?country=CG
     * Renvoie la liste des opérateurs Mobile Money disponibles pour un pays.
     */
    public static function listOperators(Request $request): void
    {
        AuthMiddleware::handle($request);
        $country = strtoupper((string) $request->query('country', ''));
        if ($country === '' || strlen($country) !== 2) {
            Response::badRequest('Pays (ISO-2) requis.');
        }
        Response::success([
            'country'   => $country,
            'operators' => self::MOBILE_MONEY_OPERATORS[$country] ?? [],
        ]);
    }

    /** GET /api/accounts/networks — réseaux blockchain supportés. */
    public static function listNetworks(Request $request): void
    {
        AuthMiddleware::handle($request);
        Response::success([
            'networks' => self::ALLOWED_NETWORKS,
        ]);
    }

    /**
     * GET /api/accounts/cash-pickup-networks
     * Réseaux agents pour cash pickup / cashout (Western Union prioritaire).
     */
    public static function listCashPickupNetworks(Request $request): void
    {
        AuthMiddleware::handle($request);
        $items = [];
        foreach (self::CASH_PICKUP_NETWORKS as $name) {
            $items[] = [
                'name'          => $name,
                'provider_slug' => self::CASH_PICKUP_PROVIDER_SLUG[$name] ?? null,
            ];
        }
        Response::success(['networks' => $items]);
    }

    /**
     * GET /api/accounts/authorized-origins
     *
     * Retourne les origines autorisées pour l'utilisateur authentifié,
     * calculées côté backend à partir des sources de financement vérifiées.
     *
     * Le frontend utilise cet endpoint pour afficher le sélecteur
     * "Depuis quel pays souhaitez-vous envoyer les fonds ?"
     * avec uniquement les pays réellement disponibles.
     */
    public static function authorizedOrigins(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $origins = FundingSourceEngine::getAuthorizedOrigins($userId, $user);

        Response::success([
            'origins'             => $origins,
            'total'               => count($origins),
            'country_of_residence' => $user['country_of_residence'] ?? null,
            'kyc_level'           => $user['kyc_level'] ?? 'none',
        ]);
    }
}
