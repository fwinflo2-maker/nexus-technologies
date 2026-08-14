<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PHASE 3 — AUDIT DES PRODUCTEURS D'ÉVÉNEMENTS.
 *
 * Deux exigences opposées, tenues ensemble :
 *
 *   - un événement qui A une notion d'environnement doit la porter dans la
 *     COLONNE (sinon il est infiltrable, mais pas filtrable) ;
 *   - un événement qui n'en a AUCUNE ne doit pas s'en voir attribuer une
 *     artificiellement (§3). Inventer « sandbox » pour un `auth.login`
 *     donnerait une information fausse à quiconque filtrerait le journal.
 */
final class AuditProducersTest extends TestCase
{
    private PDO $pdo;
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $suffix = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :t, :c)'
        );
        $stmt->execute([
            'n' => 'Audit Producers',
            'e' => 'prod_' . $suffix . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        if ($this->userId > 0) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->userId = 0;
        }
    }

    private function purge(): void
    {
        if ($this->userId > 0) {
            $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$this->userId]);
        }
    }

    /** @return array<string,mixed>|null */
    private function lastLog(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT action, entity_type, environment, metadata FROM audit_logs
              WHERE user_id = :u ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['u' => $this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function callCredentialAudit(string $action, array $metadata): void
    {
        $m = new ReflectionMethod(\Nexus\Controllers\ProviderCredentialController::class, 'audit');
        $m->setAccessible(true);
        $m->invoke(null, $this->pdo, $this->userId, $action, 'stripe', $metadata, new \Nexus\Core\Request([]));
    }

    // ══ 1. Un événement de credential porte son environnement ══════════════

    public function test_credential_event_populates_the_environment_column(): void
    {
        $this->callCredentialAudit('provider.credentials.upsert', ['environment' => 'production']);

        $log = $this->lastLog();
        $this->assertNotNull($log);
        $this->assertSame('provider_credentials', $log['entity_type']);
        $this->assertSame(
            'production',
            $log['environment'],
            'Un événement de credential doit être filtrable par environnement.'
        );
    }

    // ══ 2. Une valeur douteuse n'est pas forcée ════════════════════════════

    public function test_unknown_environment_is_not_forged(): void
    {
        $this->callCredentialAudit('provider.credentials.test', ['environment' => 'staging']);

        $log = $this->lastLog();
        $this->assertNotNull($log);
        $this->assertNull(
            $log['environment'],
            'Une valeur hors ENUM ne doit pas être remplacée par une valeur inventée.'
        );
    }

    // ══ 3. Aucun secret, même si un champ sensible est ajouté demain ═══════

    public function test_credential_audit_redacts_secrets(): void
    {
        $this->callCredentialAudit('provider.credentials.upsert', [
            'environment' => 'sandbox',
            'secret_key'  => 'sk_live_NE_DOIT_PAS_FUITER',
            'api_token'   => 'tok_NE_DOIT_PAS_FUITER',
        ]);

        $log = $this->lastLog();
        $this->assertNotNull($log);
        $raw = (string) $log['metadata'];

        $this->assertStringNotContainsString('sk_live_NE_DOIT_PAS_FUITER', $raw);
        $this->assertStringNotContainsString('tok_NE_DOIT_PAS_FUITER', $raw);
        // L'information non sensible reste exploitable.
        $this->assertStringContainsString('stripe', $raw);
    }

    // ══ 4. Les événements sans notion d'exécution restent sans environnement ══

    /**
     * §3 — « Ne pas ajouter artificiellement environment aux événements qui
     * n'ont aucune notion d'exécution. » Une authentification n'appartient à
     * aucun environnement d'exécution : la colonne doit rester NULL.
     */
    public function test_authentication_events_carry_no_environment(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address)
             VALUES (:u, :a, :t, :i, :m, :ip)'
        );
        $stmt->execute([
            'u'  => $this->userId,
            'a'  => 'auth.login',
            't'  => 'users',
            'i'  => $this->userId,
            'm'  => json_encode(['email' => 'x@y.z']),
            'ip' => '127.0.0.1',
        ]);

        $log = $this->lastLog();
        $this->assertNotNull($log);
        $this->assertSame('auth.login', $log['action']);
        $this->assertNull(
            $log['environment'],
            'Un événement d\'authentification ne doit pas se voir attribuer un environnement.'
        );
    }

    // ══ 5. La colonne permet une ventilation réelle ════════════════════════

    public function test_logs_can_be_partitioned_by_environment(): void
    {
        $this->callCredentialAudit('provider.credentials.upsert', ['environment' => 'sandbox']);
        $this->callCredentialAudit('provider.credentials.upsert', ['environment' => 'production']);
        $this->callCredentialAudit('provider.credentials.delete', ['environment' => 'production']);

        $stmt = $this->pdo->prepare(
            'SELECT environment, COUNT(*) c FROM audit_logs
              WHERE user_id = :u AND environment IS NOT NULL
              GROUP BY environment'
        );
        $stmt->execute(['u' => $this->userId]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(1, (int) $counts['sandbox']);
        $this->assertSame(2, (int) $counts['production']);
    }
}
