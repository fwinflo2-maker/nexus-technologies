<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\ExecutionContext;
use Nexus\Services\ExecutionEngine;
use PDO;

/**
 * TransferController — exécution et historique réel des transferts.
 *
 *  - POST /api/transfers      → exécute une route de quote (saga déterministe)
 *  - GET  /api/transfers      → historique paginé + filtrable (source réelle)
 *  - GET  /api/transfers/{id} → détail d'une transaction
 *
 * Toutes les routes sont protégées (JWT) et l'ownership est vérifiée
 * côté serveur (jamais de confiance au frontend).
 */
final class TransferController
{
    private const ALLOWED_TYPES   = ['send', 'receive', 'fx', 'convert'];
    private const ALLOWED_STATUS  = ['completed', 'processing', 'pending', 'failed', 'cancelled'];

    /**
     * POST /api/transfers
     *
     * @body{quote_id, route_id, idempotency_key?}
     * (la clé d'idempotence peut aussi être passée via l'en-tête Idempotency-Key)
     */
    public static function execute(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        // Contexte d'exécution : résolu UNE fois, en amont, puis transporté.
        // Toute demande d'environnement non autorisée s'arrête ici (403).
        $context = ExecutionContext::fromRequest($request, $user);

        $quoteId = trim((string) $request->input('quote_id', ''));
        $routeId = trim((string) $request->input('route_id', ''));

        if ($quoteId === '' || $routeId === '') {
            Response::badRequest('quote_id et route_id sont requis.');
        }

        $idemKey = trim((string) $request->input('idempotency_key', ''));
        if ($idemKey === '') {
            $idemKey = trim((string) $request->header('Idempotency-Key', ''));
        }

        $transaction = ExecutionEngine::execute($userId, $quoteId, $routeId, $idemKey !== '' ? $idemKey : null, $context);

        Response::success(['transaction' => $transaction], 201);
    }

    /**
     * GET /api/transfers
     *
     * @query{page, per_page, type, status, currency}
     */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        $page     = max(1, (int) $request->query('page', 1));
        $perPage  = min(100, max(1, (int) $request->query('per_page', 25)));
        $type     = (string) $request->query('type', '');
        $status   = (string) $request->query('status', '');
        $currency = strtoupper((string) $request->query('currency', ''));

        // §20 — l'historique est scopé par environnement : un transfert de
        // test ne doit pas apparaître dans l'historique d'argent réel.
        $context = ExecutionContext::fromRequest($request, $user);

        $where  = ['user_id = :uid', 'environment = :env'];
        $params = ['uid' => $userId, 'env' => $context->environmentValue()];

        if ($type !== '' && in_array($type, self::ALLOWED_TYPES, true)) {
            $where[]         = 'type = :type';
            $params['type']  = $type;
        }
        if ($status !== '' && in_array($status, self::ALLOWED_STATUS, true)) {
            $where[]           = 'status = :status';
            $params['status']  = $status;
        }
        if ($currency !== '' && preg_match('/^[A-Z]{3,5}$/', $currency) === 1) {
            $where[]           = 'currency = :cur';
            $params['cur']     = $currency;
        }

        $whereSql = implode(' AND ', $where);
        $pdo      = Database::getConnection();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            "SELECT * FROM transactions
             WHERE {$whereSql}
             ORDER BY created_at DESC, id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map([self::class, 'formatTransaction'], $stmt->fetchAll());

        Response::success([
            'items'    => $items,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    /**
     * GET /api/transfers/{id}
     */
    public static function show(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];
        $id      = (int) $request->param('id', '0');

        if ($id <= 0) {
            Response::badRequest('Identifiant de transaction invalide.');
        }

        // Une transaction de l'autre environnement est « introuvable » : le
        // détail d'un mouvement d'argent réel ne s'ouvre pas depuis une vue
        // de test.
        $context = ExecutionContext::fromRequest($request, $user);

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM transactions WHERE id = :id AND user_id = :uid AND environment = :env LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId, 'env' => $context->environmentValue()]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new HttpException(404, 'Transaction introuvable.', 'TRANSACTION_NOT_FOUND');
        }

        Response::success(['transaction' => self::formatTransaction($row)]);
    }

    /**
     * Normalise les types numériques pour la sérialisation JSON.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function formatTransaction(array $row): array
    {
        $intFields   = ['id', 'execution_time_seconds'];
        $floatFields = ['amount', 'amount_ref', 'amount_xaf', 'fee', 'dest_amount', 'fx_rate'];

        foreach ($intFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        foreach ($floatFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (float) $row[$field];
            }
        }
        return $row;
    }
}
