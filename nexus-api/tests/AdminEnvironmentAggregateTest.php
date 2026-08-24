<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\AdminController;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PHPUnit\Framework\TestCase;

final class AdminEnvironmentAggregateTest extends TestCase
{
    private static int $adminId;
    private static string $token;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
        $pdo = Database::getConnection();
        $pdo->prepare("DELETE FROM users WHERE email LIKE 'admin.aggregate.%@nexus.test'")->execute();
        $pdo->prepare(
            "INSERT INTO users
                (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('Aggregate Admin', 'admin.aggregate.staff@nexus.test', '', 'personal', 'superadmin', 'ACTIVE', 'advanced')"
        )->execute();
        self::$adminId = (int) $pdo->lastInsertId();
        self::$token = \Nexus\Auth\Jwt::encode([
            'sub' => (string) self::$adminId,
            'email' => 'admin.aggregate.staff@nexus.test',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Database::getConnection()
            ->prepare("DELETE FROM users WHERE email LIKE 'admin.aggregate.%@nexus.test'")
            ->execute();
        Response::enableTestMode(false);
    }

    private function overview(): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$token;
        unset($_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);
        try {
            AdminController::overview(new Request());
            $this->fail('ResponseSent attendue.');
        } catch (ResponseSent $e) {
            return $e->decoded()['data'];
        }
    }

    public function test_overview_uses_available_balance_and_scopes_transactions(): void
    {
        $before = $this->overview();
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO users
                (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('Aggregate Client', 'admin.aggregate.client@nexus.test', '', 'personal', 'user', 'ACTIVE', 'basic')"
        )->execute();
        $clientId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO wallets
                (user_id, currency, balance, available_balance, pending_balance, hold_balance)
             VALUES (:user, 'EUR', 999.00, 7.00, 992.00, 0.00)"
        )->execute(['user' => $clientId]);
        $insert = $pdo->prepare(
            "INSERT INTO transactions
                (user_id, type, direction, label, amount, currency, amount_ref, ref_currency, amount_xaf, status, environment)
             VALUES (:user, 'send', 'out', :label, 1, 'EUR', 1, 'EUR', 655.96, 'completed', :environment)"
        );
        $insert->execute(['user' => $clientId, 'label' => 'admin-aggregate-sandbox', 'environment' => 'sandbox']);
        $insert->execute(['user' => $clientId, 'label' => 'admin-aggregate-production', 'environment' => 'production']);

        $after = $this->overview();
        $this->assertSame('sandbox', $after['environment']);
        $this->assertSame('available_balance', $after['assets_basis']);
        $this->assertEqualsWithDelta(
            (float) $before['assets']['EUR'] + 7.0,
            (float) $after['assets']['EUR'],
            0.001
        );
        $this->assertSame(
            (int) $before['transactions']['total'] + 1,
            (int) $after['transactions']['total']
        );
    }
}
