<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\PlatformRole;

/**
 * Support chat — tickets & conversations (client ↔ agent / bot).
 *
 * RBAC :
 *   * Un CLIENT (authentifié) ne voit et n'écrit QUE dans ses propres
 *     conversations. Il ne peut jamais lire les conversations des autres.
 *   * Un AGENT (customer_support, support_operator, superadmin, ou tout rôle
 *     interne habilité par la capacité `support`) voit toutes les
 *     conversations et peut y répondre.
 *
 * Temps réel : les endpoints sont conçus pour un long-polling côté client
 * (lister les messages d'une conversation), ce qui fonctionne sur le serveur
 * PHP intégré et sur tous les navigateurs.
 *
 * Bot auto : répond immédiatement aux questions courantes (catégorisation par
 * mots-clés). Un agent humain peut prendre le relais et répondre.
 */
final class SupportController
{
    /** Capacité requise pour agir en tant qu'agent. */
    private const AGENT_CAPABILITY = 'support';

    /** Rôles considérés comme agents (défaut si la capacité est indisponible). */
    private const AGENT_ROLES = [
        'customer_support', 'support_operator', 'superadmin',
    ];

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

    /**
     * GET /api/support/conversations — liste les conversations de l'utilisateur.
     * Un agent voit toutes les conversations.
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
                $r['unread'] = (int) $pdo->prepare(
                    'SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND customer_id IS NOT NULL AND read_at IS NULL'
                )->execute([$r['id']]) ? (int) $pdo->prepare(
                    'SELECT COUNT(*) FROM support_messages WHERE conversation_id = ? AND customer_id IS NOT NULL AND read_at IS NULL'
                )->fetchColumn() : 0;
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
     * POST /api/support/conversations — ouvre un nouveau ticket.
     * Body : { subject, category? }
     * Le bot salue et pose une question d'orientation.
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
        if ($category === '' || !in_array($category, ['account', 'transfer', 'kyc', 'billing', 'other'], true)) {
            $category = 'other';
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO support_conversations (user_id, subject, category, status, priority)
                 VALUES (:uid, :subject, :category, \'open\', \'normal\')'
            );
            $ins->execute(['uid' => (int) $user['id'], 'subject' => $subject, 'category' => $category]);
            $convId = (int) $pdo->lastInsertId();

            // Message d'ouverture + réponse du bot.
            $msg = $pdo->prepare(
                'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                 VALUES (?, ?, NULL, 0, ?, NOW())'
            );
            $msg->execute([$convId, (int) $user['id'], $subject]);

            $bot = $pdo->prepare(
                'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                 VALUES (?, NULL, NULL, 1, ?, NOW())'
            );
            $bot->execute([$convId, self::welcomeMessage($user, $category)]);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::audit($pdo, (int) $user['id'], 'support.conversation_open', 'support_conversation', $convId, ['category' => $category]);

        Response::success([
            'conversation' => [
                'id' => $convId,
                'subject' => $subject,
                'category' => $category,
                'status' => 'open',
            ],
            'bot_reply' => self::welcomeMessage($user, $category),
        ], 201);
    }

    /**
     * GET /api/support/conversations/{id}/messages
     * Utilisé en long-polling (passe `after_id` pour ne recevoir que les nouveaux).
     */
    public static function messages(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();
        $convId = (int) ($request->param('id') ?? 0);

        if (!self::canAccess($pdo, $user, $convId)) {
            Response::forbidden('Accès refusé à cette conversation.');
        }

        $after = (int) ($request->query('after_id') ?? 0);

        if ($after > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, customer_id, agent_id, is_bot, body, read_at, created_at,
                        (SELECT full_name FROM users WHERE id = customer_id) AS customer_name,
                        (SELECT full_name FROM users WHERE id = agent_id) AS agent_name
                 FROM support_messages
                 WHERE conversation_id = :cid AND id > :after
                 ORDER BY id ASC'
            );
            $stmt->execute(['cid' => $convId, 'after' => $after]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, customer_id, agent_id, is_bot, body, read_at, created_at,
                        (SELECT full_name FROM users WHERE id = customer_id) AS customer_name,
                        (SELECT full_name FROM users WHERE id = agent_id) AS agent_name
                 FROM support_messages
                 WHERE conversation_id = :cid
                 ORDER BY id ASC'
            );
            $stmt->execute(['cid' => $convId]);
        }

        $messages = $stmt->fetchAll();

        // Marquer les messages entrants comme lus par le lecteur courant.
        if (self::isAgent($user)) {
            $upd = $pdo->prepare('UPDATE support_messages SET read_at = NOW() WHERE conversation_id = ? AND customer_id IS NOT NULL AND read_at IS NULL');
            $upd->execute([$convId]);
        } else {
            $upd = $pdo->prepare('UPDATE support_messages SET read_at = NOW() WHERE conversation_id = ? AND agent_id IS NOT NULL AND read_at IS NULL');
            $upd->execute([$convId]);
        }

        Response::success(['items' => $messages]);
    }

    /**
     * POST /api/support/conversations/{id}/messages — envoie un message.
     * Le bot auto répond s'il s'agit d'un message client.
     */
    public static function sendMessage(Request $request): void
    {
        $user = self::currentUser($request);
        $pdo = Database::getConnection();
        $convId = (int) ($request->param('id') ?? 0);

        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            Response::badRequest('Le message ne peut pas être vide.');
        }

        if (!self::canAccess($pdo, $user, $convId)) {
            Response::forbidden('Accès refusé à cette conversation.');
        }

        $isAgent = self::isAgent($user);
        $agentId = $isAgent ? (int) $user['id'] : null;
        $customerId = $isAgent ? null : (int) $user['id'];

        // Si le ticket était résolu/fermé et qu'un client écrit, on le ré-ouvre.
        if (!$isAgent) {
            $pdo->prepare("UPDATE support_conversations SET status = 'open' WHERE id = ? AND status IN ('resolved','closed')")
                ->execute([$convId]);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                 VALUES (?, ?, ?, 0, ?, NOW())'
            );
            $ins->execute([$convId, $customerId, $agentId, $body]);

            // Réponse du bot si le message vient du client.
            $botReply = null;
            if (!$isAgent) {
                $reply = self::botReply($body);
                if ($reply !== null) {
                    $bot = $pdo->prepare(
                        'INSERT INTO support_messages (conversation_id, customer_id, agent_id, is_bot, body, created_at)
                         VALUES (?, NULL, NULL, 1, ?, NOW())'
                    );
                    $bot->execute([$convId, $reply]);
                    $botReply = $reply;
                }
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::audit($pdo, (int) $user['id'], $isAgent ? 'support.agent_reply' : 'support.customer_message', 'support_conversation', $convId, []);

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

    private static function welcomeMessage(array $user, string $category): string
    {
        $name = $user['full_name'] ?? 'cher client';
        $first = explode(' ', (string) $name)[0];
        $cat = self::categoryLabel($category);
        return "Bonjour {$first} 👋 Bienvenue sur le support Nexus. Vous avez ouvert un ticket « {$cat} ». "
            . "Décrivez votre problème en quelques mots : je peux vous aider sur les transferts, votre compte, "
            . "la vérification KYC ou la facturation. Un agent pourra aussi prendre le relais si besoin.";
    }

    /** Retourne une réponse de bot selon les mots-clés, ou null (=> agent humain). */
    private static function botReply(string $body): ?string
    {
        $text = mb_strtolower($body);

        $rules = [
            'transfert' => "Pour un transfert : allez dans « Envoyer », choisissez la devise et le destinataire. "
                . "Les fonds partent généralement sous quelques minutes. Si votre transfert est bloqué, "
                . "un agent va vérifier — je peux l'escalader.",
            'solde|sold|balance|salaire' => "Votre solde est visible dans « Portefeuille », avec la répartition "
                . "disponible / en attente / en transit. Si vous voyez un écart, dites-le moi et un agent vérifiera le ledger.",
            'kyc|vérif|verif|identité|document' => "Pour la vérification KYC, rendez-vous dans « KYC ». Il faut une pièce "
                . "d'identité + un selfie. Les dossiers sont traités sous 24-48h. Besoin d'aide ? Un agent peut suivre le dossier.",
            'factur|frais|commission|tarif' => "Les frais sont calculés au moment de l'envoi selon le provider. "
                . "Vous voyez le total avant de confirmer. Pour le détail d'une opération précise, un agent peut vous aider.",
            'compte|bloqué|suspendu|accès' => "Si votre compte est bloqué ou suspendu, c'est une opération sensible. "
                . "Je vais transmettre à un agent humain qui vérifiera votre situation. Merci de patienter.",
            'carte|card|plafond|limite' => "Les limites et plafonds dépendent de votre niveau de vérification. "
                . "Pour le détail de vos plafonds, un agent peut vous accompagner.",
            'merci|ok|super|parfait|compris' => "Avec plaisir ! N'hésitez pas si vous avez d'autres questions. "
                . "Si tout est réglé, je peux clôturer ce ticket.",
        ];

        foreach ($rules as $keys => $reply) {
            foreach (explode('|', $keys) as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    return $reply;
                }
            }
        }

        // Aucun mot-clé : escalade vers un agent humain.
        return null;
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
}
