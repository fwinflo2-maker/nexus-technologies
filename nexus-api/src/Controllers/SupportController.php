<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;

/**
 * Support chat — tickets & conversations (client ↔ agent / bot).
 *
 * RBAC :
 *   * Un CLIENT (authentifié) ne voit et n'écrit QUE dans ses propres
 *     conversations. Il ne voit JAMAIS les notes internes des agents.
 *   * Un AGENT (customer_support, support_operator, superadmin, …) voit toutes
 *     les conversations, peut répondre, laisser des notes internes, changer le
 *     statut.
 *
 * Flux "bot pré-ticket" :
 *   L'utilisateur discute d'abord avec le bot SANS créer de ticket. Un ticket
 *   n'est ouvert QUE lorsque :
 *     - le bot ne sait pas répondre (escalade automatique), OU
 *     - l'utilisateur demande explicitement un agent humain.
 *   À ce moment, toute la discussion pré-ticket est jointe au ticket.
 *
 * Temps réel : long-polling côté client (?after_id).
 */
final class SupportController
{
    private const AGENT_ROLES = [
        'customer_support', 'support_operator', 'superadmin',
    ];

    private const CATEGORIES = ['account', 'transfer', 'kyc', 'billing', 'other'];

    private static function currentUser(Request $request): array
    {
        $request = AuthMiddleware::handle($request);
        return $request->attribute('user');
    }

    private static function isAgent(array $user): bool
    {
        $role = (string) ($user['platform_role'] ?? 'user');
        if ($role === 'superadmin') {
            return true;
        }
        return in_array($role, self::AGENT_ROLES, true);
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

    // ─── Endpoints ────────────────────────────────────────────────────────

    /**
     * POST /api/support/bot — le bot répond SANS créer de ticket.
     * Body : { message }
     * → { reply, escalate, category, subject }
     *   * escalate = true  → le bot ne sait pas répondre ou l'utilisateur veut un humain
     *   * escalate = false → réponse du bot (aucun ticket créé)
     */
    public static function bot(Request $request): void
    {
        self::currentUser($request); // authentification requise
        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            Response::badRequest('Le message est requis.');
        }
        Response::success(self::analyzeBot($message));
    }

    /**
     * GET /api/support/unread — compteur de messages non lus (côté client).
     * → { total, conversations: [{id, unread}] }
     */
    public static function unread(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();
        $uid = (int) $user['id'];

        $rows = $pdo->prepare(
            'SELECT c.id, COUNT(m.id) AS unread
             FROM support_conversations c
             LEFT JOIN support_messages m ON m.conversation_id = c.id
               AND m.agent_id IS NOT NULL AND m.is_internal = 0 AND m.read_at IS NULL
             WHERE c.user_id = :uid
             GROUP BY c.id'
        );
        $rows->execute(['uid' => $uid]);
        $items = $rows->fetchAll();
        $total = 0;
        foreach ($items as $it) {
            $total += (int) $it['unread'];
        }
        Response::success(['total' => $total, 'conversations' => $items]);
    }

    /**
     * GET /api/support/conversations — liste les conversations de l'utilisateur.
     */
    public static function conversations(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();

        if (self::isAgent($user)) {
            $stmt = $pdo->query(
                'SELECT c.id, c.subject, c.category, c.status, c.priority, c.created_at, c.updated_at,
                        c.assigned_to, u.full_name AS client_name, u.email AS client_email
                 FROM support_conversations c
                 JOIN users u ON u.id = c.user_id
                 ORDER BY (c.status = \'open\') DESC, c.updated_at DESC'
            );
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND customer_id IS NOT NULL AND read_at IS NULL');
                $q->execute([$r['id']]);
                $r['unread'] = (int) $q->fetchColumn();
            }
        } else {
            $stmt = $pdo->prepare(
                'SELECT c.id, c.subject, c.category, c.status, c.priority, c.created_at, c.updated_at, c.assigned_to
                 FROM support_conversations c
                 WHERE c.user_id = :uid
                 ORDER BY c.updated_at DESC'
            );
            $stmt->execute(['uid' => (int) $user['id']]);
            $rows = $stmt->fetchAll();
        }

        Response::success(['items' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/support/conversations — ouvre un ticket (généralement à l'escalade).
     * Body : { subject, category?, history?: [{sender:'customer'|'bot', body}], priority? }
     */
    public static function createConversation(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();

        $subject  = trim((string) $request->input('subject', ''));
        $category = trim((string) $request->input('category', ''));
        if ($subject === '') {
            Response::badRequest('Le sujet est requis.');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'other';
        }
        $history = $request->input('history');
        if (!is_array($history)) {
            $history = [];
        }
        $priority = (string) $request->input('priority', 'normal');
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO support_conversations (user_id, subject, category, status, priority)
                 VALUES (:uid, :subject, :category, \'open\', :priority)'
            );
            $ins->execute(['uid' => (int) $user['id'], 'subject' => $subject, 'category' => $category, 'priority' => $priority]);
            $convId = (int) $pdo->lastInsertId();

            // Message d'ouverture du client (le premier message / résumé).
            $msg = $pdo->prepare(
                'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                 VALUES (?, ?, NULL, 0, ?, NOW())'
            );
            $msg->execute([$convId, (int) $user['id'], $subject]);

            // Historique pré-ticket (discussion avec le bot) rejointe au ticket.
            if (count($history) > 0) {
                $hist = $pdo->prepare(
                    'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                     VALUES (?, ?, NULL, ?, ?, NOW())'
                );
                foreach ($history as $h) {
                    $sender = ($h['sender'] ?? 'customer') === 'bot' ? 'bot' : 'customer';
                    $bodyH = trim((string) ($h['body'] ?? ''));
                    if ($bodyH === '') {
                        continue;
                    }
                    $isBot = $sender === 'bot' ? 1 : 0;
                    $cid = $isBot ? null : (int) $user['id'];
                    $hist->execute([$convId, $cid, $isBot, $bodyH]);
                }
            }

            // Message de prise en charge par un agent (escalade).
            $esc = $pdo->prepare(
                'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                 VALUES (?, NULL, NULL, 0, ?, NOW())'
            );
            $esc->execute([$convId, '📨 Ticket transmis à un agent humain. Nous traitons votre demande et vous répondons ici.']);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::audit($pdo, (int) $user['id'], 'support.conversation_open', 'support_conversation', $convId, ['category' => $category, 'priority' => $priority]);

        Response::success([
            'conversation' => ['id' => $convId, 'subject' => $subject, 'category' => $category, 'status' => 'open', 'priority' => $priority],
        ], 201);
    }

    /**
     * GET /api/support/conversations/{id}/messages — long-polling.
     * Un client ne reçoit JAMAIS les notes internes (is_internal = 1).
     */
    public static function messages(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();
        $convId = (int) ($request->param('id') ?? 0);

        if (!self::canAccess($pdo, $user, $convId)) {
            Response::forbidden('Accès refusé à cette conversation.');
        }

        $isAgent = self::isAgent($user);
        $after = (int) ($request->query('after_id') ?? 0);
        $internalFilter = $isAgent ? '' : 'AND is_internal = 0';

        $sql = 'SELECT id, customer_id, agent_id, is_bot, is_internal, body, attachment_name, attachment_url, read_at, created_at,
                       (SELECT full_name FROM users WHERE id = customer_id) AS customer_name,
                       (SELECT full_name FROM users WHERE id = agent_id) AS agent_name
                FROM support_messages
                WHERE conversation_id = :cid ' . $internalFilter . ' AND id > :after
                ORDER BY id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['cid' => $convId, 'after' => $after]);

        $messages = $stmt->fetchAll();

        // Marquer comme lu.
        if ($isAgent) {
            $upd = $pdo->prepare('UPDATE support_messages SET read_at = NOW() WHERE conversation_id = ? AND customer_id IS NOT NULL AND read_at IS NULL');
        } else {
            $upd = $pdo->prepare('UPDATE support_messages SET read_at = NOW() WHERE conversation_id = ? AND agent_id IS NOT NULL AND is_internal = 0 AND read_at IS NULL');
        }
        $upd->execute([$convId]);

        Response::success(['items' => $messages]);
    }

    /**
     * POST /api/support/conversations/{id}/messages — envoie un message.
     * Le bot auto répond aux messages clients SAUF en cas d'escalade déjà ouverte.
     * Body : { body, is_internal?, attachment_name?, attachment_url? }
     */
    public static function sendMessage(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();
        $convId = (int) ($request->param('id') ?? 0);

        $body = trim((string) $request->input('body', ''));
        if ($body === '' && !$request->input('attachment_url')) {
            Response::badRequest('Le message ne peut pas être vide.');
        }
        if (!self::canAccess($pdo, $user, $convId)) {
            Response::forbidden('Accès refusé à cette conversation.');
        }

        $isAgent = self::isAgent($user);
        $isInternal = (int) (bool) $request->input('is_internal', false);
        if ($isInternal && !$isAgent) {
            Response::forbidden('Seul un agent peut laisser une note interne.');
        }
        $attName = $isAgent ? (string) $request->input('attachment_name', '') : '';
        $attUrl  = $isAgent ? (string) $request->input('attachment_url', '') : '';
        $agentId = $isAgent ? (int) $user['id'] : null;
        $customerId = $isAgent ? null : (int) $user['id'];

        if (!$isAgent) {
            $pdo->prepare("UPDATE support_conversations SET status = 'open' WHERE id = ? AND status IN ('resolved','closed')")
                ->execute([$convId]);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, is_internal, body, attachment_name, attachment_url, created_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([$convId, $customerId, $agentId, $isInternal, $body, $attName !== '' ? $attName : null, $attUrl !== '' ? $attUrl : null]);

            // Réponse du bot : uniquement si le message vient d'un client ET
            // qu'il ne s'agit pas d'une note interne ET que le ticket est "open"
            // (un ticket escaladé est pris en charge par un humain).
            $botReply = null;
            if (!$isAgent && !$isInternal) {
                $statusRow = $pdo->prepare('SELECT status FROM support_conversations WHERE id = ?');
                $statusRow->execute([$convId]);
                $status = $statusRow->fetchColumn();
                if ($status === 'open') {
                    $analysis = self::analyzeBot($body);
                    if ($analysis['escalate']) {
                        // L'utilisateur demande un humain ou le bot ne sait pas :
                        // on re-route vers un agent, sans réponse bot.
                        $pdo->prepare("UPDATE support_conversations SET status = 'waiting' WHERE id = ?")
                            ->execute([$convId]);
                        $botReply = "Un agent humain va prendre en charge votre demande. Merci de patienter quelques instants.";
                        $bot = $pdo->prepare(
                            'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                             VALUES (?, NULL, NULL, 1, ?, NOW())'
                        );
                        $bot->execute([$convId, $botReply]);
                    } elseif ($analysis['reply'] !== null) {
                        $botReply = $analysis['reply'];
                        $bot = $pdo->prepare(
                            'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                             VALUES (?, NULL, NULL, 1, ?, NOW())'
                        );
                        $bot->execute([$convId, $botReply]);
                    }
                }
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::audit($pdo, (int) $user['id'], $isAgent ? 'support.agent_reply' : 'support.customer_message', 'support_conversation', $convId, ['internal' => $isInternal === 1]);
        Response::success(['bot_reply' => $botReply]);
    }

    /**
     * PATCH /api/support/conversations/{id}/status — (agent) change le statut.
     */
    public static function setStatus(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();
        $convId = (int) ($request->param('id') ?? 0);

        if (!self::isAgent($user)) {
            Response::forbidden('Seul un agent peut modifier le statut.');
        }
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['open', 'waiting', 'resolved', 'closed'], true)) {
            Response::badRequest('Statut invalide.');
        }

        $pdo->prepare('UPDATE support_conversations SET status = :s, assigned_to = :agent WHERE id = :id')
            ->execute(['s' => $status, 'agent' => (int) $user['id'], 'id' => $convId]);

        self::audit($pdo, (int) $user['id'], 'support.conversation_status', 'support_conversation', $convId, ['status' => $status]);
        Response::success(['status' => $status]);
    }

    /**
     * POST /api/support/attachments — upload d'une pièce jointe (client ou agent).
     * Renvoie { url, name }. Fichiers : images, PDF, texte, < 5 Mo.
     */
    public static function uploadAttachment(Request $request): void
    {
        self::currentUser($request); // authentification requise

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::badRequest('Aucun fichier reçu.');
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            Response::badRequest('Fichier trop volumineux (5 Mo max).');
        }

        $allowed = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'application/pdf' => 'pdf', 'text/plain' => 'txt',
        ];
        $mime = (string) ($file['type'] ?? '');
        if (!isset($allowed[$mime])) {
            Response::badRequest('Type de fichier non autorisé (images, PDF ou texte).');
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/support';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::error('Impossible d\'enregistrer le fichier.', 500, 'INTERNAL_ERROR');
        }

        Response::success([
            'url' => '/uploads/support/' . $name,
            'name' => basename((string) ($file['name'] ?? 'fichier')),
        ], 201);
    }

    // ─── Analyse du bot ───────────────────────────────────────────────────

    /**
     * Analyse un message et décide : réponse directe OU escalade.
     * Retourne { reply, escalate, category, subject }.
     */
    private static function analyzeBot(string $body): array
    {
        $text = mb_strtolower($body);

        // 1. Demande explicite d'un humain → escalade immédiate.
        $humanIntent = [
            'agent', 'humain', 'conseiller', 'operateur', 'parler à quelqu', 'parler a quelqu',
            'vraie personne', 'assistance humaine', 'un être humain', 'advisor', 'real agent',
            'votre supérieur', 'manager', 'appeler', 'tel', 'réclamation', 'plainte',
        ];
        foreach ($humanIntent as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return [
                    'reply'    => null,
                    'escalate' => true,
                    'category' => 'other',
                    'subject'  => mb_substr($body, 0, 120),
                ];
            }
        }

        $rules = [
            'transfert|transfer|virement|envoi' => [
                'reply' => "Pour un transfert : allez dans « Envoyer », choisissez la devise et le destinataire. "
                    . "Les fonds partent généralement sous quelques minutes. Si votre transfert est bloqué, dites-le-moi : un agent vérifiera.",
                'category' => 'transfer',
                'subject' => 'Question sur un transfert',
            ],
            'solde|sold|balance|salaire|portefeuille' => [
                'reply' => "Votre solde est visible dans « Portefeuille », avec la répartition disponible / en attente / en transit. "
                    . "Si vous voyez un écart, un agent vérifiera le ledger.",
                'category' => 'account',
                'subject' => 'Question sur le solde',
            ],
            'kyc|vérif|verif|identité|document|pièce' => [
                'reply' => "Pour la vérification KYC, rendez-vous dans « KYC ». Il faut une pièce d'identité + un selfie. "
                    . "Les dossiers sont traités sous 24-48h. Besoin d'aide ? Un agent peut suivre le dossier.",
                'category' => 'kyc',
                'subject' => 'Vérification KYC',
            ],
            'factur|frais|commission|tarif|coût' => [
                'reply' => "Les frais sont calculés au moment de l'envoi selon le provider. "
                    . "Vous voyez le total avant de confirmer. Pour le détail d'une opération précise, un agent peut vous aider.",
                'category' => 'billing',
                'subject' => 'Question sur les frais',
            ],
            'carte|card|plafond|limite|gel|bloqué|suspendu' => [
                'reply' => "Geler une carte ou un compte est une opération sensible. Je transmets votre demande à un agent humain qui s'en occupe immédiatement.",
                'category' => 'account',
                'subject' => mb_substr($body, 0, 120),
                'escalate' => true,
            ],
            'merci|ok|super|parfait|compris|d accord|ok merci' => [
                'reply' => "Avec plaisir ! N'hésitez pas si vous avez d'autres questions. Un agent peut aussi vous aider si besoin.",
                'category' => 'other',
                'subject' => 'Remerciement',
            ],
        ];

        foreach ($rules as $keys => $def) {
            foreach (explode('|', $keys) as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    return [
                        'reply'    => $def['reply'],
                        'escalate' => $def['escalate'] ?? false,
                        'category' => $def['category'],
                        'subject'  => $def['subject'],
                    ];
                }
            }
        }

        // 2. Aucun mot-clé → le bot ne sait pas répondre → escalade.
        return [
            'reply'    => null,
            'escalate' => true,
            'category' => 'other',
            'subject'  => mb_substr($body, 0, 120),
        ];
    }

    private static function categoryLabel(string $category): string
    {
        return [
            'account' => 'Mon compte',
            'transfer' => 'Transfert',
            'kyc' => 'Vérification KYC',
            'billing' => 'Facturation',
            'other' => 'Autre',
        ][$category] ?? 'Autre';
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private static function canAccess(\PDO $pdo, array $user, int $convId): bool
    {
        if (self::isAgent($user)) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM support_conversations WHERE id = ? AND user_id = ?');
        $stmt->execute([$convId, (int) $user['id']]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
