<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\AdminController;
use Nexus\Controllers\AuthController;
use Nexus\Controllers\ControlCenterController;
use Nexus\Controllers\DashboardController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Execution\PlatformRole;
use PHPUnit\Framework\TestCase;

/**
 * Un employé interne, y compris operations_manager, ne doit jamais être
 * exposé ni routé comme un compte client personnel/business.
 */
final class EmployeeClientIsolationTest extends TestCase
{
    private const PREFIX = 'iso-emp-';
    private const PASSWORD = 'EmployeeIso!42';

    /** @var list<int> */
    private array $userIds = [];

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
    }

    protected function tearDown(): void
    {
        $pdo = Database::getConnection();
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM employees WHERE user_id IN ($ph)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM login_attempts WHERE email LIKE ?")->execute([self::PREFIX . '%@nexus.test']);
            $pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
        }
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public static function tearDownAfterClass(): void
    {
        Response::enableTestMode(false);
    }

    public function test_every_employee_role_is_rejected_on_client_portal_and_kept_off_client_registry(): void
    {
        $superadmin = $this->createUser('superadmin', 'personal', null);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \Nexus\Auth\Jwt::encode(['sub' => $superadmin]);

        foreach (PlatformRole::employeeRoles() as $index => $role) {
            if ($role === PlatformRole::SUPERADMIN) {
                continue;
            }
            $accountType = $index % 2 === 0 ? 'personal' : 'business';
            $userId = $this->createUser('user', $accountType, $role);
            $email = self::PREFIX . $userId . '@nexus.test';

            $clientLogin = $this->login($email, 'client');
            self::assertSame(403, $clientLogin['status'], $role);
            self::assertSame('WRONG_PORTAL', $clientLogin['json']['code'], $role);

            $staffLogin = $this->login($email, 'staff');
            self::assertSame(200, $staffLogin['status'], $role);
            self::assertSame($role, $staffLogin['json']['data']['user']['platform_role'], $role);
            self::assertSame('employee', $staffLogin['json']['data']['user']['identity_kind'], $role);
            self::assertSame($accountType, $staffLogin['json']['data']['user']['account_type'], $role);

            $adminLogin = $this->login($email, 'admin');
            self::assertSame(403, $adminLogin['status'], $role);

            $clients = $this->control('clients', []);
            self::assertSame(200, $clients['status']);
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $clients['json']['data']['items']);
            self::assertNotContains($userId, $ids, $role);

            $detail = $this->control('clientDetail', ['id' => (string) $userId]);
            self::assertSame(403, $detail['status'], $role);
        }
    }

    public function test_operations_employee_cannot_open_personal_dashboard_api(): void
    {
        $userId = $this->createUser('user', 'personal', PlatformRole::OPERATIONS_MANAGER);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \Nexus\Auth\Jwt::encode(['sub' => $userId]);
        try {
            DashboardController::summary(new Request([]));
            self::fail('Le dashboard client doit être interdit aux employés operations.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertSame('CLIENT_SURFACE_FORBIDDEN', $e->errorCode());
        }
    }

    public function test_client_is_rejected_on_staff_and_admin_portals(): void
    {
        $userId = $this->createUser('user', 'personal');
        $email = self::PREFIX . $userId . '@nexus.test';
        self::assertSame(403, $this->login($email, 'staff')['status']);
        self::assertSame(403, $this->login($email, 'admin')['status']);
        self::assertSame(200, $this->login($email, 'client')['status']);
        self::assertSame('client', $this->login($email, 'client')['json']['data']['user']['identity_kind']);
    }

    public function test_created_employee_with_personal_account_type_stays_off_client_list(): void
    {
        $sa = $this->createUser('superadmin', 'personal');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \Nexus\Auth\Jwt::encode(['sub' => $sa]);
        try {
            AdminController::createEmployee(new Request([
                'full_name' => 'Ops Isolated',
                'email' => self::PREFIX . 'created@nexus.test',
                'role' => PlatformRole::OPERATIONS_MANAGER,
                'department' => 'Operations',
            ]));
            self::fail('createEmployee doit répondre.');
        } catch (ResponseSent $e) {
            self::assertSame(201, $e->statusCode());
            $uid = (int) $e->decoded()['data']['user_id'];
            $this->userIds[] = $uid;
        }

        $clients = $this->control('clients', []);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $clients['json']['data']['items']);
        self::assertNotContains($this->userIds[array_key_last($this->userIds)], $ids);
    }

    private function createUser(string $platformRole, string $accountType, ?string $employeeRole = null): int
    {
        $pdo = Database::getConnection();
        $email = self::PREFIX . bin2hex(random_bytes(4)) . '@nexus.test';
        $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, platform_role, auth_provider, status, kyc_level)
             VALUES (:name, :email, :password_hash, :account_type, :platform_role, :auth_provider, :status, :kyc_level)'
        )->execute([
            'name' => 'Isolation ' . $platformRole,
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'account_type' => $accountType,
            'platform_role' => $platformRole,
            'auth_provider' => 'local',
            'status' => 'ACTIVE',
            'kyc_level' => 'none',
        ]);
        $userId = (int) $pdo->lastInsertId();
        $this->userIds[] = $userId;
        $pdo->prepare('UPDATE users SET email = :email WHERE id = :id')->execute([
            'email' => self::PREFIX . $userId . '@nexus.test',
            'id' => $userId,
        ]);

        if ($employeeRole !== null) {
            $pdo->prepare(
                'INSERT INTO employees (user_id, department, role, permissions, status)
                 VALUES (:user_id, :department, :role, NULL, :status)'
            )->execute([
                'user_id' => $userId,
                'department' => 'Test',
                'role' => $employeeRole,
                'status' => 'active',
            ]);
        }

        return $userId;
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function login(string $email, string $audience): array
    {
        try {
            AuthController::login(new Request([
                'identifier' => $email,
                'password' => self::PASSWORD,
                'audience' => $audience,
            ]));
            self::fail('login doit émettre une réponse.');
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        }
    }

    /** @param array<string,string> $params */
    private function control(string $method, array $params): array
    {
        $request = new Request([]);
        $request->setParams($params);
        try {
            ControlCenterController::$method($request);
            self::fail('Le contrôleur doit émettre une réponse.');
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        }
    }
}
