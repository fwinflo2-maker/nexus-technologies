<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Providers\ProviderRegistry;
use Nexus\Services\IdempotencyService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PHASE 2 — AUDIT DES ESPACES DE NOMS D'IDEMPOTENCE.
 *
 * Deux garanties, en sens inverse l'une de l'autre :
 *
 *   1. sandbox + K et production + K  → DEUX opérations indépendantes ;
 *   2. sandbox + K et sandbox + K     → LA MÊME opération (rejeu).
 *
 * Et la garantie structurelle : aucune contrainte SQL d'idempotence ne doit
 * rester globale, faute de quoi un objet d'un environnement produit un effet
 * observable — ne serait-ce qu'un blocage — dans l'autre.
 */
final class IdempotencyScopeAuditTest extends TestCase
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
            'n' => 'Idem Scope',
            'e' => 'idem_' . $suffix . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            $this->pdo->prepare('DELETE FROM idempotency_keys WHERE user_id = ?')->execute([$this->userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->userId = 0;
        }
    }

    // ══ 1. Deux environnements, une même clé → indépendance ════════════════

    public function test_same_key_is_independent_across_environments(): void
    {
        $key = 'scope-' . bin2hex(random_bytes(5));

        $sandbox = IdempotencyService::start($key, $this->userId, null, 'sandbox');
        $this->assertTrue($sandbox['created'], 'La réservation sandbox doit être créée.');

        // La MÊME clé en production ne doit pas voir la réservation sandbox.
        $production = IdempotencyService::start($key, $this->userId, null, 'production');
        $this->assertTrue(
            $production['created'],
            'Une clé consommée en sandbox ne doit jamais bloquer la production.'
        );

        // Deux lignes distinctes coexistent.
        $stmt = $this->pdo->prepare(
            'SELECT environment FROM idempotency_keys WHERE idempotency_key = :k AND user_id = :u ORDER BY environment'
        );
        $stmt->execute(['k' => $key, 'u' => $this->userId]);
        $envs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        sort($envs); // ORDER BY sur un ENUM trie par ordinal, pas alphabétiquement.
        $this->assertSame(['production', 'sandbox'], $envs);
    }

    // ══ 2. Même environnement, une même clé → rejeu ════════════════════════

    public function test_same_key_in_same_environment_is_a_replay(): void
    {
        foreach (['sandbox', 'production'] as $env) {
            $key = 'replay-' . $env . '-' . bin2hex(random_bytes(5));

            $first = IdempotencyService::start($key, $this->userId, null, $env);
            $this->assertTrue($first['created'], sprintf('[%s] première réservation', $env));

            $second = IdempotencyService::start($key, $this->userId, null, $env);
            $this->assertFalse(
                $second['created'],
                sprintf('[%s] la seconde tentative doit être détectée comme rejeu', $env)
            );
        }
    }

    // ══ 3. Aucune réponse ne franchit la frontière ═════════════════════════

    /**
     * Le scénario dangereux : une réponse de sandbox servie à un appel de
     * production ferait croire à un succès sans exécution réelle.
     */
    public function test_a_cached_sandbox_response_is_never_served_to_production(): void
    {
        $key = 'cross-' . bin2hex(random_bytes(5));

        IdempotencyService::start($key, $this->userId, 'op-sandbox', 'sandbox');
        IdempotencyService::complete($key, $this->userId, ['result' => 'SANDBOX'], 'op-sandbox', 'sandbox');

        // Vue depuis la production : la clé n'existe pas.
        $this->assertNull(
            IdempotencyService::check($key, $this->userId, 'production'),
            'Aucune réponse sandbox ne doit être visible depuis la production.'
        );

        // Et réciproquement.
        $cached = IdempotencyService::check($key, $this->userId, 'sandbox');
        $this->assertNotNull($cached);
        $this->assertSame('SANDBOX', $cached['response_json']['result']);
    }

    public function test_a_cached_production_response_is_never_served_to_sandbox(): void
    {
        $key = 'cross-rev-' . bin2hex(random_bytes(5));

        IdempotencyService::start($key, $this->userId, 'op-prod', 'production');
        IdempotencyService::complete($key, $this->userId, ['result' => 'PRODUCTION'], 'op-prod', 'production');

        $this->assertNull(
            IdempotencyService::check($key, $this->userId, 'sandbox'),
            'Aucune réponse production ne doit être visible depuis la sandbox.'
        );
    }

    // ══ 4. Garantie structurelle : plus aucun espace de noms global ════════

    /**
     * Vérifie en base qu'aucune contrainte UNIQUE portant une clé
     * d'idempotence n'omet la colonne `environment`.
     *
     * Ce test est le filet : il échouerait si une future migration
     * réintroduisait un index global.
     */
    public function test_no_idempotency_unique_index_ignores_environment(): void
    {
        $rows = $this->pdo->query(
            "SELECT s.TABLE_NAME, s.INDEX_NAME,
                    GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) AS cols
               FROM information_schema.STATISTICS s
              WHERE s.TABLE_SCHEMA = DATABASE()
                AND s.NON_UNIQUE = 0
                AND s.INDEX_NAME <> 'PRIMARY'
              GROUP BY s.TABLE_NAME, s.INDEX_NAME
             HAVING cols LIKE '%idempotency_key%'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($rows, 'Au moins un index d\'idempotence doit exister.');

        foreach ($rows as $row) {
            $this->assertStringContainsString(
                'environment',
                (string) $row['cols'],
                sprintf(
                    'L\'index %s.%s (%s) est un espace de noms GLOBAL : une clé consommée dans un '
                    . 'environnement bloquerait l\'autre.',
                    $row['TABLE_NAME'],
                    $row['INDEX_NAME'],
                    $row['cols']
                )
            );
        }
    }

    // ══ 5. Le cache d'adaptateurs ne capture aucun environnement ═══════════

    /**
     * `ProviderRegistry` met les adaptateurs en cache par slug. Si un
     * adaptateur capturait son environnement (ou pire, une credential) à la
     * construction, le premier appelant figerait l'environnement de tous les
     * suivants.
     */
    public function test_provider_adapter_cache_is_environment_agnostic(): void
    {
        putenv('PROVIDERS_ENV=sandbox');
        $first = ProviderRegistry::adapter('stripe');

        putenv('PROVIDERS_ENV=production');
        $second = ProviderRegistry::adapter('stripe');

        // Même instance (cache) : c'est le comportement attendu…
        $this->assertSame($first, $second);

        // … et c'est acceptable UNIQUEMENT parce que l'adaptateur ne fige pas
        // d'environnement : il le relit à chaque appel.
        $this->assertSame('production', ProviderRegistry::environment('stripe'));

        putenv('PROVIDERS_ENV=sandbox');
        $this->assertSame('sandbox', ProviderRegistry::environment('stripe'));

        putenv('PROVIDERS_ENV');
    }
}
