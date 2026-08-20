<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
use Nexus\Controllers\SupportController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PHPUnit\Framework\TestCase;

/**
 * Pièces jointes du widget support : le client peut envoyer fichiers / captures
 * via /uploads/support/ ; les URL externes sont refusées.
 */
final class SupportAttachmentsTest extends TestCase
{
    private static int $seq = 0;
    private static string $uploadDir;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
        self::$uploadDir = dirname(__DIR__) . '/public/uploads/support';
        if (!is_dir(self::$uploadDir)) {
            mkdir(self::$uploadDir, 0775, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email LIKE :p')->execute(['p' => 'supatt.%@nexus.test']);
        Response::enableTestMode(false);
    }

    private function newUser(string $role = 'user'): array
    {
        $email = 'supatt.' . (++self::$seq) . '@nexus.test';
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status)
             VALUES (:n, :e, '', 'personal', :r, 'ACTIVE')"
        )->execute(['n' => 'SupAtt ' . self::$seq, 'e' => $email, 'r' => $role]);
        $id = (int) $pdo->lastInsertId();
        return [
            'id'    => $id,
            'email' => $email,
            'token' => Jwt::encode(['sub' => (string) $id, 'email' => $email]),
        ];
    }

    private function insertTicket(int $userId): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO support_conversations (user_id, subject, category, status, priority)
             VALUES (:u, 'PJ test', 'kyc', 'open', 'normal')"
        )->execute(['u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    private function plantUpload(string $ext = 'png'): array
    {
        $basename = bin2hex(random_bytes(12)) . '.' . $ext;
        $path = self::$uploadDir . '/' . $basename;
        file_put_contents($path, $ext === 'png' ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') : 'hello');
        return [
            'path' => $path,
            'url'  => '/uploads/support/' . $basename,
            'name' => 'capture-ecran.' . $ext,
        ];
    }

    private function callSend(?string $token, int $convId, array $body): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $token !== null ? 'Bearer ' . $token : '';
        $request = new Request($body);
        $request->setParams(['id' => (string) $convId]);
        try {
            SupportController::sendMessage($request);
            $this->fail('ResponseSent attendu.');
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

    public function test_customer_can_attach_uploaded_screenshot(): void
    {
        $client = $this->newUser('user');
        $convId = $this->insertTicket($client['id']);
        $file = $this->plantUpload('png');

        $res = $this->callSend($client['token'], $convId, [
            'body'            => 'Voici ma capture KYC',
            'attachment_name' => $file['name'],
            'attachment_url'  => $file['url'],
        ]);
        $this->assertSame(200, $res['status'], json_encode($res['json']));
        $this->assertTrue($res['json']['success']);

        $pdo = Database::getConnection();
        $row = $pdo->query(
            "SELECT attachment_name, attachment_url, body FROM support_messages
              WHERE conversation_id = {$convId} AND customer_id = {$client['id']}
              ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertSame($file['name'], $row['attachment_name']);
        $this->assertSame($file['url'], $row['attachment_url']);
        $this->assertSame('Voici ma capture KYC', $row['body']);

        @unlink($file['path']);
    }

    public function test_customer_cannot_attach_external_url(): void
    {
        $client = $this->newUser('user');
        $convId = $this->insertTicket($client['id']);

        $res = $this->callSend($client['token'], $convId, [
            'body'            => 'phishing',
            'attachment_name' => 'evil.png',
            'attachment_url'  => 'https://evil.example/x.png',
        ]);
        $this->assertSame(400, $res['status']);
    }

    public function test_customer_cannot_attach_missing_local_file(): void
    {
        $client = $this->newUser('user');
        $convId = $this->insertTicket($client['id']);

        $res = $this->callSend($client['token'], $convId, [
            'body'            => 'fantôme',
            'attachment_name' => 'ghost.png',
            'attachment_url'  => '/uploads/support/' . str_repeat('ab', 12) . '.png',
        ]);
        $this->assertSame(400, $res['status']);
    }

    public function test_attachment_only_message_allowed(): void
    {
        $client = $this->newUser('user');
        $convId = $this->insertTicket($client['id']);
        $file = $this->plantUpload('pdf');

        $res = $this->callSend($client['token'], $convId, [
            'body'            => '',
            'attachment_name' => 'dossier.pdf',
            'attachment_url'  => $file['url'],
        ]);
        $this->assertSame(200, $res['status'], json_encode($res['json']));

        @unlink($file['path']);
    }
}
