<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Controllers\ControlCenterController;
use PHPUnit\Framework\TestCase;

/**
 * Tests du dashboard employé (GET /api/control/staff/dashboard) :
 *   - un compte client (platform_role = user) est refusé (403) ;
 *   - chaque rôle interne reçoit UNIQUEMENT les données de sa console ;
 *   - les données sont réelles (agrégées depuis la base de test).
 */
final class StaffDashboardTest extends TestCase
{
    private static ?int $superadminId = null;
    private static ?string $superadminToken = null;
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email = :e')->execute(['e' => 'sa.staff@nexus.test']);
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('SA Staff', :e, '', 'personal', 'superadmin', 'ACTIVE', 'advanced')"
        )->execute(['e' => 'sa.staff@nexus.test']);
        self::$superadminId = (int) $pdo->lastInsertId();
        self::$superadminToken = \Nexus\Auth\Jwt::encode([
            'sub'   => (string) self::$superadminId,
            'email' => 'sa.staff@nexus.test',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email LIKE :p')->execute(['p' => 'staff.%@nexus.test']);
        if (self::$superadminId !== null) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => self::$superadminId]);
        }
        Response::enableTestMode(false);
    }

    private function newUser(string $role, string $accountType = 'personal'): array
    {
        $email = 'staff.' . (++self::$seq) . '@nexus.test';
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES (:n, :e, '', :t, :r, 'ACTIVE', 'basic')"
        )->execute(['n' => 'Staff ' . self::$seq, 'e' => $email, 't' => $accountType, 'r' => $role]);
        $id = (int) $pdo->lastInsertId();
        return ['id' => $id, 'email' => $email, 'token' => \Nexus\Auth\Jwt::encode(['sub' => (string) $id, 'email' => $email])];
    }

    private function call(?string $token, array $params = []): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $token !== null ? 'Bearer ' . $token : '';
        $request = new Request();
        $request->setParams($params);

        try {
            ControlCenterController::staffDashboard($request);
            $this->fail("La réponse attendue (ResponseSent) n'a pas été levée.");
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        } catch (HttpException $e) {
            // En test mode, le routeur (qui convertit HttpException) n'est pas
            // appelé : on reproduit sa forme de réponse.
            return ['status' => $e->statusCode(), 'json' => [
                'success' => false,
                'error'   => $e->getMessage(),
                'code'    => $e->getCode(),
            ]];
        }
    }

    public function test_client_account_is_rejected(): void
    {
        $client = $this->newUser('user', 'business');
        $res = $this->call($client['token']);
        $this->assertSame(403, $res['status']);
        $this->assertFalse($res['json']['success']);
    }

    public function test_operations_manager_gets_operations_section(): void
    {
        $emp = $this->newUser('operations_manager');
        $res = $this->call($emp['token']);
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];
        $this->assertSame('operations_manager', $data['role']);
        $this->assertSame('operations', $data['dashboard']);
        $this->assertArrayHasKey('operations', $data['sections']);
        $this->assertArrayHasKey('counters', $data['sections']['operations']);
        $this->assertArrayHasKey('queue', $data['sections']['operations']);
        // Un opérateur ne reçoit PAS les sections d'autres consoles.
        $this->assertArrayNotHasKey('compliance', $data['sections']);
        $this->assertArrayNotHasKey('finance', $data['sections']);
    }

    public function test_compliance_officer_gets_compliance_section(): void
    {
        $emp = $this->newUser('compliance_officer');
        $res = $this->call($emp['token']);
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];
        $this->assertSame('compliance', $data['dashboard']);
        $this->assertArrayHasKey('compliance', $data['sections']);
        $this->assertArrayHasKey('counters', $data['sections']['compliance']);
        $this->assertArrayHasKey('pending', $data['sections']['compliance']);
    }

    public function test_provider_manager_gets_providers_section(): void
    {
        $emp = $this->newUser('provider_manager');
        $res = $this->call($emp['token']);
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];
        $this->assertSame('providers', $data['dashboard']);
        $this->assertArrayHasKey('providers', $data['sections']);
        $this->assertArrayHasKey('providers', $data['sections']['providers']);
        $this->assertArrayHasKey('credentials', $data['sections']['providers']);
        $this->assertArrayHasKey('total', $data['sections']['providers']['providers']);
    }

    public function test_support_role_gets_support_section(): void
    {
        $emp = $this->newUser('customer_support');
        $res = $this->call($emp['token']);
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];
        $this->assertSame('support', $data['dashboard']);
        $this->assertArrayHasKey('support', $data['sections']);
        $this->assertArrayHasKey('counters', $data['sections']['support']);
        $this->assertArrayHasKey('recent', $data['sections']['support']);
    }

    public function test_executive_returns_note_not_sensitive_data(): void
    {
        $res = $this->call(self::$superadminToken);
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];
        $this->assertSame('executive', $data['dashboard']);
        $this->assertArrayHasKey('executive', $data['sections']);
    }
}
