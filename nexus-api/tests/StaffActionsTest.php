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
 * Tests des ACTIONS métier du personnel (POST /api/control/staff/action) :
 *   - un compte client est refusé (403) ;
 *   - un employé n'agit que dans SA console (403 sinon) ;
 *   - operations : tx_approve / tx_cancel / tx_retry ;
 *   - compliance : kyc_approve (statut + niveau utilisateur) ;
 *   - support    : ticket_status ;
 *   - risk       : suspend / unsuspend / risk_level ;
 *   - business   : kyb_approve / kyb_reject.
 */
final class StaffActionsTest extends TestCase
{
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email LIKE :p')->execute(['p' => 'act.%@nexus.test']);
        $pdo->prepare('DELETE FROM users WHERE email LIKE :p')->execute(['p' => 'actsa@nexus.test']);
        Response::enableTestMode(false);
    }

    private function newUser(string $role, string $accountType = 'personal'): array
    {
        $email = 'act.' . (++self::$seq) . '@nexus.test';
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES (:n, :e, '', :t, :r, 'ACTIVE', 'none')"
        )->execute(['n' => 'Action ' . self::$seq, 'e' => $email, 't' => $accountType, 'r' => $role]);
        $id = (int) $pdo->lastInsertId();
        return ['id' => $id, 'email' => $email, 'token' => \Nexus\Auth\Jwt::encode(['sub' => (string) $id, 'email' => $email])];
    }

    private function call(?string $token, array $body = []): array
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

    private function insertTransaction(int $userId, string $status): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO transactions (user_id, type, direction, label, amount, currency, amount_ref, ref_currency, amount_xaf, status)
             VALUES (:u, 'send', 'out', 'Test action', 100.00, 'EUR', 100.00, 'EUR', 65595.70, :s)"
        )->execute(['u' => $userId, 's' => $status]);
        return (int) $pdo->lastInsertId();
    }

    private function insertKyc(int $userId, string $status, string $subjectType = 'individual'): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO kyc_verifications (user_id, provider, environment, subject_type, applicant_id, status)
             VALUES (:u, 'sumsub', 'sandbox', :t, :a, :s)"
        )->execute([
            'u' => $userId,
            't' => $subjectType,
            'a' => 'appl-' . uniqid('', true),
            's' => $status,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertTicket(int $userId): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO support_conversations (user_id, subject, category, status, priority)
             VALUES (:u, 'Ticket test', 'compte', 'open', 'normal')"
        )->execute(['u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function test_client_cannot_run_any_action(): void
    {
        $client = $this->newUser('user');
        $res = $this->call($client['token'], [
            'console' => 'operations',
            'action'  => 'tx_approve',
            'transaction_id' => '1',
        ]);
        $this->assertSame(403, $res['status']);
        $this->assertFalse($res['json']['success']);
    }

    public function test_employee_cannot_act_outside_own_console(): void
    {
        $emp = $this->newUser('operations_manager');
        $res = $this->call($emp['token'], [
            'console' => 'compliance',
            'action'  => 'kyc_approve',
            'verification_id' => '1',
        ]);
        $this->assertSame(403, $res['status']);
        $this->assertSame('FORBIDDEN_PLATFORM_ROLE', $res['json']['code'] ?? null);
    }

    public function test_operations_tx_approve_and_cancel(): void
    {
        $emp    = $this->newUser('operations_manager');
        $client = $this->newUser('user');
        $txId   = $this->insertTransaction($client['id'], 'pending');

        $res = $this->call($emp['token'], [
            'console' => 'operations',
            'action'  => 'tx_approve',
            'transaction_id' => (string) $txId,
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['success']);
        $status = Database::getConnection()->query("SELECT status FROM transactions WHERE id = $txId")->fetchColumn();
        $this->assertSame('processing', $status);

        // Une transaction en processing ne peut pas être annulée.
        $res = $this->call($emp['token'], [
            'console' => 'operations',
            'action'  => 'tx_cancel',
            'transaction_id' => (string) $txId,
        ]);
        $this->assertSame(400, $res['status']);
    }

    public function test_operations_tx_retry_failed(): void
    {
        $emp    = $this->newUser('operations_manager');
        $client = $this->newUser('user');
        $txId   = $this->insertTransaction($client['id'], 'failed');

        $res = $this->call($emp['token'], [
            'console' => 'operations',
            'action'  => 'tx_retry',
            'transaction_id' => (string) $txId,
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT status FROM transactions WHERE id = $txId")->fetchColumn();
        $this->assertSame('pending', $status);
    }

    public function test_compliance_kyc_approve_updates_user_level(): void
    {
        $emp    = $this->newUser('compliance_officer');
        $client = $this->newUser('user');
        $kycId  = $this->insertKyc($client['id'], 'pending');

        $res = $this->call($emp['token'], [
            'console' => 'compliance',
            'action'  => 'kyc_approve',
            'verification_id' => (string) $kycId,
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT status FROM kyc_verifications WHERE id = $kycId")->fetchColumn();
        $this->assertSame('verified', $status);
        $level = Database::getConnection()->query("SELECT kyc_level FROM users WHERE id = {$client['id']}")->fetchColumn();
        $this->assertSame('standard', $level);
    }

    public function test_compliance_kyc_reject_requires_reason(): void
    {
        $emp    = $this->newUser('compliance_officer');
        $client = $this->newUser('user');
        $kycId  = $this->insertKyc($client['id'], 'pending');

        $res = $this->call($emp['token'], [
            'console' => 'compliance',
            'action'  => 'kyc_reject',
            'verification_id' => (string) $kycId,
        ]);
        $this->assertSame(400, $res['status']);

        $res = $this->call($emp['token'], [
            'console' => 'compliance',
            'action'  => 'kyc_reject',
            'verification_id' => (string) $kycId,
            'reason'  => 'Document illisible.',
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT status FROM kyc_verifications WHERE id = $kycId")->fetchColumn();
        $this->assertSame('rejected', $status);
    }

    public function test_support_ticket_status_and_assign(): void
    {
        $emp    = $this->newUser('customer_support');
        $client = $this->newUser('user');
        $ticket = $this->insertTicket($client['id']);

        $res = $this->call($emp['token'], [
            'console' => 'support',
            'action'  => 'ticket_assign',
            'conversation_id' => (string) $ticket,
        ]);
        $this->assertSame(200, $res['status']);
        $assigned = Database::getConnection()->query("SELECT assigned_to FROM support_conversations WHERE id = $ticket")->fetchColumn();
        $this->assertSame((string) $emp['id'], (string) $assigned);

        $res = $this->call($emp['token'], [
            'console' => 'support',
            'action'  => 'ticket_status',
            'conversation_id' => (string) $ticket,
            'status'  => 'resolved',
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT status FROM support_conversations WHERE id = $ticket")->fetchColumn();
        $this->assertSame('resolved', $status);
    }

    public function test_risk_suspend_unsuspend_and_risk_level(): void
    {
        $emp    = $this->newUser('risk_fraud');
        $client = $this->newUser('user');

        $res = $this->call($emp['token'], [
            'console' => 'risk',
            'action'  => 'suspend',
            'user_id' => (string) $client['id'],
            'reason'  => 'Fraude présumée.',
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT status FROM users WHERE id = {$client['id']}")->fetchColumn();
        $this->assertSame('SUSPENDED', $status);

        $res = $this->call($emp['token'], [
            'console' => 'risk',
            'action'  => 'risk_level',
            'user_id' => (string) $client['id'],
            'level'   => 'high',
        ]);
        $this->assertSame(200, $res['status']);
        $level = Database::getConnection()->query("SELECT risk_level FROM users WHERE id = {$client['id']}")->fetchColumn();
        $this->assertSame('high', $level);

        $res = $this->call($emp['token'], [
            'console' => 'risk',
            'action'  => 'unsuspend',
            'user_id' => (string) $client['id'],
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT status FROM users WHERE id = {$client['id']}")->fetchColumn();
        $this->assertSame('ACTIVE', $status);
    }

    public function test_risk_cannot_suspend_staff(): void
    {
        $emp    = $this->newUser('risk_fraud');
        $staff  = $this->newUser('compliance_officer');

        $res = $this->call($emp['token'], [
            'console' => 'risk',
            'action'  => 'suspend',
            'user_id' => (string) $staff['id'],
            'reason'  => 'Test.',
        ]);
        $this->assertSame(403, $res['status']);
    }

    public function test_business_kyb_approve(): void
    {
        $emp     = $this->newUser('business_manager');
        $company = $this->newUser('user', 'business');

        $res = $this->call($emp['token'], [
            'console' => 'business',
            'action'  => 'kyb_approve',
            'user_id' => (string) $company['id'],
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT kyb_status FROM users WHERE id = {$company['id']}")->fetchColumn();
        $this->assertSame('verified', $status);
    }

    public function test_business_kyb_reject_requires_reason(): void
    {
        $emp     = $this->newUser('business_manager');
        $company = $this->newUser('user', 'business');

        $res = $this->call($emp['token'], [
            'console' => 'business',
            'action'  => 'kyb_reject',
            'user_id' => (string) $company['id'],
        ]);
        $this->assertSame(400, $res['status']);

        $res = $this->call($emp['token'], [
            'console' => 'business',
            'action'  => 'kyb_reject',
            'user_id' => (string) $company['id'],
            'reason'  => 'Documents non conformes.',
        ]);
        $this->assertSame(200, $res['status']);
        $status = Database::getConnection()->query("SELECT kyb_status FROM users WHERE id = {$company['id']}")->fetchColumn();
        $this->assertSame('rejected', $status);
    }
}
