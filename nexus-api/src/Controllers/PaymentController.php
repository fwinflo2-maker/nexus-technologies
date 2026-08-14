<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\BusinessService;
use PDO;
use Throwable;

/**
 * PaymentController — workflow paiement Business :
 *   create (draft) → submit (pending_approval) → approve/reject
 *   → execute (saga : hold → capture → ledger) → completed.
 *
 * Les approbations sont vérifiées côté backend (jamais contournables via UI).
 */
final class PaymentController
{
    private const STATUSES = ['draft', 'pending_approval', 'approved', 'executing', 'completed', 'failed', 'rejected', 'cancelled'];

    /** GET /api/payments?business_id=&status=&page=&per_page= */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], BusinessService::ROLES, 'consulter les paiements');

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $status  = (string) $request->query('status', '');

        $pdo    = Database::getConnection();
        $where  = 'user_id = :uid';
        $params = ['uid' => $bid];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }

        $count = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map([self::class, 'format'], $stmt->fetchAll());
        Response::success(['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }

    /** GET /api/payments/{id} */
    public static function show(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], BusinessService::ROLES, 'consulter un paiement');

        $payment = self::find(Database::getConnection(), $bid, (int) $request->param('id', '0'));
        Response::success(['payment' => self::format($payment)]);
    }

    /** POST /api/payments — crée un paiement (draft) avec quote réelle. */
    public static function create(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager', 'operator'], 'créer un paiement');

        $beneficiaryId = (int) $request->input('beneficiary_id', 0);
        $sourceCurrency = strtoupper(trim((string) $request->input('source_currency', 'EUR')));
        $destCurrency  = strtoupper(trim((string) $request->input('dest_currency', '')));
        $purpose       = trim((string) $request->input('purpose', ''));
        $objective     = strtolower(trim((string) $request->input('objective', 'optimized')));

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM beneficiaries WHERE id = :id AND user_id = :uid AND status = 'active' LIMIT 1");
        $stmt->execute(['id' => $beneficiaryId, 'uid' => $bid]);
        $beneficiary = $stmt->fetch();
        if ($beneficiary === false) {
            Response::badRequest('Bénéficiaire introuvable ou inactif.');
        }

        $draft = [
            'amount'          => (float) $request->input('amount', 0),
            'source_currency' => $sourceCurrency,
            'dest_currency'   => $destCurrency !== '' ? $destCurrency : (string) $beneficiary['currency'],
            'objective'       => $objective,
        ];

        $quote = BusinessService::quotePayment($bid, $beneficiary, $draft);
        $best  = $quote['best'];
        $intent = $quote['intent'];

        // Frais en devise source (le quote exprime les frais en EUR).
        $rateRef  = Currency::rateToRef((string) $intent['sourceCurrency']);
        $feeSource = $rateRef > 0.0 ? round((float) ($best['feesNum'] ?? 0) / $rateRef, 2) : 0.0;
        $amountRef = round((float) $intent['amount'] * $rateRef, 2);

        $ins = $pdo->prepare(
            'INSERT INTO payments
                (user_id, beneficiary_id, purpose, source_currency, dest_currency,
                 amount, amount_ref, fee, fee_currency, dest_amount, fx_rate,
                 provider, route_id, destination, status, created_by)
             VALUES
                (:uid, :bid, :purpose, :scur, :dcur,
                 :amount, :aref, :fee, :feecur, :damount, :fxr,
                 :prov, :rid, :dest, :status, :created_by)'
        );
        $ins->execute([
            'uid'        => $bid,
            'bid'        => $beneficiaryId,
            'purpose'    => $purpose !== '' ? $purpose : null,
            'scur'       => (string) $intent['sourceCurrency'],
            'dcur'       => (string) $intent['destCurrency'],
            'amount'     => (float) $intent['amount'],
            'aref'       => $amountRef,
            'fee'        => $feeSource,
            'feecur'     => (string) $intent['sourceCurrency'],
            'damount'    => (float) ($best['receivedNum'] ?? 0),
            'fxr'        => (float) ($best['rate'] ?? 0) > 0 ? number_format((float) $best['rate'], 8, '.', '') : null,
            'prov'       => (string) ($best['provider'] ?? ''),
            'rid'        => (string) ($best['id'] ?? ''),
            'dest'       => BusinessService::paymentDestination($beneficiary),
            'status'     => 'draft',
            'created_by' => (int) $actor['id'],
        ]);

        $payment = self::find($pdo, $bid, (int) $pdo->lastInsertId());
        Response::success([
            'payment' => self::format($payment),
            'routes'  => $quote['routes'],
            'beneficiary' => self::beneficiaryName($beneficiary),
        ], 201);
    }

    /** POST /api/payments/{id}/submit */
    public static function submit(Request $request): void
    {
        self::transition($request, 'draft', 'pending_approval', ['owner', 'admin', 'finance_manager', 'operator']);
    }

    /** POST /api/payments/{id}/approve */
    public static function approve(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager'], 'approuver un paiement');

        $pdo     = Database::getConnection();
        $payment = self::find($pdo, $bid, (int) $request->param('id', '0'));

        if ($payment['status'] !== 'pending_approval') {
            Response::conflict('Seul un paiement en attente d\'approbation peut être approuvé.');
        }

        $stmt = $pdo->prepare(
            "UPDATE payments SET status = 'approved', approved_by = :by, approved_at = NOW() WHERE id = :id AND user_id = :uid"
        );
        $stmt->execute(['by' => (int) $actor['id'], 'id' => (int) $payment['id'], 'uid' => $bid]);

        Response::success(['payment' => self::format(self::find($pdo, $bid, (int) $payment['id']))]);
    }

    /** POST /api/payments/{id}/reject */
    public static function reject(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager'], 'rejeter un paiement');

        $pdo     = Database::getConnection();
        $payment = self::find($pdo, $bid, (int) $request->param('id', '0'));

        if ($payment['status'] !== 'pending_approval') {
            Response::conflict('Seul un paiement en attente d\'approbation peut être rejeté.');
        }

        $stmt = $pdo->prepare("UPDATE payments SET status = 'rejected', approved_by = :by, approved_at = NOW() WHERE id = :id AND user_id = :uid");
        $stmt->execute(['by' => (int) $actor['id'], 'id' => (int) $payment['id'], 'uid' => $bid]);

        Response::success(['payment' => self::format(self::find($pdo, $bid, (int) $payment['id']))]);
    }

    /** POST /api/payments/{id}/execute — saga réelle (hold → capture → ledger). */
    public static function execute(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager'], 'exécuter un paiement');

        $pdo     = Database::getConnection();
        $payment = self::find($pdo, $bid, (int) $request->param('id', '0'));

        if ($payment['status'] !== 'approved') {
            Response::conflict('Seul un paiement approuvé peut être exécuté.');
        }

        // Bénéficiaire (peut être null si supprimé — on bloque dans ce cas).
        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $payment['beneficiary_id']]);
        $beneficiary = $stmt->fetch();
        if ($beneficiary === false) {
            Response::conflict('Le bénéficiaire de ce paiement n\'existe plus.');
        }

        // Marque executing (visible) puis exécute la saga.
        $upd = $pdo->prepare("UPDATE payments SET status = 'executing' WHERE id = :id AND user_id = :uid");
        $upd->execute(['id' => (int) $payment['id'], 'uid' => $bid]);

        try {
            $tx = BusinessService::executePayment($bid, $payment, $beneficiary);
        } catch (Throwable $e) {
            $fail = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = :id AND user_id = :uid");
            $fail->execute(['id' => (int) $payment['id'], 'uid' => $bid]);
            throw $e;
        }

        $done = $pdo->prepare(
            "UPDATE payments SET status = 'completed', executed_at = NOW(), transaction_id = :txid WHERE id = :id AND user_id = :uid"
        );
        $done->execute(['txid' => (int) $tx['id'], 'id' => (int) $payment['id'], 'uid' => $bid]);

        Response::success(['payment' => self::format(self::find($pdo, $bid, (int) $payment['id'])), 'transaction' => $tx]);
    }

    /** POST /api/payments/{id}/cancel */
    public static function cancel(Request $request): void
    {
        self::transition($request, 'draft', 'cancelled', ['owner', 'admin', 'finance_manager', 'operator']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function transition(Request $request, string $from, string $to, array $roles): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], $roles, 'modifier un paiement');

        $pdo     = Database::getConnection();
        $payment = self::find($pdo, $bid, (int) $request->param('id', '0'));

        if ($payment['status'] !== $from) {
            Response::conflict(sprintf('Transition impossible : statut actuel « %s », attendu « %s ».', $payment['status'], $from));
        }

        $stmt = $pdo->prepare('UPDATE payments SET status = :to WHERE id = :id AND user_id = :uid');
        $stmt->execute(['to' => $to, 'id' => (int) $payment['id'], 'uid' => $bid]);

        Response::success(['payment' => self::format(self::find($pdo, $bid, (int) $payment['id']))]);
    }

    /** @return array<string,mixed> */
    private static function find(PDO $pdo, int $bid, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $id, 'uid' => $bid]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(404, 'Paiement introuvable.', 'PAYMENT_NOT_FOUND');
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function format(array $row): array
    {
        $intFields   = ['id', 'beneficiary_id', 'created_by', 'approved_by', 'transaction_id'];
        $floatFields = ['amount', 'amount_ref', 'fee', 'dest_amount', 'fx_rate'];
        foreach ($intFields as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $row[$f] = (int) $row[$f];
            }
        }
        foreach ($floatFields as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $row[$f] = (float) $row[$f];
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $b */
    private static function beneficiaryName(array $b): array
    {
        return ['id' => (int) $b['id'], 'name' => (string) $b['name']];
    }
}
