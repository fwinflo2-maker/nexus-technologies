<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\BusinessService;

/**
 * ReconciliationController — rapprochement ledger Nexus ↔ relevés provider.
 *
 * Chaque transfert sortant (send, completed) peut être rapproché avec un
 * relevé provider (référence + montant réel). Le statut est calculé :
 *   - matched      : montant réel == montant attendu (dest_amount)
 *   - discrepancy  : écart détecté
 *   - pending      : aucun relevé fourni
 *   - resolved     : pointé/résolu manuellement
 */
final class ReconciliationController
{
    /** GET /api/reconciliation?business_id=&status= */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], BusinessService::ROLES, 'consulter le rapprochement');

        $status = (string) $request->query('status', '');
        $pdo    = Database::getConnection();

        $sql = "SELECT t.id AS tx_id, t.amount, t.currency, t.dest_amount, t.dest_currency,
                       t.provider, t.destination, t.status AS tx_status, t.created_at,
                       r.id AS item_id, r.provider_reference, r.actual_amount,
                       r.status AS recon_status, r.notes, r.resolved_at
                FROM transactions t
                LEFT JOIN reconciliation_items r ON r.transaction_id = t.id AND r.user_id = t.user_id
                WHERE t.user_id = :uid AND t.type = 'send' AND t.status = 'completed'";
        $params = ['uid' => $bid];
        if ($status !== '' && in_array($status, ['pending', 'matched', 'unmatched', 'discrepancy', 'resolved'], true)) {
            if ($status === 'pending') {
                $sql .= ' AND (r.id IS NULL OR r.status = :s)';
            } else {
                $sql .= ' AND r.status = :s';
            }
            $params['s'] = $status;
        }
        $sql .= ' ORDER BY t.created_at DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $items = array_map(static function (array $row): array {
            $expected = $row['dest_amount'] !== null ? (float) $row['dest_amount'] : (float) $row['amount'];
            $currency = $row['dest_currency'] !== null ? (string) $row['dest_currency'] : (string) $row['currency'];
            $actual   = $row['actual_amount'] !== null ? (float) $row['actual_amount'] : null;
            $status   = $row['recon_status'] !== null ? (string) $row['recon_status'] : 'pending';
            if ($status === 'pending' && $actual !== null) {
                $status = abs($actual - $expected) < 0.01 ? 'matched' : 'discrepancy';
            }
            return [
                'transaction_id'    => (int) $row['tx_id'],
                'item_id'           => $row['item_id'] !== null ? (int) $row['item_id'] : null,
                'provider'          => (string) $row['provider'],
                'destination'       => (string) $row['destination'],
                'expected_amount'   => $expected,
                'actual_amount'     => $actual,
                'currency'          => $currency,
                'provider_reference' => (string) ($row['provider_reference'] ?? ''),
                'status'            => $status,
                'notes'             => (string) ($row['notes'] ?? ''),
                'created_at'        => (string) $row['created_at'],
                'resolved_at'       => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            ];
        }, $stmt->fetchAll());

        Response::success(['items' => $items]);
    }

    /** POST /api/reconciliation — enregistre un relevé provider pour une transaction. */
    public static function upsert(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager', 'accountant'], 'rapprocher une transaction');

        $txId   = (int) $request->input('transaction_id', 0);
        $ref    = trim((string) $request->input('provider_reference', ''));
        $actual = (float) $request->input('actual_amount', 0);

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = :id AND user_id = :uid AND type = 'send' LIMIT 1");
        $stmt->execute(['id' => $txId, 'uid' => $bid]);
        $tx = $stmt->fetch();
        if ($tx === false) {
            throw new HttpException(404, 'Transaction introuvable.', 'TRANSACTION_NOT_FOUND');
        }

        $expected = $tx['dest_amount'] !== null ? (float) $tx['dest_amount'] : (float) $tx['amount'];
        $currency = $tx['dest_currency'] !== null ? (string) $tx['dest_currency'] : (string) $tx['currency'];
        $status   = abs($actual - $expected) < 0.01 ? 'matched' : 'discrepancy';

        $stmt = $pdo->prepare(
            'INSERT INTO reconciliation_items (user_id, transaction_id, provider_reference, expected_amount, actual_amount, currency, status)
             VALUES (:uid, :txid, :ref, :expected, :actual, :currency, :status)
             ON DUPLICATE KEY UPDATE
                provider_reference = VALUES(provider_reference),
                actual_amount      = VALUES(actual_amount),
                status             = VALUES(status),
                resolved_at        = NULL'
        );
        // ON DUPLICATE KEY nécessite une clé unique sur transaction_id.
        $stmt->execute([
            'uid'      => $bid,
            'txid'     => $txId,
            'ref'      => $ref !== '' ? $ref : null,
            'expected' => $expected,
            'actual'   => $actual,
            'currency' => $currency,
            'status'   => $status,
        ]);

        Response::success(['transaction_id' => $txId, 'status' => $status]);
    }

    /** POST /api/reconciliation/{id}/resolve — marque un item comme résolu. */
    public static function resolve(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager', 'accountant'], 'résoudre un rapprochement');

        $id    = (int) $request->param('id', '0');
        $notes = trim((string) $request->input('notes', ''));

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            "UPDATE reconciliation_items SET status = 'resolved', notes = :notes, resolved_at = NOW() WHERE id = :id AND user_id = :uid"
        );
        $stmt->execute(['notes' => $notes !== '' ? $notes : null, 'id' => $id, 'uid' => $bid]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'Item de rapprochement introuvable.', 'RECON_ITEM_NOT_FOUND');
        }

        Response::success(['resolved' => true]);
    }
}
