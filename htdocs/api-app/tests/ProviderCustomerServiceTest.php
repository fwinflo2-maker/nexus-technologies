<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Services\ProviderCustomerService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ProviderCustomerServiceTest extends TestCase
{
    private PDO $pdo;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        Database::resetConnection();
        $this->pdo = Database::getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->userIds as $userId) {
            $this->pdo->prepare('DELETE FROM provider_customers WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $this->userIds = [];
    }

    public function testGetCustomerReturnsExistingMapping(): void
    {
        $userId = $this->createUser();
        $this->seedCustomer($userId, 'cashramp', 'sandbox', 'cr_get_1');

        $row = ProviderCustomerService::getCustomer($userId, 'cashramp', 'sandbox');

        self::assertNotNull($row);
        self::assertSame($userId, $row['user_id']);
        self::assertSame('cashramp', $row['provider_slug']);
        self::assertSame('cr_get_1', $row['provider_customer_id']);
        self::assertSame('sandbox', $row['environment']);
    }

    public function testCreateCustomerPersistsMapping(): void
    {
        $userId = $this->createUser();

        $row = ProviderCustomerService::createCustomer($userId, 'cashramp', 'sandbox', [
            'provider_customer_id' => 'cr_create_1',
            'status'               => 'ACTIVE',
            'metadata'             => ['source' => 'test'],
        ]);

        self::assertSame('cr_create_1', $row['provider_customer_id']);
        self::assertSame('ACTIVE', $row['status']);
        self::assertSame(['source' => 'test'], $row['metadata']);
    }

    public function testGetOrCreateCustomerCreatesWhenMissing(): void
    {
        $userId = $this->createUser();
        $calls  = 0;

        $row = ProviderCustomerService::getOrCreateCustomer(
            $userId,
            'cashramp',
            'sandbox',
            function () use (&$calls): array {
                $calls++;
                return [
                    'provider_customer_id' => 'cr_or_create_1',
                    'status'               => 'ACTIVE',
                ];
            }
        );

        self::assertSame(1, $calls);
        self::assertSame('cr_or_create_1', $row['provider_customer_id']);
    }

    public function testGetOrCreateCustomerReturnsExistingMapping(): void
    {
        $userId = $this->createUser();
        $this->seedCustomer($userId, 'cashramp', 'sandbox', 'cr_existing_1');
        $calls = 0;

        $row = ProviderCustomerService::getOrCreateCustomer(
            $userId,
            'cashramp',
            'sandbox',
            function () use (&$calls): array {
                $calls++;
                return ['provider_customer_id' => 'should_not_be_used'];
            }
        );

        self::assertSame(0, $calls);
        self::assertSame('cr_existing_1', $row['provider_customer_id']);
    }

    public function testGetOrCreateCustomerIsIdempotentAcrossRepeatedCalls(): void
    {
        $userId = $this->createUser();
        $calls  = 0;
        $provisioner = function () use (&$calls): array {
            $calls++;
            return [
                'provider_customer_id' => 'cr_idem_' . $calls,
                'status'               => 'ACTIVE',
            ];
        };

        $first  = ProviderCustomerService::getOrCreateCustomer($userId, 'cashramp', 'sandbox', $provisioner);
        $second = ProviderCustomerService::getOrCreateCustomer($userId, 'cashramp', 'sandbox', $provisioner);

        self::assertSame($first['id'], $second['id']);
        self::assertSame($first['provider_customer_id'], $second['provider_customer_id']);
        self::assertSame(1, $calls);
    }

    public function testEnvironmentIsolationSandboxAndProduction(): void
    {
        $userId = $this->createUser();

        $sandbox = ProviderCustomerService::createCustomer($userId, 'cashramp', 'sandbox', [
            'provider_customer_id' => 'cr_env_sandbox',
            'status'               => 'ACTIVE',
        ]);
        $production = ProviderCustomerService::createCustomer($userId, 'cashramp', 'production', [
            'provider_customer_id' => 'cr_env_production',
            'status'               => 'ACTIVE',
        ]);

        self::assertNotSame($sandbox['id'], $production['id']);
        self::assertSame('sandbox', $sandbox['environment']);
        self::assertSame('production', $production['environment']);
        self::assertSame('cr_env_sandbox', $sandbox['provider_customer_id']);
        self::assertSame('cr_env_production', $production['provider_customer_id']);
    }

    public function testSyncCustomerUpdatesMapping(): void
    {
        $userId = $this->createUser();
        ProviderCustomerService::createCustomer($userId, 'pawapay', 'sandbox', [
            'provider_customer_id' => 'pp_old',
            'status'               => 'PENDING',
            'metadata'             => ['tier' => 'basic'],
        ]);

        $updated = ProviderCustomerService::syncCustomer($userId, 'pawapay', 'sandbox', [
            'provider_customer_id' => 'pp_new',
            'status'               => 'ACTIVE',
            'metadata'             => ['tier' => 'verified'],
        ]);

        self::assertSame('pp_new', $updated['provider_customer_id']);
        self::assertSame('ACTIVE', $updated['status']);
        self::assertSame(['tier' => 'verified'], $updated['metadata']);
    }

    public function testDuplicateCreateReturnsExistingOnRace(): void
    {
        $userId = $this->createUser();

        ProviderCustomerService::createCustomer($userId, 'cashramp', 'sandbox', [
            'provider_customer_id' => 'cr_race_1',
            'status'               => 'ACTIVE',
        ]);

        try {
            ProviderCustomerService::createCustomer($userId, 'cashramp', 'sandbox', [
                'provider_customer_id' => 'cr_race_2',
                'status'               => 'ACTIVE',
            ]);
            self::fail('Expected duplicate create to fail.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->statusCode());
        }
    }

    public function testGetOrCreateCustomerHandlesDuplicateInsert(): void
    {
        $userId = $this->createUser();
        $this->seedCustomer($userId, 'cashramp', 'sandbox', 'cr_dup_handler');
        $calls = 0;

        $row = ProviderCustomerService::getOrCreateCustomer(
            $userId,
            'cashramp',
            'sandbox',
            function () use (&$calls): array {
                $calls++;
                return ['provider_customer_id' => 'cr_should_not_insert'];
            }
        );

        self::assertSame(0, $calls);
        self::assertSame('cr_dup_handler', $row['provider_customer_id']);
    }

    public function testMetadataRejectsSecrets(): void
    {
        $userId = $this->createUser();

        $this->expectException(HttpException::class);
        ProviderCustomerService::createCustomer($userId, 'cashramp', 'sandbox', [
            'provider_customer_id' => 'cr_meta_1',
            'metadata'             => ['api_key' => 'secret-value'],
        ]);
    }

    private function createUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type)
             VALUES (:n, :e, :p, :t)'
        );
        $stmt->execute([
            'n' => 'PCS Test',
            'e' => 'pcs_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }

    private function seedCustomer(int $userId, string $slug, string $environment, string $providerCustomerId): void
    {
        $this->pdo->prepare(
            'INSERT INTO provider_customers
                (user_id, provider_slug, provider_customer_id, environment, status)
             VALUES (:uid, :slug, :pcid, :env, :status)'
        )->execute([
            'uid'    => $userId,
            'slug'   => $slug,
            'pcid'   => $providerCustomerId,
            'env'    => $environment,
            'status' => 'ACTIVE',
        ]);
    }
}
