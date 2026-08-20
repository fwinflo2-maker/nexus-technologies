<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\AuthMiddleware;
use Nexus\Auth\Jwt;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareInvalidationTest extends TestCase
{
    private int $userId;

    protected function setUp(): void
    {
        $pdo = Database::getConnection();
        self::assertSame('nexus_test', $pdo->query('SELECT DATABASE()')->fetchColumn());
        $email = 'jwt-invalidation-' . bin2hex(random_bytes(6)) . '@nexus.test';
        $pdo->prepare(
            "INSERT INTO users
                (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('JWT Invalidation', :email, '', 'personal', 'user', 'ACTIVE', 'standard')"
        )->execute(['email' => $email]);
        $this->userId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        Database::getConnection()->prepare('DELETE FROM users WHERE id = :id')
            ->execute(['id' => $this->userId]);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function test_suspension_invalidates_an_already_issued_jwt(): void
    {
        $token = Jwt::encode(['sub' => $this->userId]);
        Database::getConnection()->prepare("UPDATE users SET status = 'SUSPENDED' WHERE id = :id")
            ->execute(['id' => $this->userId]);

        try {
            AuthMiddleware::handle($this->requestWithToken($token));
            self::fail('Un JWT antérieur à la suspension ne doit plus être accepté.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertSame('ACCOUNT_RESTRICTED', $e->errorCode());
        }
    }

    public function test_password_change_invalidates_older_jwt(): void
    {
        $token = Jwt::encode([
            'sub' => $this->userId,
            'iat' => time() - 120,
            'exp' => time() + 3600,
        ]);
        Database::getConnection()->prepare(
            'UPDATE users SET password_changed_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute(['id' => $this->userId]);

        try {
            AuthMiddleware::handle($this->requestWithToken($token));
            self::fail('Un JWT antérieur au changement de mot de passe ne doit plus être accepté.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->statusCode());
            self::assertSame('TOKEN_INVALID', $e->errorCode());
        }
    }

    private function requestWithToken(string $token): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/users/me';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        return new Request([]);
    }
}
