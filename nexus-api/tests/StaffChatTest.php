<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Controllers\StaffChatController;
use Nexus\Controllers\ControlCenterController;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la messagerie interne du personnel (StaffChatController) et de
 * l'escalade support (staffAction → ticket_escalate) :
 *   - un client est refusé (403) ;
 *   - l'annuaire ne renvoie que le personnel ;
 *   - créer un fil + envoyer des messages + liste + non-lus ;
 *   - un non-membre ne peut ni lire ni écrire (403) ;
 *   - l'escalade assigne le spécialiste et crée un fil lié au ticket.
 */
final class StaffChatTest extends TestCase
{
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email LIKE :p')->execute(['p' => 'ichat.%@nexus.test']);
        Response::enableTestMode(false);
    }

    private function newUser(string $role): array
    {
        $email = 'ichat.' . (++self::$seq) . '@nexus.test';
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status)
             VALUES (:n, :e, '', 'personal', :r, 'ACTIVE')"
        )->execute(['n' => 'Chat ' . self::$seq, 'e' => $email, 'r' => $role]);
        $id = (int) $pdo->lastInsertId();
        return ['id' => $id, 'email' => $email, 'token' => \Nexus\Auth\Jwt::encode(['sub' => (string) $id, 'email' => $email])];
    }

    private function insertTicket(int $userId): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO support_conversations (user_id, subject, category, status, priority)
             VALUES (:u, 'Ticket escalade test', 'transfer', 'open', 'normal')"
        )->execute(['u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** Appelle une méthode du StaffChatController (route param + body). */
    private function callChat(string $method, ?string $token, array $body = [], ?int $chatId = null, array $query = []): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $token !== null ? 'Bearer ' . $token : '';
        $request = new Request($body);
        if ($chatId !== null) {
            $request->setParams(['id' => (string) $chatId]);
        }
        foreach ($query as $k => $v) {
            $_GET[$k] = $v;
        }

        try {
            switch ($method) {
                case 'directory': StaffChatController::directory($request); break;
                case 'chats': StaffChatController::chats($request); break;
                case 'create': StaffChatController::createChat($request); break;
                case 'messages': StaffChatController::messages($request); break;
                case 'send': StaffChatController::sendMessage($request); break;
            }
            $this->fail("La réponse attendue (ResponseSent) n'a pas été levée.");
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'json' => [
                'success' => false,
                'error'   => $e->getMessage(),
                'code'    => $e->errorCode(),
            ]];
        } finally {
            foreach (array_keys($query) as $k) {
                unset($_GET[$k]);
            }
        }
    }

    /** Appelle staffAction (console support). */
    private function callAction(?string $token, array $body = []): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $token !== null ? 'Bearer ' . $token : '';
        $request = new Request($body);
        try {
            ControlCenterController::staffAction($request);
            $this->fail("La réponse attendue (ResponseSent) n'a pas été levée.");
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'json' => [
                'success' => false,
                'error'   => $e->getMessage(),
                'code'    => $e->errorCode(),
            ]];
        }
    }

    public function test_client_is_rejected(): void
    {
        $client = $this->newUser('user');
        $res = $this->callChat('chats', $client['token']);
        $this->assertSame(403, $res['status']);
        $res = $this->callChat('directory', $client['token']);
        $this->assertSame(403, $res['status']);
    }

    public function test_directory_returns_only_staff(): void
    {
        $emp = $this->newUser('operations_manager');
        $res = $this->callChat('directory', $emp['token']);
        $this->assertSame(200, $res['status']);
        $items = $res['json']['data']['items'];
        $this->assertNotEmpty($items);
        foreach ($items as $it) {
            $this->assertNotSame('user', $it['platform_role']);
            $this->assertNotEmpty($it['full_name']);
        }
    }

    public function test_create_send_and_list_chat(): void
    {
        $a = $this->newUser('operations_manager');
        $b = $this->newUser('technical_admin');

        $res = $this->callChat('create', $a['token'], [
            'title' => 'Coordination incident',
            'member_ids' => [$b['id']],
        ]);
        $this->assertSame(201, $res['status']);
        $chatId = (int) $res['json']['data']['id'];
        $this->assertGreaterThan(0, $chatId);

        $res = $this->callChat('send', $a['token'], ['body' => 'Bonjour, incident en cours.'], $chatId);
        $this->assertSame(201, $res['status']);

        // B a un non-lu ; A (émetteur) n'en a pas.
        $resA = $this->callChat('chats', $a['token']);
        $chatA = null;
        foreach ($resA['json']['data']['items'] as $it) {
            if ((int) $it['id'] === $chatId) { $chatA = $it; break; }
        }
        $this->assertNotNull($chatA, 'Le fil doit apparaître pour le créateur.');
        $this->assertSame(0, $chatA['unread']);

        $resB = $this->callChat('chats', $b['token']);
        $chatB = null;
        foreach ($resB['json']['data']['items'] as $it) {
            if ((int) $it['id'] === $chatId) { $chatB = $it; break; }
        }
        $this->assertNotNull($chatB, 'Le fil doit apparaître pour le second membre.');
        $this->assertSame(1, $chatB['unread']);
        $this->assertSame('Bonjour, incident en cours.', $chatB['last_body']);

        // Lecture par B → marque lu + reçoit le message.
        $msgs = $this->callChat('messages', $b['token'], [], $chatId);
        $this->assertSame(200, $msgs['status']);
        $this->assertCount(1, $msgs['json']['data']['items']);
        $resB2 = $this->callChat('chats', $b['token']);
        foreach ($resB2['json']['data']['items'] as $it) {
            if ((int) $it['id'] === $chatId) { $this->assertSame(0, $it['unread']); }
        }
    }

    public function test_non_member_cannot_read_or_send(): void
    {
        $a = $this->newUser('operations_manager');
        $b = $this->newUser('technical_admin');
        $c = $this->newUser('risk_analyst');

        $res = $this->callChat('create', $a['token'], ['title' => 'Fil privé', 'member_ids' => [$b['id']]]);
        $chatId = (int) $res['json']['data']['id'];

        $res = $this->callChat('messages', $c['token'], [], $chatId);
        $this->assertSame(403, $res['status']);
        $res = $this->callChat('send', $c['token'], ['body' => 'intrusion'], $chatId);
        $this->assertSame(403, $res['status']);
    }

    public function test_escalation_assigns_specialist_and_creates_chat(): void
    {
        $agent     = $this->newUser('customer_support');
        $specialist = $this->newUser('technical_admin');
        $client    = $this->newUser('user');
        $ticket    = $this->insertTicket($client['id']);

        $res = $this->callAction($agent['token'], [
            'console' => 'support',
            'action'  => 'ticket_escalate',
            'conversation_id' => (string) $ticket,
            'specialist_id'   => (string) $specialist['id'],
            'difficulty'      => 'complexe',
            'reason'          => 'Problème technique avancé sur le provider.',
        ]);
        $this->assertSame(200, $res['status']);
        $chatId = (int) $res['json']['data']['chat_id'];
        $this->assertGreaterThan(0, $chatId);

        // Le ticket est assigné au spécialiste et sa priorité montée à high.
        $row = Database::getConnection()->query("SELECT assigned_to, priority FROM support_conversations WHERE id = $ticket")->fetch();
        $this->assertSame((string) $specialist['id'], (string) $row['assigned_to']);
        $this->assertSame('high', $row['priority']);

        // Le spécialiste voit le fil avec un message système non lu.
        $resSp = $this->callChat('chats', $specialist['token']);
        $found = null;
        foreach ($resSp['json']['data']['items'] as $it) {
            if ((int) $it['id'] === $chatId) { $found = $it; break; }
        }
        $this->assertNotNull($found, 'Le spécialiste doit voir le fil d\'escalade.');
        $this->assertSame((int) $ticket, (int) $found['related_conversation_id']);
        $this->assertGreaterThan(0, $found['unread']);
        $this->assertStringContainsString('Escalade', (string) $found['title']);

        $msgs = $this->callChat('messages', $specialist['token'], [], $chatId);
        $this->assertSame(200, $msgs['status']);
        $this->assertCount(1, $msgs['json']['data']['items']);
        $this->assertSame(1, (int) $msgs['json']['data']['items'][0]['is_system']);
        $this->assertStringContainsString('complexe', (string) $msgs['json']['data']['items'][0]['body']);
    }

    public function test_escalation_requires_specialist_and_reason(): void
    {
        $agent  = $this->newUser('customer_support');
        $client = $this->newUser('user');
        $ticket = $this->insertTicket($client['id']);

        $res = $this->callAction($agent['token'], [
            'console' => 'support',
            'action'  => 'ticket_escalate',
            'conversation_id' => (string) $ticket,
            'specialist_id'   => '999999',
            'difficulty'      => 'complexe',
        ]);
        $this->assertSame(400, $res['status']);

        $res = $this->callAction($agent['token'], [
            'console' => 'support',
            'action'  => 'ticket_escalate',
            'conversation_id' => (string) $ticket,
            'specialist_id'   => (string) $agent['id'],
            'difficulty'      => 'complexe',
            'reason'          => 'Test.',
        ]);
        $this->assertSame(400, $res['status'], 'Escalade vers soi-même refusée.');
    }
}
