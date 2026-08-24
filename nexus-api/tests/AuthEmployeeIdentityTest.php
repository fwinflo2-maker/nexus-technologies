<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\AuthMiddleware;
use Nexus\Auth\Jwt;
use Nexus\Auth\PlatformIdentity;
use Nexus\Controllers\AuthController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Execution\PlatformRole;
use PHPUnit\Framework\TestCase;

final class AuthEmployeeIdentityTest extends TestCase
{
    private const EMAIL_PREFIX = 'auth-employee-';
    private const PASSWORD = 'EmployeeTest!42';

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
    }

    protected function tearDown(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM employees WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')
            ->execute(['email' => self::EMAIL_PREFIX . '%@nexus.test']);
        $pdo->prepare('DELETE FROM login_attempts WHERE email LIKE :email')
            ->execute(['email' => self::EMAIL_PREFIX . '%@nexus.test']);
        $pdo->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => self::EMAIL_PREFIX . '%@nexus.test']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public static function tearDownAfterClass(): void
    {
        Response::enableTestMode(false);
    }

    public function test_login_and_middleware_resolve_every_employee_role_independently_of_account_type(): void
    {
        foreach (PlatformRole::employeeRoles() as $index => $role) {
            $accountType = $index % 2 === 0 ? 'personal' : 'business';
            [$userId, $email] = $this->createIdentity('user', $accountType, 'active', $role);

            $response = $this->login($email);
            self::assertSame(200, $response['status'], $role);
            self::assertSame($role, $response['json']['data']['user']['platform_role'], $role);
            self::assertSame($accountType, $response['json']['data']['user']['account_type'], $role);
            self::assertSame(
                $role === PlatformRole::SUPERADMIN ? 'superadmin' : 'employee',
                $response['json']['data']['user']['identity_kind'],
                $role
            );

            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $response['json']['data']['token'];
            $request = AuthMiddleware::handle(new Request([]));
            self::assertSame($userId, (int) $request->attribute('user')['id']);
            self::assertSame($role, $request->attribute('user')['platform_role'], $role);
        }
    }

    public function test_inactive_employee_is_rejected_even_when_user_is_active(): void
    {
        [$userId, $email] = $this->createIdentity(
            PlatformRole::OPERATIONS_MANAGER,
            'business',
            'disabled',
            PlatformRole::OPERATIONS_MANAGER
        );

        foreach ([
            static fn () => AuthMiddleware::handle(self::requestWithToken(Jwt::encode(['sub' => $userId]))),
            fn () => $this->login($email),
        ] as $action) {
            try {
                $action();
                self::fail('Un employé inactif doit être refusé.');
            } catch (HttpException $e) {
                self::assertSame(403, $e->statusCode());
                self::assertSame('ACCOUNT_RESTRICTED', $e->errorCode());
            }
        }
    }

    public function test_unknown_employee_role_is_rejected_instead_of_becoming_a_client(): void
    {
        [, $email] = $this->createIdentity('user', 'personal', 'active', 'unknown_internal_role');

        try {
            $this->login($email);
            self::fail('Un rôle employé inconnu doit être refusé.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertSame('INVALID_PLATFORM_IDENTITY', $e->errorCode());
        }
    }

    public function test_unknown_standalone_platform_role_is_rejected(): void
    {
        try {
            PlatformIdentity::resolve(Database::getConnection(), [
                'id' => 0,
                'status' => 'ACTIVE',
                'platform_role' => 'unknown_internal_role',
            ]);
            self::fail('Un platform_role inconnu doit être refusé.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertSame('INVALID_PLATFORM_IDENTITY', $e->errorCode());
        }
    }

    public function test_normal_personal_and_business_clients_remain_users(): void
    {
        foreach (['personal', 'business'] as $accountType) {
            [, $email] = $this->createIdentity('user', $accountType);
            $response = $this->login($email);
            self::assertSame(200, $response['status']);
            self::assertSame('user', $response['json']['data']['user']['platform_role']);
            self::assertSame('client', $response['json']['data']['user']['identity_kind']);
            self::assertSame($accountType, $response['json']['data']['user']['account_type']);
        }
    }

    /** @return array{int,string} */
    private function createIdentity(
        string $platformRole,
        string $accountType,
        ?string $employeeStatus = null,
        ?string $employeeRole = null
    ): array {
        $pdo = Database::getConnection();
        $email = self::EMAIL_PREFIX . bin2hex(random_bytes(6)) . '@nexus.test';
        $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, platform_role, auth_provider, status, kyc_level)
             VALUES (:name, :email, :password_hash, :account_type, :platform_role, :auth_provider, :status, :kyc_level)'
        )->execute([
            'name' => 'Employee Identity',
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'account_type' => $accountType,
            'platform_role' => $platformRole,
            'auth_provider' => 'local',
            'status' => 'ACTIVE',
            'kyc_level' => 'none',
        ]);
        $userId = (int) $pdo->lastInsertId();

        if ($employeeStatus !== null) {
            $pdo->prepare(
                'INSERT INTO employees (user_id, department, role, permissions, status)
                 VALUES (:user_id, :department, :role, NULL, :status)'
            )->execute([
                'user_id' => $userId,
                'department' => 'Test',
                'role' => $employeeRole,
                'status' => $employeeStatus,
            ]);
        }

        return [$userId, $email];
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function login(string $email): array
    {
        try {
            AuthController::login(new Request([
                'identifier' => $email,
                'password' => self::PASSWORD,
            ]));
            self::fail('AuthController::login doit émettre une réponse.');
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        }
    }

    private static function requestWithToken(string $token): Request
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        return new Request([]);
    }
}
