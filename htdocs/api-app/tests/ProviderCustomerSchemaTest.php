<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Schéma provider_customers — existence, FK, contrainte d'unicité.
 */
final class ProviderCustomerSchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Database::resetConnection();
        $this->pdo = Database::getConnection();
    }

    public function testProviderCustomersTableExists(): void
    {
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'provider_customers'");
        self::assertNotFalse($stmt->fetch());
    }

    public function testForeignKeyToUsers(): void
    {
        $userId = $this->createUser();

        $this->pdo->prepare(
            'INSERT INTO provider_customers
                (user_id, provider_slug, provider_customer_id, environment, status)
             VALUES (:uid, :slug, :pcid, :env, :status)'
        )->execute([
            'uid'    => $userId,
            'slug'   => 'cashramp',
            'pcid'   => 'cr_test_fk',
            'env'    => 'sandbox',
            'status' => 'ACTIVE',
        ]);

        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM provider_customers WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testUniqueConstraintUserProviderEnvironment(): void
    {
        $userId = $this->createUser();

        $insert = $this->pdo->prepare(
            'INSERT INTO provider_customers
                (user_id, provider_slug, provider_customer_id, environment, status)
             VALUES (:uid, :slug, :pcid, :env, :status)'
        );
        $insert->execute([
            'uid'    => $userId,
            'slug'   => 'cashramp',
            'pcid'   => 'cr_unique_1',
            'env'    => 'sandbox',
            'status' => 'ACTIVE',
        ]);

        $this->expectException(PDOException::class);
        $insert->execute([
            'uid'    => $userId,
            'slug'   => 'cashramp',
            'pcid'   => 'cr_unique_2',
            'env'    => 'sandbox',
            'status' => 'ACTIVE',
        ]);
    }

    private function createUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type)
             VALUES (:n, :e, :p, :t)'
        );
        $stmt->execute([
            'n' => 'Schema Test',
            'e' => 'schema_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
