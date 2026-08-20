<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Controllers\ControlCenterController;
use Nexus\Auth\Jwt;
use PHPUnit\Framework\TestCase;

/**
 * Override KYC/KYB exclusif Super Admin (secours Sumsub).
 *
 *   - compliance_officer → 403
 *   - superadmin peut approuver un dossier existant
 *   - superadmin peut créer un dossier manuel sans applicant Sumsub
 *   - motif obligatoire
 *   - projection users.kyc_level / kyb_status
 *   - audit kyc.approve
 */
final class SuperadminKycOverrideTest extends TestCase
{
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email LIKE :p')->execute(['p' => 'kycov.%@nexus.test']);
        Response::enableTestMode(false);
    }

    private function newUser(string $role, string $accountType = 'personal'): array
    {
        $email = 'kycov.' . (++self::$seq) . '@nexus.test';
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES (:n, :e, '', :t, :r, 'ACTIVE', 'none')"
        )->execute(['n' => 'KYC OV ' . self::$seq, 'e' => $email, 't' => $accountType, 'r' => $role]);
        $id = (int) $pdo->lastInsertId();
        return [
            'id'    => $id,
            'email' => $email,
            'token' => Jwt::encode(['sub' => (string) $id, 'email' => $email]),
        ];
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
            'a' => 'appl-ov-' . uniqid('', true),
            's' => $status,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function call(?string $token, array $body = []): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $token !== null ? 'Bearer ' . $token : '';
        $request = new Request($body);
        try {
            ControlCenterController::kycOverride($request);
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

    public function test_compliance_officer_cannot_use_manual_override(): void
    {
        $officer = $this->newUser('compliance_officer');
        $client  = $this->newUser('user');
        $kycId   = $this->insertKyc($client['id'], 'pending');

        $res = $this->call($officer['token'], [
            'decision'        => 'approve',
            'reason'          => 'Sumsub down — contrôle manuel des pièces.',
            'verification_id' => $kycId,
        ]);
        $this->assertSame(403, $res['status']);
        $this->assertFalse($res['json']['success']);
    }

    public function test_superadmin_approve_existing_dossier_updates_kyc_level(): void
    {
        $admin  = $this->newUser('superadmin');
        $client = $this->newUser('user');
        $kycId  = $this->insertKyc($client['id'], 'pending');

        $res = $this->call($admin['token'], [
            'decision'        => 'approve',
            'reason'          => 'Sumsub 503 — CNI et selfie contrôlés manuellement.',
            'verification_id' => $kycId,
        ]);
        $this->assertSame(200, $res['status'], json_encode($res['json']));
        $this->assertTrue($res['json']['success']);
        $this->assertSame('verified', $res['json']['data']['status'] ?? null);

        $pdo = Database::getConnection();
        $status = $pdo->query("SELECT status FROM kyc_verifications WHERE id = {$kycId}")->fetchColumn();
        $level  = $pdo->query("SELECT kyc_level FROM users WHERE id = {$client['id']}")->fetchColumn();
        $this->assertSame('verified', $status);
        $this->assertSame('standard', $level);

        $audit = $pdo->query(
            "SELECT action, metadata FROM audit_logs
              WHERE action = 'kyc.approve' AND entity_id = {$kycId}
              ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertNotFalse($audit);
        $meta = json_decode((string) $audit['metadata'], true);
        $this->assertSame('superadmin_manual_override', $meta['source'] ?? null);
    }

    public function test_superadmin_can_create_manual_dossier_without_sumsub(): void
    {
        $admin  = $this->newUser('superadmin');
        $client = $this->newUser('user');

        $res = $this->call($admin['token'], [
            'decision' => 'approve',
            'reason'   => 'Sumsub non configuré en urgence — validation manuelle direction.',
            'user_id'  => $client['id'],
            'subject_type' => 'individual',
        ]);
        $this->assertSame(200, $res['status'], json_encode($res['json']));
        $this->assertTrue($res['json']['data']['created'] ?? false);
        $this->assertSame('manual', $res['json']['data']['provider'] ?? null);

        $pdo = Database::getConnection();
        $level = $pdo->query("SELECT kyc_level FROM users WHERE id = {$client['id']}")->fetchColumn();
        $this->assertSame('standard', $level);
        $provider = $pdo->query(
            "SELECT provider FROM kyc_verifications WHERE id = " . (int) $res['json']['data']['verification_id']
        )->fetchColumn();
        $this->assertSame('manual', $provider);
    }

    public function test_superadmin_kyb_approve_does_not_touch_kyc_level(): void
    {
        $admin   = $this->newUser('superadmin');
        $company = $this->newUser('user', 'business');
        $kycId   = $this->insertKyc($company['id'], 'pending', 'company');

        $res = $this->call($admin['token'], [
            'decision'        => 'approve',
            'reason'          => 'Sumsub KYB timeout — Kbis et UBO vérifiés manuellement.',
            'verification_id' => $kycId,
        ]);
        $this->assertSame(200, $res['status'], json_encode($res['json']));

        $pdo = Database::getConnection();
        $row = $pdo->query(
            "SELECT kyb_status, kyc_level FROM users WHERE id = {$company['id']}"
        )->fetch();
        $this->assertSame('verified', $row['kyb_status']);
        $this->assertSame('none', $row['kyc_level']);
    }

    public function test_reason_is_required(): void
    {
        $admin  = $this->newUser('superadmin');
        $client = $this->newUser('user');
        $kycId  = $this->insertKyc($client['id'], 'pending');

        $res = $this->call($admin['token'], [
            'decision'        => 'approve',
            'reason'          => '',
            'verification_id' => $kycId,
        ]);
        $this->assertSame(400, $res['status']);
    }

    public function test_reject_requires_reason_and_demotes(): void
    {
        $admin  = $this->newUser('superadmin');
        $client = $this->newUser('user');
        $kycId  = $this->insertKyc($client['id'], 'pending');

        $res = $this->call($admin['token'], [
            'decision'        => 'reject',
            'reason'          => 'Documents frauduleux — décision manuelle direction.',
            'verification_id' => $kycId,
        ]);
        $this->assertSame(200, $res['status'], json_encode($res['json']));

        $pdo = Database::getConnection();
        $status = $pdo->query("SELECT status FROM kyc_verifications WHERE id = {$kycId}")->fetchColumn();
        $level  = $pdo->query("SELECT kyc_level FROM users WHERE id = {$client['id']}")->fetchColumn();
        $this->assertSame('rejected', $status);
        $this->assertSame('none', $level);

        $audit = $pdo->query(
            "SELECT action FROM audit_logs WHERE action = 'kyc.reject' AND entity_id = {$kycId} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertSame('kyc.reject', $audit);
    }
}
