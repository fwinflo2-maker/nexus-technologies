<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\ControlCenterController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PHPUnit\Framework\TestCase;

final class ControlCenterObservabilityTest extends TestCase
{
    private static array $staff;
    private static array $client;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
        self::$staff = self::createUser('observability.staff@nexus.test', 'technical_admin');
        self::$client = self::createUser('observability.client@nexus.test', 'user');
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare("DELETE FROM provider_webhook_events WHERE event_id LIKE 'obs-%'")->execute();
        $pdo->prepare("DELETE FROM users WHERE email LIKE 'observability.%@nexus.test'")->execute();
        Response::enableTestMode(false);
    }

    private static function createUser(string $email, string $role): array
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $email]);
        $pdo->prepare(
            "INSERT INTO users
                (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('Observability', :email, '', 'personal', :role, 'ACTIVE', 'basic')"
        )->execute(['email' => $email, 'role' => $role]);
        $id = (int) $pdo->lastInsertId();
        return [
            'id' => $id,
            'token' => \Nexus\Auth\Jwt::encode(['sub' => (string) $id, 'email' => $email]),
        ];
    }

    private function call(string $method, string $token): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        unset($_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);
        try {
            ControlCenterController::$method(new Request());
            $this->fail('ResponseSent attendue.');
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'json' => [
                'success' => false,
                'code' => $e->errorCode(),
                'error' => $e->getMessage(),
            ]];
        }
    }

    public function test_webhook_journal_includes_provider_events_in_current_environment_only(): void
    {
        $pdo = Database::getConnection();
        $insert = $pdo->prepare(
            'INSERT INTO provider_webhook_events
                (provider, environment, event_id, event_type, status)
             VALUES (:provider, :environment, :event, :type, :status)'
        );
        $insert->execute([
            'provider' => 'stripe',
            'environment' => 'sandbox',
            'event' => 'obs-sandbox-' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'status' => 'processed',
        ]);
        $sandboxEvent = (string) $pdo->query(
            "SELECT event_id FROM provider_webhook_events WHERE event_id LIKE 'obs-sandbox-%' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $insert->execute([
            'provider' => 'stripe',
            'environment' => 'production',
            'event' => 'obs-production-' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'status' => 'processed',
        ]);
        $productionEvent = (string) $pdo->query(
            "SELECT event_id FROM provider_webhook_events WHERE event_id LIKE 'obs-production-%' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        $res = $this->call('webhooks', self::$staff['token']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('sandbox', $res['json']['data']['environment']);
        $events = array_column($res['json']['data']['items'], 'event_id');
        $this->assertContains($sandboxEvent, $events);
        $this->assertNotContains($productionEvent, $events);
        $providerRows = array_filter(
            $res['json']['data']['items'],
            static fn (array $row): bool => $row['event_id'] === $sandboxEvent
        );
        $this->assertSame('provider', array_values($providerRows)[0]['source_type']);
    }

    public function test_source_statuses_are_honest_and_rbac_protected(): void
    {
        $res = $this->call('sourceStatuses', self::$staff['token']);
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];
        $this->assertSame('sandbox', $data['environment']);
        $this->assertFalse($data['fx']['market_vendor_configured']);
        $this->assertTrue($data['fx']['fail_closed']);
        $this->assertContains($data['sanctions']['status'], ['CONFIGURED', 'UNAVAILABLE']);

        $forbidden = $this->call('sourceStatuses', self::$client['token']);
        $this->assertSame(403, $forbidden['status']);
    }
}
