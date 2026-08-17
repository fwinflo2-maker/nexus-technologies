<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;

/**
 * Centre de notifications (toutes les routes sont protégées).
 *
 *  - GET  /api/notifications              → liste paginée (filtres type, non-lues) ;
 *  - GET  /api/notifications/unread-count → nombre de notifications non lues ;
 *  - POST /api/notifications/{id}/read    → marque une notification comme lue ;
 *  - POST /api/notifications/read-all     → marque toutes les notifications comme lues.
 *
 * Types de notifications : transfert, quote, kyc, securite, business, systeme.
 * La même constante alimente les notifications de démo du premier login.
 */
final class NotificationController
{
    /** Types de notifications autorisés (filtrés côté UI et API). */
    public const ALLOWED_TYPES = ['transfert', 'quote', 'kyc', 'securite', 'business', 'systeme'];

    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 50;

    /**
     * GET /api/notifications
     *
     * Paramètres de requête :
     *  - type     : transfert | quote | kyc | securite | business | systeme (optionnel) ;
     *  - unread   : 1/true → non lues uniquement, 0/false → lues uniquement (optionnel) ;
     *  - page     : page courante (défaut 1) ;
     *  - per_page : taille de page (défaut 20, max 50).
     */
    public static function list(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];

        // --- Filtres ---------------------------------------------------------
        $type = strtolower(trim((string) $request->query('type', '')));
        if ($type !== '' && !in_array($type, self::ALLOWED_TYPES, true)) {
            Response::badRequest('Type de notification invalide.');
        }

        $unread = $request->query('unread');
        $filterUnread = in_array($unread, ['1', 'true', '0', 'false'], true);

        // --- Pagination ------------------------------------------------------
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $pdo = Database::getConnection();

        // --- Requête (filtres dynamiques, paramètres nommés) -----------------
        $where  = ['user_id = :uid'];
        $params = ['uid' => $userId];

        if ($type !== '') {
            $where[]        = 'type = :type';
            $params['type'] = $type;
        }
        if ($filterUnread) {
            $where[] = ($unread === '1' || $unread === 'true')
                ? 'read_at IS NULL'
                : 'read_at IS NOT NULL';
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$whereSql}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $totalPages = (int) ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;

        // $perPage et $offset sont des entiers validés : interpolation sûre.
        $stmt = $pdo->prepare(
            "SELECT id, type, title, message, read_at, created_at
             FROM notifications
             WHERE {$whereSql}
             ORDER BY created_at DESC, id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $items = array_map(
            static fn (array $n): array => [
                'id'         => (int) $n['id'],
                'type'       => $n['type'],
                'title'      => $n['title'],
                'message'    => $n['message'],
                'is_read'    => $n['read_at'] !== null,
                'read_at'    => $n['read_at'] !== null ? self::toIso8601($n['read_at']) : null,
                'created_at' => self::toIso8601($n['created_at']),
            ],
            $stmt->fetchAll()
        );

        Response::success([
            'items'        => $items,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => $totalPages,
            'unread_count' => self::unreadCountFor($pdo, $userId),
        ]);
    }

    /** GET /api/notifications/unread-count — badge de la cloche. */
    public static function unreadCount(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];

        Response::success([
            'unread_count' => self::unreadCountFor(Database::getConnection(), $userId),
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     *
     * Marque une notification comme lue (idempotent : déjà lue → succès).
     * Vérifie que la notification appartient bien à l'utilisateur connecté.
     */
    public static function readOne(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];
        $id      = (int) $request->param('id', '0');

        if ($id <= 0) {
            Response::badRequest('Identifiant de notification invalide.');
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT 1 FROM notifications WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);

        if ($stmt->fetch() === false) {
            Response::notFound('Notification introuvable.');
        }

        // Marquage idempotent : la lecture n'est mise à jour que si nécessaire.
        $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND read_at IS NULL')
            ->execute(['id' => $id]);

        Response::success([
            'id'           => $id,
            'read'         => true,
            'unread_count' => self::unreadCountFor($pdo, $userId),
        ]);
    }

    /** POST /api/notifications/read-all — marque toutes les notifications comme lues. */
    public static function readAll(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $userId  = (int) $request->attribute('user')['id'];

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = :uid AND read_at IS NULL'
        );
        $stmt->execute(['uid' => $userId]);

        Response::success([
            'updated'      => (int) $stmt->rowCount(),
            'unread_count' => 0,
        ]);
    }

    // --- Helpers privés --------------------------------------------------------

    /** Nombre de notifications non lues d'un utilisateur. */
    private static function unreadCountFor(\PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL'
        );
        $stmt->execute(['uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Convertit une date MySQL en ISO 8601 avec fuseau (base en UTC). */
    private static function toIso8601(string $mysqlDatetime): string
    {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? $mysqlDatetime : gmdate('c', $ts);
    }
}
