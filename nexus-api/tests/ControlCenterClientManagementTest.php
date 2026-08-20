<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Controllers\ControlCenterController;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la gestion des comptes (normes « grande fintech ») :
 *   - POST /api/control/clients/{id}/status  (suspend / bannir / réactiver)
 *   - GET  /api/control/clients/linked       (détection de comptes multiples)
 *
 * Vérifie : validation, garde-fous (soi-même, personnel), journal d'audit,
 * et le regroupement par signaux d'identité partagés.
 */
final class ControlCenterClientManagementTest extends TestCase
{
    // E-mails fixes de test (purgés à chaque exécution).
    private const SA_EMAIL    = 'sa.control@nexus.test';
    private const EMAIL_A     = 'alice.doe@gmail.com';
    private const EMAIL_B     = 'alicedoe+work@gmail.com'; // alias Gmail normalisé = EMAIL_A
    private const EMAIL_C     = 'carol.doe@nexus.test';
    private const STAFF_EMAIL = 'alice.doe+staff@gmail.com'; // brut unique, normalisé = EMAIL_A

    private static ?int $superadminId = null;
    private static ?string $superadminToken = null;
    private static ?int $clientA = null;
    private static ?int $clientB = null;
    private static ?int $clientC = null;
    private static ?int $staffId = null;

    public static function setUpBeforeClass(): void
    {
        Response::enableTestMode(true);
        $pdo = Database::getConnection();

        // E-mails fixes : on purge d'abord les résidus d'exécutions précédentes
        // (e-mail unique en base, normalisation Gmail exacte attendue).
        foreach ([self::SA_EMAIL, self::EMAIL_A, self::EMAIL_B, self::EMAIL_C, self::STAFF_EMAIL] as $em) {
            $pdo->prepare('DELETE FROM users WHERE email = :e')->execute(['e' => $em]);
        }

        // Super admin acteur.
        $pdo->prepare(
            "INSERT INTO users (full_name, email, phone, password_hash, account_type, platform_role, status, kyc_level)
             VALUES ('SA Test', :e, '+33100000000', '', 'personal', 'superadmin', 'ACTIVE', 'advanced')"
        )->execute(['e' => self::SA_EMAIL]);
        self::$superadminId = (int) $pdo->lastInsertId();
        self::$superadminToken = \Nexus\Auth\Jwt::encode([
            'sub'   => (string) self::$superadminId,
            'email' => self::SA_EMAIL,
        ]);

        // Clients : Alice (A), B (même e-mail normalisé Gmail), C (même téléphone qu'Alice).
        $ins = static function (string $name, string $email, ?string $phone, ?string $birth = null) use ($pdo): int {
            $pdo->prepare(
                "INSERT INTO users (full_name, email, phone, password_hash, account_type, platform_role, status, kyc_level, birth_date)
                 VALUES (:n, :e, :p, '', 'personal', 'user', 'ACTIVE', 'basic', :b)"
            )->execute([
                'n' => $name,
                'e' => $email,
                'p' => $phone,
                'b' => $birth,
            ]);

            return (int) $pdo->lastInsertId();
        };

        self::$clientA = $ins('Alice Doe', self::EMAIL_A, '+33611223344', '1990-01-01');
        self::$clientB = $ins('Alice B', self::EMAIL_B, '+33611223345', '1990-01-01');
        self::$clientC = $ins('Carol Doe', self::EMAIL_C, '+33611223344', null);

        // Personnel : e-mail alias Gmail (brut unique, normalisé = celui d'Alice)
        // mais rôle plateforme → jamais signalé par la détection.
        $pdo->prepare(
            "INSERT INTO users (full_name, email, phone, password_hash, account_type, platform_role, status)
             VALUES ('Staff Alice', :e, '+33699999999', '', 'personal', 'security_technical', 'ACTIVE')"
        )->execute(['e' => self::STAFF_EMAIL]);
        self::$staffId = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        foreach ([self::$clientA, self::$clientB, self::$clientC, self::$staffId, self::$superadminId] as $id) {
            if ($id !== null) {
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            }
        }
        Response::enableTestMode(false);
    }

    private function call(string $method, array $params, ?array $body = null): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$superadminToken;
        $request = new Request($body);
        $request->setParams($params);

        try {
            ControlCenterController::$method($request);
            $this->fail("La réponse attendue (ResponseSent) n'a pas été levée.");
        } catch (ResponseSent $e) {
            return ['status' => $e->statusCode(), 'json' => $e->decoded()];
        }
    }

    public function test_suspend_requires_a_reason(): void
    {
        $res = $this->call('clientStatus', ['id' => (string) self::$clientA], ['status' => 'SUSPENDED']);
        $this->assertSame(400, $res['status']);
        $this->assertFalse($res['json']['success']);
    }

    public function test_suspend_and_reactivate_client(): void
    {
        $res = $this->call('clientStatus', ['id' => (string) self::$clientA], ['status' => 'SUSPENDED', 'reason' => 'Activité frauduleuse signalée.']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('SUSPENDED', $res['json']['data']['status']);

        // Le statut est bien persisté.
        $pdo = Database::getConnection();
        $status = $pdo->prepare('SELECT status FROM users WHERE id = :id');
        $status->execute(['id' => self::$clientA]);
        $this->assertSame('SUSPENDED', $status->fetchColumn());

        // L'action est tracée en audit.
        $audit = $pdo->prepare(
            "SELECT action, metadata FROM audit_logs
             WHERE user_id = :admin AND action = 'CLIENT_SUSPENDED' AND entity_type = 'users' AND entity_id = :c
             ORDER BY id DESC LIMIT 1"
        );
        $audit->execute(['admin' => self::$superadminId, 'c' => self::$clientA]);
        $row = $audit->fetch();
        $this->assertNotFalse($row, "Aucune trace d'audit pour la suspension.");
        $this->assertSame('ACTIVE', json_decode((string) $row['metadata'], true)['previous_status']);

        // Réactivation.
        $res = $this->call('clientStatus', ['id' => (string) self::$clientA], ['status' => 'ACTIVE']);
        $this->assertSame(200, $res['status']);
        $status->execute(['id' => self::$clientA]);
        $this->assertSame('ACTIVE', $status->fetchColumn());
    }

    public function test_ban_client(): void
    {
        $res = $this->call('clientStatus', ['id' => (string) self::$clientB], ['status' => 'CLOSED', 'reason' => 'Multi-comptes : contournement de la politique.']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('CLOSED', $res['json']['data']['status']);
    }

    public function test_cannot_modify_own_account_or_staff(): void
    {
        $res = $this->call('clientStatus', ['id' => (string) self::$superadminId], ['status' => 'SUSPENDED', 'reason' => 'Test']);
        $this->assertSame(403, $res['status']);

        $res = $this->call('clientStatus', ['id' => (string) self::$staffId], ['status' => 'SUSPENDED', 'reason' => 'Test']);
        $this->assertSame(403, $res['status']);
    }

    public function test_linked_clients_detects_shared_identities(): void
    {
        $res = $this->call('linkedClients', []);
        $this->assertSame(200, $res['status']);
        $groups = $res['json']['data']['groups'];

        // Groupes : e-mail (A+B), téléphone (A+C). Le personnel n'apparaît jamais.
        $emailGroup = null;
        $phoneGroup = null;
        foreach ($groups as $g) {
            $ids = array_map(static fn (array $m): int => (int) $m['id'], $g['members']);
            if ($g['signal'] === 'email' && in_array(self::$clientA, $ids, true)) {
                $emailGroup = $g;
            }
            if ($g['signal'] === 'phone' && in_array(self::$clientA, $ids, true)) {
                $phoneGroup = $g;
            }
        }

        $this->assertNotNull($emailGroup, 'Groupe e-mail A+B introuvable.');
        $this->assertContains(self::$clientB, array_map(static fn (array $m): int => (int) $m['id'], $emailGroup['members']));
        $this->assertNotContains(self::$staffId, array_map(static fn (array $m): int => (int) $m['id'], $emailGroup['members']));
        $this->assertSame('high', $emailGroup['risk']);

        $this->assertNotNull($phoneGroup, 'Groupe téléphone A+C introuvable.');
        $this->assertContains(self::$clientC, array_map(static fn (array $m): int => (int) $m['id'], $phoneGroup['members']));
        $this->assertNotContains(self::$staffId, array_map(static fn (array $m): int => (int) $m['id'], $phoneGroup['members']));

        // Les valeurs exposées sont masquées : jamais l'e-mail ni le numéro complets.
        $this->assertStringNotContainsString('alice.doe@gmail.com', (string) $emailGroup['detail']);
        $this->assertStringContainsString('…', (string) $emailGroup['detail']);
    }
}
