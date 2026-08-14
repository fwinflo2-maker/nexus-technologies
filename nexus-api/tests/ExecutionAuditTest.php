<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Execution\ExecutionAudit;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\ProductionAuthorizationPolicy as Policy;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * JOURNALISATION DES DÉCISIONS D'ENVIRONNEMENT (§17–§19).
 *
 * Ce qui est vérifié :
 *   - la décision est reconstructible depuis `audit_logs` ;
 *   - les refus de sécurité sont tracés ;
 *   - aucun secret n'atteint la table ;
 *   - le journal interne est plus détaillé que la réponse au client.
 */
final class ExecutionAuditTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $this->clearEnv();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        if ($this->userId > 0) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->userId = 0;
        }
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach (getenv() as $key => $_) {
            if (str_starts_with((string) $key, 'PROVIDER')) {
                putenv((string) $key);
            }
        }
        putenv('APP_ENV');
        putenv(Policy::ENV_ALLOW_ALL);
        putenv(Policy::ENV_ALLOW_LIST);
        unset($_SERVER['HTTP_X_NEXUS_ENVIRONMENT'], $_GET['environment']);
    }

    private function purge(): void
    {
        $this->pdo->exec("DELETE FROM audit_logs WHERE entity_type = 'execution_context'");
    }

    private function createUser(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Audit Test',
            'e' => 'audit_' . $suffix . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();

        return $this->userId;
    }

    /** @return list<array<string,mixed>> */
    private function logs(): array
    {
        $rows = $this->pdo->query(
            "SELECT user_id, action, entity_type, environment, metadata
               FROM audit_logs
              WHERE entity_type = 'execution_context'
              ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['metadata'] = json_decode((string) $row['metadata'], true);
        }

        return $rows;
    }

    // ══ §17 — la décision acceptée est reconstructible ═════════════════════

    public function test_granted_decision_is_fully_reconstructible(): void
    {
        $userId = $this->createUser();

        ExecutionContext::fromRequest(new Request([]), ['id' => $userId, 'account_type' => 'personal']);

        $logs = $this->logs();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        $this->assertSame(ExecutionAudit::ACTION_GRANTED, $log['action']);
        $this->assertSame($userId, (int) $log['user_id']);
        $this->assertSame('sandbox', $log['environment']);

        // qui / quel compte / quel environnement / source / request_id
        $meta = $log['metadata'];
        $this->assertSame($userId, $meta['actor_user_id']);
        $this->assertSame($userId, $meta['account_id']);
        $this->assertSame('sandbox', $meta['environment']);
        $this->assertArrayHasKey('environment_source', $meta);
        $this->assertNotSame('', (string) $meta['request_id']);
    }

    // ══ §18 — les refus sont auditables ════════════════════════════════════

    public function test_denied_authorization_is_audited(): void
    {
        $userId = $this->createUser();
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'production';

        try {
            ExecutionContext::fromRequest(new Request([]), ['id' => $userId, 'account_type' => 'personal']);
            $this->fail('La demande aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame('ENVIRONMENT_NOT_ALLOWED', $e->errorCode());
        }

        $logs = $this->logs();
        $this->assertCount(1, $logs, 'Le refus doit laisser exactement une trace.');
        $this->assertSame(ExecutionAudit::ACTION_DENIED, $logs[0]['action']);
        $this->assertSame('production', $logs[0]['environment']);
        $this->assertSame('ENVIRONMENT_NOT_ALLOWED', $logs[0]['metadata']['error_code']);
        $this->assertSame($userId, (int) $logs[0]['user_id']);
    }

    /**
     * Un environnement invalide n'est PAS une valeur de l'ENUM : la colonne
     * reste NULL plutôt que d'inventer une valeur, et la demande brute est
     * conservée dans metadata.
     */
    public function test_invalid_environment_is_audited_without_forging_a_value(): void
    {
        $userId = $this->createUser();
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'prod';

        try {
            ExecutionContext::fromRequest(new Request([]), ['id' => $userId, 'account_type' => 'personal']);
            $this->fail('Un alias invalide aurait dû être refusé.');
        } catch (HttpException $e) {
            $this->assertSame('ENVIRONMENT_INVALID', $e->errorCode());
        }

        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]['environment'], 'Aucune valeur ne doit être inventée.');
        $this->assertSame('ENVIRONMENT_INVALID', $logs[0]['metadata']['error_code']);
        $this->assertSame('prod', $logs[0]['metadata']['requested_raw']);
    }

    // ══ §17 — aucun secret dans le journal ═════════════════════════════════

    public function test_audit_never_stores_secrets(): void
    {
        $userId = $this->createUser();

        ExecutionAudit::recordDenied(
            'PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT',
            $userId,
            'production',
            [
                'provider'       => 'stripe',
                'secret_key'     => 'sk_live_TRES_SECRET',
                'api_token'      => 'tok_TRES_SECRET',
                'webhook_secret' => 'whsec_TRES_SECRET',
                'private_key'    => '-----BEGIN PRIVATE KEY-----',
            ]
        );

        $raw = (string) $this->pdo->query(
            "SELECT metadata FROM audit_logs WHERE entity_type = 'execution_context' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        foreach (['sk_live_TRES_SECRET', 'tok_TRES_SECRET', 'whsec_TRES_SECRET', 'BEGIN PRIVATE KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, 'Un secret a fuité dans audit_logs.');
        }

        // L'information non sensible reste exploitable.
        $this->assertStringContainsString('stripe', $raw);
    }

    // ══ §18 — le client en apprend moins que le journal ════════════════════

    /**
     * Le message renvoyé au client ne doit pas révéler qu'une credential de
     * production EXISTE : ce serait apprendre à un utilisateur non autorisé
     * ce qu'il n'a pas le droit de savoir.
     */
    public function test_client_message_does_not_reveal_credential_existence(): void
    {
        $userId = $this->createUser();

        // La plateforme DÉTIENT des clés de production.
        putenv('PROVIDER_STRIPE_PRODUCTION_SECRET_KEY=sk_live_present');
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'production';

        try {
            ExecutionContext::fromRequest(new Request([]), ['id' => $userId, 'account_type' => 'personal']);
            $this->fail('La demande aurait dû être refusée.');
        } catch (HttpException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString('sk_live', $message);
            $this->assertStringNotContainsString('stripe', strtolower($message));
            // Le message reste générique sur l'état de la configuration.
            $this->assertStringContainsString('non autorisée', $message);
        }

        // Le journal interne, lui, conserve de quoi enquêter.
        $logs = $this->logs();
        $this->assertSame('ENVIRONMENT_NOT_ALLOWED', $logs[0]['metadata']['error_code']);
    }

    // ══ Une panne du journal ne masque jamais un refus ═════════════════════

    public function test_audit_failure_never_swallows_the_decision(): void
    {
        // recordDenied ne doit jamais lever, même avec des données douteuses.
        ExecutionAudit::recordDenied('ENVIRONMENT_MISMATCH', null, 'valeur_impossible', ['x' => str_repeat('a', 100)]);

        $this->assertTrue(true, 'Aucune exception ne doit remonter du journal.');
    }
}
