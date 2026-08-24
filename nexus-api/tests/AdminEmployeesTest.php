<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Controllers\AdminController;
use PHPUnit\Framework\TestCase;

/**
 * Tests du cycle de vie des employés internes (Super Admin) :
 *   - POST  /api/control/employees          (création)
 *   - POST  /api/control/employees/{id}/invite (lien d'activation)
 *   - PATCH /api/control/employees/{id}/status (activer / désactiver)
 *   - PUT   /api/control/employees/{id}     (changement de rôle/département)
 *
 * Vérifie : validation, traçabilité audit, synchronisation users/employees
 * et l'invitation (jeton de réinitialisation à usage unique).
 *
 * Chaque test est autonome : l'ordre d'exécution PHPUnit
 * (executionOrder="depends,defects") n'est jamais supposé.
 */
final class AdminEmployeesTest extends TestCase
{
    private const SA_EMAIL = 'sa.employees@nexus.test';

    private static ?int $superadminId = null;
    private static ?string $superadminToken = null;
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email = :e')->execute(['e' => self::SA_EMAIL]);

        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('SA Employees', :e, '', 'personal', 'superadmin', 'ACTIVE', 'advanced')"
        )->execute(['e' => self::SA_EMAIL]);
        self::$superadminId = (int) $pdo->lastInsertId();
        self::$superadminToken = \Nexus\Auth\Jwt::encode([
            'sub'   => (string) self::$superadminId,
            'email' => self::SA_EMAIL,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM employees WHERE user_id IN (SELECT id FROM users WHERE email LIKE :p)')
            ->execute(['p' => 'emp.%@nexus.test']);
        $pdo->prepare('DELETE FROM users WHERE email LIKE :p')->execute(['p' => 'emp.%@nexus.test']);
        if (self::$superadminId !== null) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => self::$superadminId]);
        }
        Response::enableTestMode(false);
    }

    private function call(string $method, array $params, ?array $body = null): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$superadminToken;
        $request = new Request($body);
        $request->setParams($params);

        try {
            AdminController::$method($request);
            $this->fail("La réponse attendue (ResponseSent) n'a pas été levée.");
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        }
    }

    /** Crée un employé propre à un test (email unique) et renvoie son id. */
    private function newEmployee(string $role = 'operations_manager', string $department = 'Operations'): int
    {
        $email = 'emp.' . (++self::$seq) . '@nexus.test';
        $res = $this->call('createEmployee', [], [
            'full_name'   => 'Ops Manager ' . self::$seq,
            'email'       => $email,
            'role'        => $role,
            'department'  => $department,
            'permissions' => ['operations'],
        ]);
        $this->assertSame(201, $res['status'], 'Création employé échouée.');
        return (int) $res['json']['data']['id'];
    }

    public function test_client_cannot_create_employee(): void
    {
        $pdo = Database::getConnection();
        $email = 'client.emp.' . (++self::$seq) . '@nexus.test';
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('Client', :e, '', 'personal', 'user', 'ACTIVE', 'none')"
        )->execute(['e' => $email]);
        $clientId = (int) $pdo->lastInsertId();
        $token = \Nexus\Auth\Jwt::encode(['sub' => (string) $clientId, 'email' => $email]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $request = new Request([
            'full_name' => 'Hacker',
            'email'     => 'emp.hack.' . (++self::$seq) . '@nexus.test',
            'role'      => 'operations_manager',
        ]);
        $request->setParams([]);
        try {
            AdminController::createEmployee($request);
            $this->fail('Un client ne doit pas créer d\'employé.');
        } catch (ResponseSent $e) {
            $this->assertSame(403, $e->statusCode());
        } catch (\Nexus\Core\HttpException $e) {
            $this->assertSame(403, $e->statusCode());
        } finally {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $clientId]);
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }
    }

    public function test_create_employee_refuses_promoting_existing_client(): void
    {
        $pdo = Database::getConnection();
        $email = 'client.promote.' . (++self::$seq) . '@nexus.test';
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('Client Promote', :e, '', 'personal', 'user', 'ACTIVE', 'none')"
        )->execute(['e' => $email]);
        $clientId = (int) $pdo->lastInsertId();

        $res = $this->call('createEmployee', [], [
            'full_name'  => 'Should Fail',
            'email'      => $email,
            'role'       => 'operations_manager',
            'department' => 'Operations',
        ]);
        $this->assertSame(409, $res['status'], 'Promotion silencieuse d\'un client refusée.');

        $role = $pdo->prepare('SELECT platform_role FROM users WHERE id = :id');
        $role->execute(['id' => $clientId]);
        $this->assertSame('user', $role->fetchColumn(), 'platform_role client inchangé.');

        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $clientId]);
    }

    public function test_create_employee_rejects_unknown_role(): void
    {
        $res = $this->call('createEmployee', [], [
            'full_name' => 'Ops Manager',
            'email'     => 'emp.reject.' . (++self::$seq) . '@nexus.test',
            'role'      => 'super_hero', // rôle hors ALLOWED_EMPLOYEE_ROLES
        ]);
        $this->assertSame(400, $res['status']);
        $this->assertFalse($res['json']['success']);
    }

    public function test_every_platform_employee_role_can_be_created(): void
    {
        foreach (\Nexus\Execution\PlatformRole::employeeRoles() as $role) {
            $employeeId = $this->newEmployee($role, 'Role catalogue');
            $stmt = Database::getConnection()->prepare(
                'SELECT e.role, u.platform_role
                 FROM employees e JOIN users u ON u.id = e.user_id
                 WHERE e.id = :id'
            );
            $stmt->execute(['id' => $employeeId]);
            $row = $stmt->fetch();
            $this->assertSame($role, $row['role'], $role);
            $this->assertSame($role, $row['platform_role'], $role);
        }
    }

    public function test_create_employee(): void
    {
        $employeeId = $this->newEmployee();
        $pdo = Database::getConnection();

        // Ligne employees + compte users synchronisés.
        $emp = $pdo->prepare('SELECT user_id, role, department, status FROM employees WHERE id = :id');
        $emp->execute(['id' => $employeeId]);
        $row = $emp->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('operations_manager', $row['role']);
        $this->assertSame('Operations', $row['department']);
        $this->assertSame('invited', $row['status']);

        $usr = $pdo->prepare('SELECT platform_role, status FROM users WHERE id = :id');
        $usr->execute(['id' => (int) $row['user_id']]);
        $u = $usr->fetch();
        $this->assertNotFalse($u);
        $this->assertSame('operations_manager', $u['platform_role']);
        $this->assertSame('PENDING', $u['status']);

        // Action tracée.
        $audit = $pdo->prepare(
            "SELECT action FROM audit_logs
             WHERE user_id = :admin AND action = 'EMPLOYEE_CREATED' AND entity_type = 'employees' AND entity_id = :e"
        );
        $audit->execute(['admin' => self::$superadminId, 'e' => $employeeId]);
        $this->assertNotFalse($audit->fetch(), "Aucune trace d'audit pour la création.");
    }

    public function test_invite_employee_creates_reset_token(): void
    {
        $employeeId = $this->newEmployee();
        $res = $this->call('inviteEmployee', ['id' => (string) $employeeId]);
        $this->assertSame(200, $res['status']);
        $this->assertSame(1800, $res['json']['data']['expires_in']);

        // En dev, le jeton brut est retourné pour être relayé manuellement.
        $token = $res['json']['data']['reset_token'];
        $this->assertIsString($token);
        $this->assertSame('/forgot-password?token=' . $token . '&portal=staff', $res['json']['data']['reset_url']);

        // Le jeton est stocké HACHÉ (jamais en clair) et expirera.
        $pdo = Database::getConnection();
        $emp = $pdo->prepare('SELECT user_id FROM employees WHERE id = :id');
        $emp->execute(['id' => $employeeId]);
        $uid = (int) $emp->fetchColumn();

        $row = $pdo->prepare(
            'SELECT token_hash, expires_at FROM password_reset_tokens WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
        );
        $row->execute(['uid' => $uid]);
        $r = $row->fetch();
        $this->assertNotFalse($r);
        $this->assertSame(hash('sha256', $token), $r['token_hash']);
        // Le timestamp est stocké en UTC : parsing explicite pour éviter le
        // décalage de fuseau horaire local du runner de tests.
        $this->assertGreaterThan(time(), strtotime((string) $r['expires_at'] . ' UTC'));

        // Une nouvelle invitation invalide la précédente (usage unique).
        $this->call('inviteEmployee', ['id' => (string) $employeeId]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = :uid');
        $count->execute(['uid' => $uid]);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function test_activate_employee_syncs_user_status(): void
    {
        $employeeId = $this->newEmployee();
        $res = $this->call('setEmployeeStatus', ['id' => (string) $employeeId], ['status' => 'active']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('active', $res['json']['data']['status']);

        $pdo = Database::getConnection();
        $emp = $pdo->prepare('SELECT user_id FROM employees WHERE id = :id');
        $emp->execute(['id' => $employeeId]);
        $uid = (int) $emp->fetchColumn();
        $st = $pdo->prepare('SELECT status FROM users WHERE id = :id');
        $st->execute(['id' => $uid]);
        $this->assertSame('ACTIVE', $st->fetchColumn());
    }

    public function test_update_employee_role_syncs_platform_role(): void
    {
        $employeeId = $this->newEmployee();
        $res = $this->call('updateEmployee', ['id' => (string) $employeeId], ['role' => 'treasury_manager']);
        $this->assertSame(200, $res['status']);

        $pdo = Database::getConnection();
        $emp = $pdo->prepare('SELECT user_id FROM employees WHERE id = :id');
        $emp->execute(['id' => $employeeId]);
        $uid = (int) $emp->fetchColumn();
        $st = $pdo->prepare('SELECT platform_role FROM users WHERE id = :id');
        $st->execute(['id' => $uid]);
        $this->assertSame('treasury_manager', $st->fetchColumn());
    }

    public function test_permissions_payload_never_grants_backend_authorization(): void
    {
        $employeeId = $this->newEmployee('customer_support', 'Support');
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT user_id, permissions FROM employees WHERE id = :id');
        $stmt->execute(['id' => $employeeId]);
        $employee = $stmt->fetch();
        $this->assertNull($employee['permissions'], 'Le pseudo-RBAC granulaire historique reste vide.');

        $role = $pdo->prepare('SELECT platform_role FROM users WHERE id = :id');
        $role->execute(['id' => $employee['user_id']]);
        $this->assertSame('customer_support', $role->fetchColumn(), 'users.platform_role est l’unique autorité.');
    }
}
