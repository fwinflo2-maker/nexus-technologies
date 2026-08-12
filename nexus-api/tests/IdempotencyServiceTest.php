<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\IdempotencyService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Tests du IdempotencyService (Phase B3).
 *
 * Base utilisée : `nexus_test` (isolée, JAMAIS `nexus`).
 * Stratégie d'isolation : chaque test crée un utilisateur et des clés avec
 * des identifiants uniques (timestamp + compteur + aléa), puis supprime les
 * lignes `idempotency_keys` et `users` créées en `tearDown`. Aucun `DROP`
 * massif, aucune suppression croisée, la base de dev n'est jamais touchée.
 */
final class IdempotencyServiceTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /**
     * Clés d'idempotence créées par le test courant.
     *
     * @var list<array{key:string, userId:int}>
     */
    private array $createdKeys = [];

    /**
     * IDs utilisateurs créés par le test courant.
     *
     * @var list<int>
     */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        // Garde-fou supplémentaire : refuse de tourner contre la base de dev.
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail(
                'Refus de tourner contre la base "' . $dbName . '". ' .
                'Les tests doivent utiliser nexus_test uniquement.'
            );
        }

        $this->createdKeys    = [];
        $this->createdUserIds = [];
    }

    protected function tearDown(): void
    {
        // Nettoyage ciblé : suppression par clé+user, jamais de TRUNCATE.
        try {
            foreach ($this->createdKeys as $entry) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM idempotency_keys WHERE idempotency_key = :key AND user_id = :uid'
                );
                $stmt->execute(['key' => $entry['key'], 'uid' => $entry['userId']]);
            }

            if (!empty($this->createdUserIds)) {
                $placeholders = implode(',', array_fill(0, count($this->createdUserIds), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                $stmt->execute($this->createdUserIds);
            }
        } catch (Throwable $e) {
            // Le nettoyage ne doit pas masquer un échec de test, mais on log.
            fwrite(STDERR, '[tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers de création de fixtures
    // ─────────────────────────────────────────────────────────────────────

    private function uniqueSuffix(): string
    {
        self::$counter++;
        return sprintf('%d_%d_%s', time(), self::$counter, bin2hex(random_bytes(3)));
    }

    private function createUser(string $suffix): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:name, :email, :pwd, :type, :status, :kyc)'
        );
        $stmt->execute([
            'name'   => 'Idem Test ' . $suffix,
            'email'  => 'idem_' . $suffix . '@example.com',
            'pwd'    => '',
            'type'   => 'personal',
            'status' => 'ACTIVE',
            'kyc'    => 'none',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function makeKey(string $suffix): string
    {
        return 'idem_' . $suffix; // < 64 caractères (VARCHAR(64))
    }

    /**
     * Enregistre une clé pour nettoyage en tearDown.
     */
    private function track(string $key, int $userId): void
    {
        $this->createdKeys[] = ['key' => $key, 'userId' => $userId];
    }

    /**
     * Insère directement une ligne (pour simuler un état préexistant :
     * concurrence, expiration, etc.).
     */
    private function insertRowDirectly(
        string $key,
        int $userId,
        string $status,
        ?string $responseJson = null,
        ?string $expiresAt = null,
        ?string $operationId = null
    ): int {
        $expiresAt ??= gmdate('Y-m-d H:i:s', time() + 86400);

        $stmt = $this->pdo->prepare(
            'INSERT INTO idempotency_keys
                (idempotency_key, user_id, operation_id, response_json, status, expires_at)
             VALUES
                (:key, :uid, :opid, :resp, :status, :exp)'
        );
        $stmt->execute([
            'key'    => $key,
            'uid'    => $userId,
            'opid'   => $operationId,
            'resp'   => $responseJson,
            'status' => $status,
            'exp'    => $expiresAt,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Lit une ligne d'idempotence (assoc) ou false.
     *
     * @return array<string,mixed>|false
     */
    private function getRow(string $key, int $userId): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, idempotency_key, user_id, operation_id, response_json, status, expires_at
             FROM idempotency_keys
             WHERE idempotency_key = :key AND user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['key' => $key, 'uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function countRows(string $key, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM idempotency_keys WHERE idempotency_key = :key AND user_id = :uid'
        );
        $stmt->execute(['key' => $key, 'uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests obligatoires
    // ─────────────────────────────────────────────────────────────────────

    public function test_premiere_requete_aucun_resultat(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        // Première requête : aucune clé → aucun résultat.
        $this->assertNull(
            IdempotencyService::check($key, $userId),
            'check() doit retourner null pour une clé inconnue.'
        );
    }

    public function test_start_cree_une_cle(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        $state = IdempotencyService::start($key, $userId);

        // État retourné : réservation processing.
        $this->assertTrue($state['created'], 'start() doit créer la clé (created=true).');
        $this->assertSame('processing', $state['status']);
        $this->assertNull($state['operation_id']);
        $this->assertNull($state['response_json']);

        // expires_at ≈ now + 24 h (TTL par défaut).
        $expTs = strtotime($state['expires_at'] . ' UTC');
        $this->assertNotFalse($expTs, 'expires_at doit être une date valide.');
        $this->assertGreaterThan(time() + 86300, $expTs, 'expires_at doit être dans ~24 h.');
        $this->assertLessThan(time() + 86410, $expTs, 'expires_at doit être dans ~24 h.');

        // En base : exactement 1 ligne en processing.
        $row = $this->getRow($key, $userId);
        $this->assertIsArray($row, 'start() doit insérer une ligne en base.');
        $this->assertSame('processing', $row['status']);
        $this->assertSame(1, $this->countRows($key, $userId));
    }

    public function test_deuxieme_requete_detecte_l_existence(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        IdempotencyService::start($key, $userId);

        // Deuxième requête : check() détecte la clé.
        $cached = IdempotencyService::check($key, $userId);
        $this->assertNotNull($cached, 'check() doit détecter la clé créée par start().');
        $this->assertSame('processing', $cached['status']);
        $this->assertNull($cached['response_json']);

        // Un second start() (simulation de requête concurrente) doit
        // détecter l'existence sans créer de doublon.
        $again = IdempotencyService::start($key, $userId);
        $this->assertFalse($again['created'], 'Un second start() doit détecter la clé existante.');
        $this->assertSame('processing', $again['status']);
        $this->assertSame(1, $this->countRows($key, $userId), 'Une seule ligne doit exister.');
    }

    public function test_complete_sauvegarde_response_json(): void
    {
        $suffix     = $this->uniqueSuffix();
        $userId     = $this->createUser($suffix);
        $key        = $this->makeKey($suffix);
        $operationId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $this->track($key, $userId);

        $state = IdempotencyService::start($key, $userId);
        $this->assertTrue($state['created']);

        $response = [
            'success'     => true,
            'operation_id' => $operationId,
            'amount'      => '50.00',
            'currency'    => 'EUR',
            'fx_rate'     => 655.957,
        ];
        IdempotencyService::complete($key, $userId, $response, $operationId);

        // La ligne est en completed avec response_json correctement stocké.
        $row = $this->getRow($key, $userId);
        $this->assertIsArray($row);
        $this->assertSame('completed', $row['status']);
        $this->assertSame($response, json_decode((string) $row['response_json'], true));
        $this->assertSame($operationId, $row['operation_id']);
    }

    public function test_check_retourne_reponse_mise_en_cache(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        IdempotencyService::start($key, $userId);

        $response = [
            'success' => true,
            'id'      => 'op_123',
            'items'   => ['a', 'b', 'c'],
        ];
        IdempotencyService::complete($key, $userId, $response);

        $cached = IdempotencyService::check($key, $userId);
        $this->assertNotNull($cached);
        $this->assertSame('completed', $cached['status']);
        $this->assertSame($response, $cached['response_json'], 'check() doit retourner la réponse mise en cache.');

        // Rejouable : deux appels retournent exactement la même réponse.
        $this->assertSame($cached, IdempotencyService::check($key, $userId));
    }

    public function test_fail_passe_en_erreur(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        IdempotencyService::start($key, $userId);

        IdempotencyService::fail($key, $userId, 'Solde insuffisant');

        // La ligne est en error avec le message stocké dans response_json.
        $row = $this->getRow($key, $userId);
        $this->assertIsArray($row);
        $this->assertSame('error', $row['status']);
        $this->assertSame(
            ['error' => 'Solde insuffisant'],
            json_decode((string) $row['response_json'], true)
        );

        // check() détecte l'état error.
        $cached = IdempotencyService::check($key, $userId);
        $this->assertNotNull($cached);
        $this->assertSame('error', $cached['status']);
        $this->assertSame('Solde insuffisant', $cached['response_json']['error']);
    }

    public function test_cle_expiree_correctement_traitee(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        IdempotencyService::start($key, $userId);

        // Expiration forcée (UTC, une heure dans le passé).
        $stmt = $this->pdo->prepare(
            'UPDATE idempotency_keys SET expires_at = :exp
             WHERE idempotency_key = :key AND user_id = :uid'
        );
        $stmt->execute([
            'exp' => gmdate('Y-m-d H:i:s', time() - 3600),
            'key' => $key,
            'uid' => $userId,
        ]);

        // check() traite la clé expirée comme absente (aucun replay).
        $this->assertNull(
            IdempotencyService::check($key, $userId),
            'check() doit ignorer une clé expirée.'
        );

        // start() réclame la clé expirée : nouvelle réservation processing.
        $state = IdempotencyService::start($key, $userId);
        $this->assertTrue($state['created'], 'start() doit réclamer une clé expirée (created=true).');
        $this->assertSame('processing', $state['status']);

        $row = $this->getRow($key, $userId);
        $this->assertIsArray($row);
        $this->assertSame('processing', $row['status']);
        $this->assertNull($row['response_json'], 'La réclamation doit purger l\'ancienne réponse.');
        $this->assertGreaterThan(
            time(),
            strtotime($row['expires_at'] . ' UTC'),
            'La nouvelle expiration doit être dans le futur.'
        );
        $this->assertSame(1, $this->countRows($key, $userId), 'Une seule ligne doit exister après réclamation.');
    }

    public function test_deux_utilisateurs_peuvent_utiliser_la_meme_cle(): void
    {
        $suffix = $this->uniqueSuffix();
        $userA  = $this->createUser($suffix);
        $userB  = $this->createUser($suffix . '_b');
        $key    = $this->makeKey($suffix);
        $this->track($key, $userA);
        $this->track($key, $userB);

        // Aucun des deux n'a encore de clé.
        $this->assertNull(IdempotencyService::check($key, $userA));
        $this->assertNull(IdempotencyService::check($key, $userB));

        // Chacun réserve la même clé indépendamment.
        $stateA = IdempotencyService::start($key, $userA);
        $stateB = IdempotencyService::start($key, $userB);
        $this->assertTrue($stateA['created'], 'Le premier utilisateur doit réserver la clé.');
        $this->assertTrue($stateB['created'], 'Le second utilisateur peut réserver la même clé.');

        // User A complète ; User B n'est pas impacté.
        IdempotencyService::complete($key, $userA, ['success' => true, 'owner' => 'A']);

        $cachedA = IdempotencyService::check($key, $userA);
        $this->assertSame('completed', $cachedA['status']);
        $this->assertSame('A', $cachedA['response_json']['owner']);

        $cachedB = IdempotencyService::check($key, $userB);
        $this->assertNotNull($cachedB, 'La clé de B doit rester indépendante.');
        $this->assertSame('processing', $cachedB['status'], 'B doit rester en processing.');

        // Deux lignes distinctes (une par utilisateur).
        $this->assertSame(1, $this->countRows($key, $userA));
        $this->assertSame(1, $this->countRows($key, $userB));
    }

    public function test_collision_concurrence_detectee(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        // Simule un requête concurrente ayant déjà réservé la clé (processing).
        $this->insertRowDirectly($key, $userId, 'processing');

        // start() doit DÉTECTER la collision, pas créer un doublon.
        $state = IdempotencyService::start($key, $userId);
        $this->assertFalse($state['created'], 'start() doit détecter la clé déjà réservée.');
        $this->assertSame('processing', $state['status']);
        $this->assertSame(1, $this->countRows($key, $userId), 'Aucun doublon ne doit être créé.');

        // check() détecte l'état processing.
        $cached = IdempotencyService::check($key, $userId);
        $this->assertNotNull($cached);
        $this->assertSame('processing', $cached['status']);
    }

    public function test_collision_avec_cle_completee(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        // Simule un concurrent qui a déjà terminé : start() doit retourner
        // l'état completed et la réponse cachée (pas de doublon).
        $this->insertRowDirectly(
            $key,
            $userId,
            'completed',
            json_encode(['success' => true, 'id' => 'already_done'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $state = IdempotencyService::start($key, $userId);
        $this->assertFalse($state['created']);
        $this->assertSame('completed', $state['status']);
        $this->assertSame('already_done', $state['response_json']['id']);
        $this->assertSame(1, $this->countRows($key, $userId));
    }

    public function test_rollback_en_cas_d_echec(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        $state = IdempotencyService::start($key, $userId);
        $this->assertTrue($state['created']);

        // Réponse non sérialisable en JSON (séquence UTF-8 invalide) :
        // complete() doit échouer et ROLLBACK laisser la ligne en processing.
        try {
            IdempotencyService::complete($key, $userId, "\xC3\x28");
            $this->fail('complete() avec une réponse non sérialisable aurait dû lever une exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sérialiser', $e->getMessage());
        }

        $row = $this->getRow($key, $userId);
        $this->assertIsArray($row);
        $this->assertSame('processing', $row['status'], 'La ligne doit rester en processing après rollback.');
        $this->assertNull($row['response_json'], 'response_json doit rester NULL après rollback.');
        $this->assertNull($row['operation_id'], 'operation_id doit rester NULL après rollback.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests complémentaires (robustesse des transitions)
    // ─────────────────────────────────────────────────────────────────────

    public function test_complete_deux_fois_est_idempotent(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        IdempotencyService::start($key, $userId);

        $first  = ['success' => true, 'id' => 'op_first'];
        $second = ['success' => true, 'id' => 'op_second'];

        IdempotencyService::complete($key, $userId, $first);
        // Second complete : no-op idempotent, la 1re réponse est conservée.
        IdempotencyService::complete($key, $userId, $second);

        $cached = IdempotencyService::check($key, $userId);
        $this->assertSame('completed', $cached['status']);
        $this->assertSame($first, $cached['response_json'], 'La 1re réponse doit être conservée.');
    }

    public function test_fail_apres_complete_refuse(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        IdempotencyService::start($key, $userId);
        IdempotencyService::complete($key, $userId, ['success' => true]);

        try {
            IdempotencyService::fail($key, $userId, 'échec tardif');
            $this->fail('fail() après complete() aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('déjà complétée', $e->getMessage());
        }

        $row = $this->getRow($key, $userId);
        $this->assertSame('completed', $row['status']);
    }

    public function test_complete_cle_inconnue_refuse(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        // Aucun start() préalable → complete() doit échouer sans rien écrire.
        try {
            IdempotencyService::complete($key, $userId, ['success' => true]);
            $this->fail('complete() sans start() préalable aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Appelez start()', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows($key, $userId), 'Aucune ligne ne doit exister.');
    }

    public function test_cle_vide_refusee(): void
    {
        $userId = $this->createUser($this->uniqueSuffix());

        try {
            IdempotencyService::check('', $userId);
            $this->fail('Une clé vide aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vide', $e->getMessage());
        }
    }

    public function test_cle_trop_longue_refusee(): void
    {
        $userId = $this->createUser($this->uniqueSuffix());
        $tooLong = str_repeat('a', 65); // > VARCHAR(64)

        try {
            IdempotencyService::start($tooLong, $userId);
            $this->fail('Une clé de 65 caractères aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('trop longue', $e->getMessage());
        }
    }

    public function test_operation_id_trop_long_refuse(): void
    {
        $suffix = $this->uniqueSuffix();
        $userId = $this->createUser($suffix);
        $key    = $this->makeKey($suffix);
        $this->track($key, $userId);

        $tooLong = str_repeat('a', 37); // > VARCHAR(36)

        try {
            IdempotencyService::start($key, $userId, $tooLong);
            $this->fail('Un operation_id de 37 caractères aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('operation_id trop long', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows($key, $userId), 'Aucune ligne ne doit être créée.');
    }

    public function test_start_avec_operation_id(): void
    {
        $suffix      = $this->uniqueSuffix();
        $userId      = $this->createUser($suffix);
        $key         = $this->makeKey($suffix);
        $operationId = '11111111-2222-4333-8444-555555555555';
        $this->track($key, $userId);

        $state = IdempotencyService::start($key, $userId, $operationId);
        $this->assertTrue($state['created']);
        $this->assertSame($operationId, $state['operation_id']);

        $row = $this->getRow($key, $userId);
        $this->assertSame($operationId, $row['operation_id']);
    }
}
