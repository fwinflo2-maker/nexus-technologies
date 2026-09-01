<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Crypto;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\BusinessService;

/**
 * BeneficiaryController — gestion des bénéficiaires Business.
 * Sécurité : ownership + rôle vérifiés côté backend.
 */
final class BeneficiaryController
{
    /** GET /api/beneficiaries?business_id=&status= */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager', 'accountant', 'operator', 'viewer'], 'consulter les bénéficiaires');

        $status = (string) $request->query('status', 'active');
        $pdo    = Database::getConnection();

        if ($status !== '' && in_array($status, ['active', 'inactive', 'pending_verification'], true)) {
            $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE user_id = :uid AND status = :s ORDER BY name ASC');
            $stmt->execute(['uid' => $bid, 's' => $status]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE user_id = :uid ORDER BY name ASC');
            $stmt->execute(['uid' => $bid]);
        }

        $items = array_map([self::class, 'format'], $stmt->fetchAll());
        Response::success(['items' => $items]);
    }

    /** POST /api/beneficiaries */
    public static function create(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], BusinessService::ROLES, 'créer un bénéficiaire');

        $name  = trim((string) $request->input('name', ''));
        $country = strtoupper(trim((string) $request->input('country', '')));
        $currency = strtoupper(trim((string) $request->input('currency', 'XAF')));
        $method = strtolower(trim((string) $request->input('method', 'mobile_money')));
        $ref   = trim((string) $request->input('account_reference', ''));

        if ($name === '' || strlen($name) > 190) {
            Response::badRequest('Le nom du bénéficiaire est requis (190 caractères max).');
        }
        if (strlen($country) !== 2) {
            Response::badRequest('Le pays doit être un code ISO-2.');
        }
        if (!in_array($method, ['mobile_money', 'bank', 'crypto', 'cash_pickup'], true)) {
            Response::badRequest('Méthode de paiement invalide.');
        }
        if ($ref === '') {
            Response::badRequest('La référence de compte est requise (IBAN, téléphone, adresse…).');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO beneficiaries (user_id, name, country, currency, method, account_reference_enc, operator, bank_name, status, verification_status)
             VALUES (:uid, :name, :country, :currency, :method, :ref, :operator, :bank, :status, :vstatus)'
        );
        $stmt->execute([
            'uid'      => $bid,
            'name'     => $name,
            'country'  => $country,
            'currency' => $currency,
            'method'   => $method,
            'ref'      => Crypto::encrypt($ref),
            'operator' => trim((string) $request->input('operator', '')) ?: null,
            'bank'     => trim((string) $request->input('bank_name', '')) ?: null,
            'status'   => 'active',
            'vstatus'  => 'unverified',
        ]);

        $id  = (int) $pdo->lastInsertId();
        $row = self::find($pdo, $bid, $id);
        Response::success(['beneficiary' => self::format($row)], 201);
    }

    /** PUT /api/beneficiaries/{id} */
    public static function update(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager', 'operator'], 'modifier un bénéficiaire');

        $id   = (int) $request->param('id', '0');
        $pdo  = Database::getConnection();
        $row  = self::find($pdo, $bid, $id);

        $name = trim((string) $request->input('name', $row['name']));
        $ref  = trim((string) $request->input('account_reference', ''));

        $stmt = $pdo->prepare(
            'UPDATE beneficiaries SET name = :name,
                 account_reference_enc = :ref,
                 operator = :operator,
                 bank_name = :bank
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            'name'     => $name,
            'ref'      => $ref !== '' ? Crypto::encrypt($ref) : $row['account_reference_enc'],
            'operator' => trim((string) $request->input('operator', $row['operator'] ?? '')) ?: null,
            'bank'     => trim((string) $request->input('bank_name', $row['bank_name'] ?? '')) ?: null,
            'id'       => $id,
            'uid'      => $bid,
        ]);

        $updated = self::find($pdo, $bid, $id);
        Response::success(['beneficiary' => self::format($updated)]);
    }

    /** POST /api/beneficiaries/{id}/deactivate */
    public static function deactivate(Request $request): void
    {
        self::setStatus($request, 'inactive');
    }

    /** POST /api/beneficiaries/{id}/activate */
    public static function activate(Request $request): void
    {
        self::setStatus($request, 'active');
    }

    /** POST /api/beneficiaries/{id}/verify */
    public static function verify(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager'], 'vérifier un bénéficiaire');

        $id  = (int) $request->param('id', '0');
        $pdo = Database::getConnection();
        self::find($pdo, $bid, $id);

        $stmt = $pdo->prepare("UPDATE beneficiaries SET verification_status = 'verified' WHERE id = :id AND user_id = :uid");
        $stmt->execute(['id' => $id, 'uid' => $bid]);

        Response::success(['beneficiary' => self::format(self::find($pdo, $bid, $id))]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function setStatus(Request $request, string $status): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager', 'operator'], 'changer le statut d\'un bénéficiaire');

        $id  = (int) $request->param('id', '0');
        $pdo = Database::getConnection();
        self::find($pdo, $bid, $id);

        $stmt = $pdo->prepare('UPDATE beneficiaries SET status = :s WHERE id = :id AND user_id = :uid');
        $stmt->execute(['s' => $status, 'id' => $id, 'uid' => $bid]);

        Response::success(['beneficiary' => self::format(self::find($pdo, $bid, $id))]);
    }

    /** @return array<string,mixed> */
    private static function find(\PDO $pdo, int $bid, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $id, 'uid' => $bid]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(404, 'Bénéficiaire introuvable.', 'BENEFICIARY_NOT_FOUND');
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function format(array $row): array
    {
        $reference = Crypto::decrypt($row['account_reference_enc']);
        $masked = $reference !== null && $reference !== ''
            ? substr($reference, 0, 2) . str_repeat('•', max(4, strlen($reference) - 6)) . substr($reference, -4)
            : null;
        $row['id'] = (int) $row['id'];
        unset($row['account_reference_enc']);
        $row['account_reference'] = $reference;
        $row['reference_masked']  = $masked;
        return $row;
    }
}
