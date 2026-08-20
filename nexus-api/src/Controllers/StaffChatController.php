<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\PlatformRole;

/**
 * Messagerie interne du personnel Nexus (employees ⇄ superadmin).
 *
 * Accessible à TOUT employé interne authentifié (platform_role ≠ user),
 * superadmin inclus. Un employé ne voit et n'écrit que dans les fils dont il
 * est membre. Les escalades support créent un fil lié au ticket
 * (related_conversation_id) entre l'agent et le spécialiste.
 *
 * Non-lus : comptés par membre via internal_chat_members.last_read_at
 * (messages envoyés par les autres après la dernière lecture).
 */
final class StaffChatController
{
    private static function currentUser(Request $request): array
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');

        if (PlatformRole::of($user) === PlatformRole::USER) {
            throw new \Nexus\Core\HttpException(403, 'Réservé au personnel interne.', PlatformRole::ERROR_CODE);
        }
        return $user;
    }

    private static function audit(\PDO $pdo, int $userId, string $action, ?string $entityType, ?int $entityId, array $metadata): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :metadata, :ip_address)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'action'     => $action,
            'entity_type'=> $entityType,
            'entity_id'  => $entityId,
            'metadata'   => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    }

    /** Est-ce qu'un utilisateur est membre du fil ? */
    private static function isMember(\PDO $pdo, int $chatId, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM internal_chat_members WHERE chat_id = :c AND user_id = :u');
        $stmt->execute(['c' => $chatId, 'u' => $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Exige l'appartenance au fil, sinon 403. */
    private static function requireMember(\PDO $pdo, int $chatId, int $userId): void
    {
        if (!self::isMember($pdo, $chatId, $userId)) {
            Response::forbidden('Vous n\'êtes pas membre de cette discussion.');
        }
    }

    // ─── Endpoints ────────────────────────────────────────────────────────

    /**
     * GET /api/control/staff/directory — annuaire du personnel interne.
     *
     * Liste des employés (jamais les clients) avec rôle, département et
     * console — sert au choix d'un destinataire (chat) et d'un spécialiste
     * (escalade support).
     */
    public static function directory(Request $request): void
    {
        self::currentUser($request);
        $pdo = Database::getConnection();

        $rows = $pdo->query(
            "SELECT u.id, u.full_name, u.email, u.platform_role,
                    e.department, e.status AS employee_status
             FROM users u
             LEFT JOIN employees e ON e.user_id = u.id
             WHERE u.platform_role <> 'user'
             ORDER BY u.full_name"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int) $r['id'],
                'full_name'      => $r['full_name'],
                'email'          => $r['email'],
                'platform_role'  => $r['platform_role'],
                'department'     => $r['department'],
                'dashboard'      => PlatformRole::dashboardForRole((string) $r['platform_role']),
                'active'         => ($r['employee_status'] ?? 'active') !== 'disabled',
            ];
        }

        Response::success(['items' => $out, 'total' => count($out)]);
    }

    /**
     * GET /api/control/staff/chats — fils de discussion de l'employé.
     *
     * Chaque fil : titre, ticket lié éventuel, membres, aperçu du dernier
     * message, nombre de non-lus pour l'utilisateur connecté.
     */
    public static function chats(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo  = Database::getConnection();
        $uid  = (int) $user['id'];

        $rows = $pdo->prepare(
            "SELECT c.id, c.title, c.creator_id, c.related_conversation_id, c.status, c.updated_at,
                    sc.subject AS ticket_subject,
                    (SELECT COUNT(*) FROM internal_chat_messages m
                     WHERE m.chat_id = c.id AND m.sender_id <> :uid_unread
                       AND (mem.last_read_at IS NULL OR m.created_at > mem.last_read_at)) AS unread,
                    (SELECT m.body FROM internal_chat_messages m
                     WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT u2.full_name FROM internal_chat_messages m
                     JOIN users u2 ON u2.id = m.sender_id
                     WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_sender
             FROM internal_chats c
             JOIN internal_chat_members mem ON mem.chat_id = c.id AND mem.user_id = :uid_join
             LEFT JOIN support_conversations sc ON sc.id = c.related_conversation_id
             ORDER BY c.updated_at DESC"
        );
        $rows->execute(['uid_unread' => $uid, 'uid_join' => $uid]);
        $chats = $rows->fetchAll();

        $out = [];
        foreach ($chats as $ch) {
            $members = $pdo->prepare(
                'SELECT u.id, u.full_name, u.email, u.platform_role
                 FROM internal_chat_members m JOIN users u ON u.id = m.user_id
                 WHERE m.chat_id = :c'
            );
            $members->execute(['c' => $ch['id']]);
            $out[] = [
                'id'                    => (int) $ch['id'],
                'title'                 => $ch['title'],
                'creator_id'            => (int) $ch['creator_id'],
                'related_conversation_id' => $ch['related_conversation_id'] !== null ? (int) $ch['related_conversation_id'] : null,
                'ticket_subject'        => $ch['ticket_subject'],
                'status'                => $ch['status'],
                'updated_at'            => $ch['updated_at'],
                'unread'                => (int) $ch['unread'],
                'last_body'             => $ch['last_body'],
                'last_sender'           => $ch['last_sender'],
                'members'               => $members->fetchAll(),
            ];
        }

        Response::success(['items' => $out, 'total' => count($out)]);
    }

    /**
     * POST /api/control/staff/chats — crée une discussion interne.
     *
     * Body : { title, member_ids: [id…], related_conversation_id? }
     * Le créateur est toujours membre. Les membres doivent être du personnel.
     */
    public static function createChat(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo  = Database::getConnection();

        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            Response::badRequest('Le titre de la discussion est requis.');
        }
        if (mb_strlen($title) > 190) {
            Response::badRequest('Titre trop long (190 caractères max).');
        }
        $memberIds = $request->input('member_ids');
        if (!is_array($memberIds)) {
            $memberIds = [];
        }
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        $memberIds = array_filter($memberIds, static fn (int $id): bool => $id > 0);
        $memberIds = array_values(array_diff($memberIds, [(int) $user['id']]));

        // Tous les membres doivent exister et être du personnel interne.
        if (count($memberIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $stmt = $pdo->prepare("SELECT id, platform_role FROM users WHERE id IN ($placeholders)");
            $stmt->execute($memberIds);
            $found = $stmt->fetchAll();
            if (count($found) !== count($memberIds)) {
                Response::badRequest('Un ou plusieurs membres sont introuvables.');
            }
            foreach ($found as $f) {
                if ($f['platform_role'] === 'user') {
                    Response::badRequest('Les clients ne peuvent pas rejoindre une discussion interne.');
                }
            }
        }

        $relConv = (int) ($request->input('related_conversation_id', 0) ?? 0);
        if ($relConv > 0) {
            $conv = $pdo->prepare('SELECT COUNT(*) FROM support_conversations WHERE id = :c');
            $conv->execute(['c' => $relConv]);
            if ((int) $conv->fetchColumn() === 0) {
                Response::badRequest('Ticket support lié introuvable.');
            }
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO internal_chats (title, creator_id, related_conversation_id)
                 VALUES (:t, :c, :r)'
            );
            $ins->execute(['t' => $title, 'c' => (int) $user['id'], 'r' => $relConv > 0 ? $relConv : null]);
            $chatId = (int) $pdo->lastInsertId();

            // Le créateur a déjà « lu » ; les autres membres démarrent à NULL
            // (tout message des autres compte comme non lu).
            $memCreator = $pdo->prepare('INSERT INTO internal_chat_members (chat_id, user_id, last_read_at) VALUES (?, ?, NOW())');
            $memCreator->execute([$chatId, (int) $user['id']]);
            $memOther = $pdo->prepare('INSERT INTO internal_chat_members (chat_id, user_id) VALUES (?, ?)');
            foreach ($memberIds as $mid) {
                $memOther->execute([$chatId, $mid]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::audit($pdo, (int) $user['id'], 'staff.chat_create', 'internal_chat', $chatId, [
            'title' => $title,
            'members' => $memberIds,
        ]);

        Response::success(['id' => $chatId, 'title' => $title], 201);
    }

    /**
     * GET /api/control/staff/chats/{id}/messages — messages du fil.
     *
     * Optionnel : ?after_id= pour le long-polling. Marque le fil comme lu
     * pour l'utilisateur connecté (last_read_at).
     */
    public static function messages(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo  = Database::getConnection();
        $chatId = (int) ($request->param('id') ?? 0);
        if ($chatId <= 0) {
            Response::badRequest('Identifiant de discussion invalide.');
        }
        self::requireMember($pdo, $chatId, (int) $user['id']);

        $after = (int) ($request->query('after_id') ?? 0);
        $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.is_system, m.body, m.created_at, u.full_name AS sender_name, u.platform_role
             FROM internal_chat_messages m JOIN users u ON u.id = m.sender_id
             WHERE m.chat_id = :c AND m.id > :after
             ORDER BY m.id ASC'
        );
        $stmt->execute(['c' => $chatId, 'after' => $after]);
        $messages = $stmt->fetchAll();

        // Marquer lu : tout ce qui est postérieur à la dernière lecture.
        $pdo->prepare('UPDATE internal_chat_members SET last_read_at = NOW() WHERE chat_id = :c AND user_id = :u')
            ->execute(['c' => $chatId, 'u' => (int) $user['id']]);

        Response::success(['items' => $messages, 'chat_id' => $chatId]);
    }

    /**
     * POST /api/control/staff/chats/{id}/messages — envoie un message.
     * Body : { body }
     */
    public static function sendMessage(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo  = Database::getConnection();
        $chatId = (int) ($request->param('id') ?? 0);
        if ($chatId <= 0) {
            Response::badRequest('Identifiant de discussion invalide.');
        }
        self::requireMember($pdo, $chatId, (int) $user['id']);

        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            Response::badRequest('Le message ne peut pas être vide.');
        }
        if (mb_strlen($body) > 4000) {
            Response::badRequest('Message trop long (4000 caractères max).');
        }

        $ins = $pdo->prepare(
            'INSERT INTO internal_chat_messages (chat_id, sender_id, is_system, body)
             VALUES (:c, :s, 0, :b)'
        );
        $ins->execute(['c' => $chatId, 's' => (int) $user['id'], 'b' => $body]);
        $msgId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE internal_chat_members SET last_read_at = NOW() WHERE chat_id = :c AND user_id = :u')
            ->execute(['c' => $chatId, 'u' => (int) $user['id']]);

        Response::success(['id' => $msgId], 201);
    }

    /** POST /api/control/staff/chats/{id}/read — marque le fil lu (compteur). */
    public static function markRead(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo  = Database::getConnection();
        $chatId = (int) ($request->param('id') ?? 0);
        if ($chatId <= 0) {
            Response::badRequest('Identifiant de discussion invalide.');
        }
        self::requireMember($pdo, $chatId, (int) $user['id']);
        $pdo->prepare('UPDATE internal_chat_members SET last_read_at = NOW() WHERE chat_id = :c AND user_id = :u')
            ->execute(['c' => $chatId, 'u' => (int) $user['id']]);
        Response::success(['chat_id' => $chatId]);
    }
}
