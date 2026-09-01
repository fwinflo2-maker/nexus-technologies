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
    private const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;

    /** @return array<string, string> */
    private static function attachmentAllowedMimes(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
    }

    private static function isValidStaffAttachmentUrl(string $url): bool
    {
        return (bool) preg_match(
            '#^/uploads/staff/[a-f0-9]{24}\.(jpe?g|png|gif|webp|pdf)$#i',
            $url
        );
    }

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

    /**
     * Contexte ticket support + client pour un fil d'escalade.
     *
     * @return array<string,mixed>|null
     */
    private static function fetchTicketContext(\PDO $pdo, int $conversationId, bool $withMessages = false): ?array
    {
        if ($conversationId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT sc.id, sc.subject, sc.category, sc.status, sc.priority, sc.created_at, sc.updated_at,
                    cu.id AS client_id, cu.full_name AS client_name, cu.email AS client_email,
                    cu.phone AS client_phone, cu.account_type AS client_account_type,
                    cu.status AS client_status, cu.kyc_level AS client_kyc_level,
                    cu.kyb_status AS client_kyb_status, cu.risk_level AS client_risk_level,
                    cu.country_of_residence AS client_country, cu.company_name AS client_company
             FROM support_conversations sc
             JOIN users cu ON cu.id = sc.user_id
             WHERE sc.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $conversationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $context = [
            'id'         => (int) $row['id'],
            'subject'    => (string) $row['subject'],
            'category'   => $row['category'],
            'status'     => (string) $row['status'],
            'priority'   => (string) $row['priority'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'client'     => [
                'id'           => (int) $row['client_id'],
                'full_name'    => (string) $row['client_name'],
                'email'        => (string) $row['client_email'],
                'phone'        => $row['client_phone'],
                'account_type' => (string) $row['client_account_type'],
                'status'       => (string) $row['client_status'],
                'kyc_level'    => (string) $row['client_kyc_level'],
                'kyb_status'   => (string) $row['client_kyb_status'],
                'risk_level'   => $row['client_risk_level'],
                'country'      => $row['client_country'],
                'company_name' => $row['client_company'],
            ],
        ];

        if (!$withMessages) {
            return $context;
        }

        $msgs = $pdo->prepare(
            "SELECT m.id, m.customer_id, m.agent_id, m.is_bot, m.body,
                    m.attachment_name, m.attachment_url, m.created_at,
                    cu.full_name AS customer_name,
                    au.full_name AS agent_name
             FROM support_messages m
             LEFT JOIN users cu ON cu.id = m.customer_id
             LEFT JOIN users au ON au.id = m.agent_id
             WHERE m.conversation_id = :conv AND m.is_internal = 0
             ORDER BY m.id ASC
             LIMIT 80"
        );
        $msgs->execute(['conv' => $conversationId]);
        $context['support_messages'] = array_map(static function (array $m): array {
            $from = 'system';
            $name = 'Système';
            if ((int) $m['is_bot'] === 1) {
                $from = 'bot';
                $name = 'Fin';
            } elseif ($m['agent_id'] !== null) {
                $from = 'agent';
                $name = (string) ($m['agent_name'] ?? 'Agent');
            } elseif ($m['customer_id'] !== null) {
                $from = 'client';
                $name = (string) ($m['customer_name'] ?? 'Client');
            }

            return [
                'id'              => (int) $m['id'],
                'from'            => $from,
                'sender_name'     => $name,
                'body'            => (string) $m['body'],
                'attachment_name' => $m['attachment_name'],
                'attachment_url'  => $m['attachment_url'],
                'created_at'      => (string) $m['created_at'],
            ];
        }, $msgs->fetchAll());

        return $context;
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
                    sc.subject AS ticket_subject, sc.category AS ticket_category,
                    sc.status AS ticket_status, sc.priority AS ticket_priority,
                    cu.id AS client_id, cu.full_name AS client_name, cu.email AS client_email,
                    cu.phone AS client_phone, cu.account_type AS client_account_type,
                    cu.status AS client_status, cu.kyc_level AS client_kyc_level,
                    cu.risk_level AS client_risk_level,
                    (SELECT COUNT(*) FROM internal_chat_messages m
                     WHERE m.chat_id = c.id AND m.sender_id <> :uid_unread
                       AND (mem.last_read_at IS NULL OR m.created_at > mem.last_read_at)) AS unread,
                    (SELECT CASE
                        WHEN m.body <> '' THEN m.body
                        WHEN m.attachment_name IS NOT NULL THEN CONCAT('📎 ', m.attachment_name)
                        WHEN m.attachment_url IS NOT NULL THEN '📎 Pièce jointe'
                        ELSE NULL END
                     FROM internal_chat_messages m
                     WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT u2.full_name FROM internal_chat_messages m
                     JOIN users u2 ON u2.id = m.sender_id
                     WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_sender
             FROM internal_chats c
             JOIN internal_chat_members mem ON mem.chat_id = c.id AND mem.user_id = :uid_join
             LEFT JOIN support_conversations sc ON sc.id = c.related_conversation_id
             LEFT JOIN users cu ON cu.id = sc.user_id
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
            $item = [
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
            if ($ch['related_conversation_id'] !== null && $ch['client_id'] !== null) {
                $item['ticket'] = [
                    'id'       => (int) $ch['related_conversation_id'],
                    'subject'  => (string) ($ch['ticket_subject'] ?? ''),
                    'category' => $ch['ticket_category'],
                    'status'   => (string) ($ch['ticket_status'] ?? ''),
                    'priority' => (string) ($ch['ticket_priority'] ?? ''),
                    'client'   => [
                        'id'           => (int) $ch['client_id'],
                        'full_name'    => (string) $ch['client_name'],
                        'email'        => (string) $ch['client_email'],
                        'phone'        => $ch['client_phone'],
                        'account_type' => (string) ($ch['client_account_type'] ?? 'personal'),
                        'status'       => (string) ($ch['client_status'] ?? ''),
                        'kyc_level'    => (string) ($ch['client_kyc_level'] ?? 'none'),
                        'risk_level'   => $ch['client_risk_level'],
                    ],
                ];
            }
            $out[] = $item;
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
        $ticketContext = null;
        if ($after === 0) {
            $convStmt = $pdo->prepare('SELECT related_conversation_id FROM internal_chats WHERE id = :c LIMIT 1');
            $convStmt->execute(['c' => $chatId]);
            $convId = (int) ($convStmt->fetchColumn() ?: 0);
            if ($convId > 0) {
                $ticketContext = self::fetchTicketContext($pdo, $convId, true);
            }
        }

        $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.is_system, m.body, m.attachment_name, m.attachment_url,
                    m.created_at, u.full_name AS sender_name, u.platform_role
             FROM internal_chat_messages m JOIN users u ON u.id = m.sender_id
             WHERE m.chat_id = :c AND m.id > :after
             ORDER BY m.id ASC'
        );
        $stmt->execute(['c' => $chatId, 'after' => $after]);
        $messages = $stmt->fetchAll();

        // Marquer lu : tout ce qui est postérieur à la dernière lecture.
        $pdo->prepare('UPDATE internal_chat_members SET last_read_at = NOW() WHERE chat_id = :c AND user_id = :u')
            ->execute(['c' => $chatId, 'u' => (int) $user['id']]);

        $payload = ['items' => $messages, 'chat_id' => $chatId];
        if ($ticketContext !== null) {
            $payload['ticket_context'] = $ticketContext;
        }
        Response::success($payload);
    }

    /**
     * POST /api/control/staff/chats/{id}/messages — envoie un message.
     * Body : { body, attachment_name?, attachment_url? }
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
        $attName = trim((string) $request->input('attachment_name', ''));
        $attUrl  = trim((string) $request->input('attachment_url', ''));
        if ($attUrl !== '') {
            if (!self::isValidStaffAttachmentUrl($attUrl)) {
                Response::badRequest('Pièce jointe invalide ou non autorisée.');
            }
            if ($attName === '') {
                $attName = basename($attUrl);
            }
            if (mb_strlen($attName) > 180) {
                $attName = mb_substr($attName, 0, 180);
            }
        } else {
            $attName = '';
        }
        if ($body === '' && $attUrl === '') {
            Response::badRequest('Le message ne peut pas être vide.');
        }
        if ($body !== '' && mb_strlen($body) > 4000) {
            Response::badRequest('Message trop long (4000 caractères max).');
        }

        $ins = $pdo->prepare(
            'INSERT INTO internal_chat_messages (chat_id, sender_id, is_system, body, attachment_name, attachment_url)
             VALUES (:c, :s, 0, :b, :an, :au)'
        );
        $ins->execute([
            'c'  => $chatId,
            's'  => (int) $user['id'],
            'b'  => $body,
            'an' => $attName !== '' ? $attName : null,
            'au' => $attUrl !== '' ? $attUrl : null,
        ]);
        $msgId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE internal_chat_members SET last_read_at = NOW() WHERE chat_id = :c AND user_id = :u')
            ->execute(['c' => $chatId, 'u' => (int) $user['id']]);
        $pdo->prepare('UPDATE internal_chats SET updated_at = NOW() WHERE id = :c')
            ->execute(['c' => $chatId]);

        Response::success(['id' => $msgId], 201);
    }

    /**
     * POST /api/control/staff/attachments — upload pièce jointe (images + PDF).
     * Auth : personnel interne. Optionnel : chat_id (vérifie l'appartenance).
     */
    public static function uploadAttachment(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo  = Database::getConnection();

        $chatId = (int) ($request->input('chat_id') ?? $request->query('chat_id') ?? 0);
        if ($chatId > 0) {
            self::requireMember($pdo, $chatId, (int) $user['id']);
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::badRequest('Aucun fichier reçu.');
        }
        if (($file['size'] ?? 0) > self::ATTACHMENT_MAX_BYTES) {
            Response::badRequest('Fichier trop volumineux (5 Mo max).');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::badRequest('Fichier upload invalide.');
        }

        $allowed = self::attachmentAllowedMimes();
        $mime = '';
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($tmp);
            $mime = is_string($detected) ? $detected : '';
        }
        if ($mime === '' || $mime === 'application/octet-stream') {
            $clientMime = strtolower(trim((string) ($file['type'] ?? '')));
            if (isset($allowed[$clientMime])) {
                $mime = $clientMime;
            }
        }
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }
        if (!isset($allowed[$mime])) {
            Response::badRequest('Type de fichier non autorisé (images ou PDF uniquement).');
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/staff';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Response::error('Impossible de préparer le stockage des pièces jointes.', 500, 'INTERNAL_ERROR');
        }

        $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            Response::error('Impossible d\'enregistrer le fichier.', 500, 'INTERNAL_ERROR');
        }

        $original = basename((string) ($file['name'] ?? 'fichier'));
        $original = preg_replace('/[\x00-\x1F\x7F<>:"\\\\|?*]/u', '_', $original) ?? 'fichier';
        if ($original === '' || $original === '.' || $original === '..') {
            $original = 'fichier.' . $allowed[$mime];
        }
        if (mb_strlen($original) > 180) {
            $original = mb_substr($original, 0, 180);
        }

        self::audit($pdo, (int) $user['id'], 'staff.attachment_upload', 'internal_chat', $chatId > 0 ? $chatId : null, [
            'name' => $original,
            'mime' => $mime,
            'bytes' => (int) ($file['size'] ?? 0),
        ]);

        Response::success([
            'url'  => '/uploads/staff/' . $name,
            'name' => $original,
            'mime' => $mime,
            'size' => (int) ($file['size'] ?? 0),
        ], 201);
    }

    /**
     * DELETE /api/control/staff/chats/{id}/messages/{messageId}
     *
     * Supprime un message du fil. Réservé à l'auteur du message, ou au
     * superadmin pour modération. Les messages système (escalade) ne sont
     * supprimables que par le superadmin.
     */
    public static function deleteMessage(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo  = Database::getConnection();
        $chatId = (int) ($request->param('id') ?? 0);
        $msgId  = (int) ($request->param('messageId') ?? 0);
        if ($chatId <= 0 || $msgId <= 0) {
            Response::badRequest('Identifiants invalides.');
        }
        self::requireMember($pdo, $chatId, (int) $user['id']);

        $stmt = $pdo->prepare(
            'SELECT id, chat_id, sender_id, is_system FROM internal_chat_messages
             WHERE id = :mid AND chat_id = :cid LIMIT 1'
        );
        $stmt->execute(['mid' => $msgId, 'cid' => $chatId]);
        $message = $stmt->fetch();
        if ($message === false) {
            Response::notFound('Message introuvable.');
        }

        $isSuperadmin = PlatformRole::isSuperadmin($user);
        $isOwner      = (int) $message['sender_id'] === (int) $user['id'];
        $isSystem     = (int) $message['is_system'] === 1;

        if ($isSystem && !$isSuperadmin) {
            Response::forbidden('Les messages système ne peuvent pas être supprimés.');
        }
        if (!$isOwner && !$isSuperadmin) {
            Response::forbidden('Vous ne pouvez supprimer que vos propres messages.');
        }

        $pdo->prepare('DELETE FROM internal_chat_messages WHERE id = :mid AND chat_id = :cid')
            ->execute(['mid' => $msgId, 'cid' => $chatId]);

        $pdo->prepare('UPDATE internal_chats SET updated_at = NOW() WHERE id = :cid')
            ->execute(['cid' => $chatId]);

        self::audit($pdo, (int) $user['id'], 'staff.chat_message_delete', 'internal_chat_message', $msgId, [
            'chat_id'   => $chatId,
            'was_system'=> $isSystem,
            'owner_id'  => (int) $message['sender_id'],
        ]);

        Response::success(['id' => $msgId, 'chat_id' => $chatId]);
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
