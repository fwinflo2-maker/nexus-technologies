<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Providers\ProviderConfig;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * IdempotencyService — prévention des doubles soumissions (Phase B3).
 *
 * Gère le cycle de vie des clés d'idempotence dans la table
 * `idempotency_keys` :
 *
 *   - `check()`   → lit l'état actuel d'une clé (null si absente ou expirée).
 *   - `start()`   → réserve la clé (status = processing) pour l'opération
 *                   en cours ; réclame proprement une clé expirée.
 *   - `complete()`→ enregistre la réponse de la première exécution
 *                   (status = completed, response_json).
 *   - `fail()`    → marque la clé en erreur (status = error).
 *
 * Cycle de vie d'une opération :
 *
 *   $cached = IdempotencyService::check($key, $userId);
 *   if ($cached !== null) {
 *       // completed  → rejouer response_json
 *       // processing → 409 (opération en cours)
 *       // error      → traiter l'échec précédent
 *   }
 *   $state = IdempotencyService::start($key, $userId);
 *   try {
 *       $result = ...; // travail réel (LedgerService, providers, etc.)
 *       IdempotencyService::complete($key, $userId, $result, $operationId);
 *       return $result;
 *   } catch (Throwable $e) {
 *       IdempotencyService::fail($key, $userId, $e->getMessage());
 *       throw $e;
 *   }
 *
 * Contraintes de conception :
 *   - Une clé est TOUJOURS isolée par `user_id` (contrainte UNIQUE
 *     `uq_idem_key (idempotency_key, user_id)` de la migration wallet_core).
 *   - Toutes les comparaisons de dates se font en UTC (la connexion force
 *     `time_zone = '+00:00'`, les horodatages sont générés via gmdate()).
 *   - Concurrence : deux `start()` simultanés sur la même clé → le premier
 *     INSERT gagne, le second reçoit un duplicate key (SQLSTATE 23000),
 *     relit la ligne existante et retourne son état sans ré-exécuter.
 *   - Expiration : une clé expirée est invisible pour `check()` et est
 *     réclamée par `start()` (DELETE + INSERT dans une transaction).
 *   - Atomicité : toute lecture-modification passe par une transaction
 *     (SELECT ... FOR UPDATE) avec rollback en cas d'échec.
 *
 * La durée de vie par défaut est de 24 h (convention de la migration
 * `2026_08_10_wallet_core.sql`, commentaire TTL sur `expires_at`).
 */
final class IdempotencyService
{
    /** TTL par défaut d'une clé : 24 h (défini par la migration wallet_core). */
    private const DEFAULT_TTL_SECONDS = 86400;

    /** Longueur maximale de la colonne `idempotency_key` (VARCHAR(64)). */
    private const KEY_MAX_LEN = 64;

    /** Longueur maximale de la colonne `operation_id` (VARCHAR(36)). */
    private const OPERATION_ID_MAX_LEN = 36;

    private function __construct()
    {
        // Classe utilitaire : méthodes statiques uniquement.
    }

    // ──────────────────────────────────────────────────────────────────────
    // API publique
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Lit l'état actuel d'une clé d'idempotence pour un utilisateur.
     *
     * Retourne `null` si la clé n'existe pas OU est expirée (une clé
     * expirée est traitée comme absente : aucune réponse ne doit être
     * rejouée après le TTL).
     *
     * @return array{status:string, operation_id:?string, response_json:mixed, expires_at:string}|null
     *         - status        : 'processing' | 'completed' | 'error'
     *         - operation_id  : UUID lié (si connu)
     *         - response_json : réponse décodée (uniquement pour 'completed')
     *         - expires_at    : 'Y-m-d H:i:s' (UTC)
     */
    public static function check(string $key, int $userId, ?string $environment = null): ?array
    {
        self::assertKey($key);
        self::assertUserId($userId);

        $pdo = Database::getConnection();
        $row = self::loadRow($pdo, $key, $userId, self::scope($environment));

        if ($row === false || self::isExpired($row['expires_at'])) {
            return null;
        }

        return self::stateFromRow($row);
    }

    /**
     * Réserve une clé d'idempotence (status = processing) avant exécution
     * de l'opération.
     *
     * Gestion des collisions sous concurrence :
     *   - INSERT simple d'abord. En cas de duplicate key (SQLSTATE 23000),
     *     on relit la ligne existante sous `SELECT ... FOR UPDATE` :
     *       * ligne active     → retourne son état (created = false).
     *       * ligne expirée    → réclamation atomique (DELETE + INSERT
     *         dans la même transaction), retourne created = true.
     *
     * @return array{created:bool, status:string, operation_id:?string, response_json:mixed, expires_at:string}
     *         - created : true si cette exécution a (ré)créé la clé,
     *                     false si la clé existait déjà (concurrence).
     */
    public static function start(
        string $key,
        int $userId,
        ?string $operationId = null,
        ?string $environment = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): array {
        self::assertKey($key);
        self::assertUserId($userId);
        self::assertOperationId($operationId);

        if ($ttlSeconds <= 0) {
            throw new RuntimeException(
                sprintf('TTL invalide : %d (doit être strictement positif).', $ttlSeconds)
            );
        }

        $pdo         = Database::getConnection();
        $expiresAt   = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $environment = self::scope($environment);

        try {
            self::insertProcessing($pdo, $key, $userId, $operationId, $expiresAt, $environment);
            return self::processingState($operationId, $expiresAt, created: true);
        } catch (PDOException $e) {
            // On ne récupère que le duplicate key (contrainte UNIQUE).
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        // Collision : la clé existe déjà pour cet utilisateur.
        return self::resolveCollision($pdo, $key, $userId, $operationId, $expiresAt, $environment);
    }

    /**
     * Marque une clé comme `completed` et enregistre la réponse de la
     * première exécution (rejouée ensuite par `check()`).
     *
     * Atomicité : SELECT ... FOR UPDATE puis UPDATE dans une transaction.
     * En cas d'échec (réponse non sérialisable, erreur DB), rollback et
     * la ligne reste dans son état précédent (processing).
     *
     * @param mixed               $response     Réponse sérialisable (array, string, int, ...).
     * @param string|null         $operationId  UUID de l'opération (lien wallet_operations).
     *
     * @throws RuntimeException Si la clé est inconnue, déjà en erreur, ou si
     *                          la réponse n'est pas sérialisable en JSON.
     */
    public static function complete(
        string $key,
        int $userId,
        mixed $response,
        ?string $operationId = null,
        ?string $environment = null
    ): void {
        self::assertKey($key);
        self::assertUserId($userId);
        self::assertOperationId($operationId);

        $responseJson = self::encodeResponse($response, 'réponse');
        self::transition($key, $userId, 'completed', $responseJson, $operationId, $environment);
    }

    /**
     * Marque une clé comme `error` après un échec d'exécution.
     *
     * Le message d'erreur est stocké dans `response_json` sous la forme
     * `{"error": "..."}` (la colonne dédiée n'existe pas dans le schéma,
     * c'est le seul canal de payload disponible).
     *
     * Atomicité : identique à `complete()` (transaction + FOR UPDATE).
     *
     * @throws RuntimeException Si la clé est inconnue, déjà `completed`, ou
     *                          si le message n'est pas sérialisable en JSON.
     */
    public static function fail(
        string $key,
        int $userId,
        ?string $errorMessage = null,
        ?string $operationId = null,
        ?string $environment = null
    ): void {
        self::assertKey($key);
        self::assertUserId($userId);
        self::assertOperationId($operationId);

        $errorJson = $errorMessage !== null
            ? self::encodeResponse(['error' => $errorMessage], 'message d\'erreur')
            : null;

        self::transition($key, $userId, 'error', $errorJson, $operationId, $environment);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers privés — cycle de vie
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Applique une transition d'état de manière atomique.
     *
     * Transitions autorisées :
     *   - processing → completed (complete)
     *   - processing → error    (fail)
     * Les transitions déjà appliquées sont idempotentes (no-op).
     * Les transitions invalides lèvent une RuntimeException.
     *
     * Atomicité : si une transaction PDO externe est déjà active
     * (cas de `WalletService::transferMultiCurrency`, qui enveloppe
     * `LedgerService::transfer` + `complete()` dans une transaction),
     * la transition y PARTICIPE sans appeler beginTransaction() une
     * deuxième fois. Appelé seul, le comportement est identique à
     * l'ancien contrat (transaction dédiée commit/rollback).
     */
    private static function transition(
        string $key,
        int $userId,
        string $targetStatus,
        ?string $responseJson,
        ?string $operationId,
        ?string $environment = null
    ): void {
        $pdo         = Database::getConnection();
        $environment = self::scope($environment);

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $row = self::loadRowForUpdate($pdo, $key, $userId, $environment);

            if ($row === false) {
                throw new RuntimeException(
                    sprintf(
                        'Aucune clé d\'idempotence active pour (key=%s, user_id=%d). ' .
                        'Appelez start() avant complete()/fail().',
                        $key,
                        $userId
                    )
                );
            }

            $current = (string) $row['status'];

            if ($targetStatus === 'completed') {
                if ($current === 'completed') {
                    // Déjà complétée : no-op idempotent (la 1re réponse est conservée).
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }
                    return;
                }
                if ($current === 'error') {
                    throw new RuntimeException(
                        sprintf('La clé d\'idempotence (%s) est déjà en erreur : impossible de la compléter.', $key)
                    );
                }
            } else { // 'error'
                if ($current === 'error') {
                    // Déjà en erreur : no-op idempotent.
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }
                    return;
                }
                if ($current === 'completed') {
                    throw new RuntimeException(
                        sprintf('La clé d\'idempotence (%s) est déjà complétée : impossible de la marquer en erreur.', $key)
                    );
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE idempotency_keys
                 SET status         = :status,
                     response_json  = COALESCE(:resp, response_json),
                     operation_id   = COALESCE(:opid, operation_id)
                 WHERE id = :id'
            );
            $stmt->execute([
                'status' => $targetStatus,
                'resp'   => $responseJson,
                'opid'   => $operationId,
                'id'     => $row['id'],
            ]);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Résout une collision de `start()` (duplicate key) en relisant la
     * ligne existante. Si la ligne est expirée, elle est réclamée de façon
     * atomique (DELETE + INSERT dans la même transaction).
     *
     * La boucle gère le cas où un concurrent ré-insère entre notre DELETE
     * et notre INSERT (nouveau duplicate key → on relit la ligne fraîche,
     * qui n'est plus expirée, et on retourne son état).
     */
    private static function resolveCollision(
        PDO $pdo,
        string $key,
        int $userId,
        ?string $operationId,
        string $expiresAt,
        string $environment
    ): array {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $pdo->beginTransaction();
            try {
                $row = self::loadRowForUpdate($pdo, $key, $userId, $environment);

                if ($row === false) {
                    // La ligne a disparu entre le duplicate et la relecture
                    // (cas très rare de DELETE concurrent) : on ré-essaie l'INSERT.
                    $pdo->commit();
                    try {
                        self::insertProcessing($pdo, $key, $userId, $operationId, $expiresAt, $environment);
                        return self::processingState($operationId, $expiresAt, created: true);
                    } catch (PDOException $e) {
                        if ((string) $e->getCode() !== '23000') {
                            throw $e;
                        }
                        continue; // Un concurrent a gagné : on re-tente la relecture.
                    }
                }

                if (!self::isExpired($row['expires_at'])) {
                    // Clé active : on retourne son état sans la modifier.
                    $pdo->commit();
                    return self::stateFromRow($row) + ['created' => false];
                }

                // Clé expirée → réclamation atomique.
                $stmt = $pdo->prepare('DELETE FROM idempotency_keys WHERE id = :id');
                $stmt->execute(['id' => $row['id']]);

                try {
                    self::insertProcessing($pdo, $key, $userId, $operationId, $expiresAt, $environment);
                    $pdo->commit();
                    return self::processingState($operationId, $expiresAt, created: true);
                } catch (PDOException $e) {
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                    // Un concurrent a ré-inséré entre DELETE et INSERT :
                    // rollback (annule aussi le DELETE) puis relecture.
                    $pdo->rollBack();
                    continue;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        throw new RuntimeException(
            sprintf('Conflit d\'idempotence persistant pour la clé "%s" (utilisateur %d).', $key, $userId)
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers privés — accès DB
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Environnement de scope d'une clé.
     *
     * §22 — la clé d'idempotence était globale : une même clé utilisée en
     * sandbox puis en production entrait en collision, et l'appel de
     * production recevait la réponse mise en cache par la sandbox — sans
     * jamais exécuter l'opération réelle. Les deux environnements ne
     * partagent donc plus le même espace de noms.
     */
    private static function scope(?string $environment): string
    {
        $env = strtolower(trim((string) $environment));

        return $env === 'sandbox' || $env === 'production'
            ? $env
            : ProviderConfig::defaultEnvironment();
    }

    /**
     * Insère une ligne en status `processing`.
     */
    private static function insertProcessing(
        PDO $pdo,
        string $key,
        int $userId,
        ?string $operationId,
        string $expiresAt,
        string $environment
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO idempotency_keys
                (idempotency_key, user_id, operation_id, status, expires_at, environment)
             VALUES
                (:key, :uid, :opid, \'processing\', :exp, :env)'
        );
        $stmt->execute([
            'key'  => $key,
            'uid'  => $userId,
            'opid' => $operationId,
            'exp'  => $expiresAt,
            'env'  => $environment,
        ]);
    }

    /**
     * Lit une ligne de clé d'idempotence (sans verrou).
     *
     * @return array<string,mixed>|false
     */
    private static function loadRow(PDO $pdo, string $key, int $userId, string $environment): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, idempotency_key, user_id, operation_id, response_json, status, expires_at
             FROM idempotency_keys
             WHERE idempotency_key = :key AND user_id = :uid AND environment = :env
             LIMIT 1'
        );
        $stmt->execute(['key' => $key, 'uid' => $userId, 'env' => $environment]);
        return $stmt->fetch();
    }

    /**
     * Lit une ligne de clé d'idempotence sous verrou `FOR UPDATE`
     * (à l'intérieur d'une transaction).
     *
     * @return array<string,mixed>|false
     */
    private static function loadRowForUpdate(PDO $pdo, string $key, int $userId, string $environment): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, idempotency_key, user_id, operation_id, response_json, status, expires_at
             FROM idempotency_keys
             WHERE idempotency_key = :key AND user_id = :uid AND environment = :env
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['key' => $key, 'uid' => $userId, 'env' => $environment]);
        return $stmt->fetch();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers privés — sérialisation et validation
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Sérialise une valeur en JSON (mêmes flags que Response.php et
     * LedgerService.php : UTF-8 non échappé, slashes non échappés).
     *
     * @throws RuntimeException Si la valeur n'est pas sérialisable (ex. UTF-8 invalide).
     */
    private static function encodeResponse(mixed $value, string $label): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException(
                sprintf('Impossible de sérialiser la %s en JSON (json_encode a échoué).', $label)
            );
        }

        return $json;
    }

    /**
     * Décode `response_json` (null si la colonne est vide/NULL).
     */
    private static function decodeResponse(?string $responseJson): mixed
    {
        if ($responseJson === null || $responseJson === '') {
            return null;
        }

        return json_decode($responseJson, true);
    }

    /**
     * Construit l'état retourné par `check()` à partir d'une ligne DB.
     *
     * @param array<string,mixed> $row
     *
     * @return array{status:string, operation_id:?string, response_json:mixed, expires_at:string}
     */
    private static function stateFromRow(array $row): array
    {
        return [
            'status'        => (string) $row['status'],
            'operation_id'  => $row['operation_id'] !== null ? (string) $row['operation_id'] : null,
            'response_json' => self::decodeResponse(
                $row['response_json'] !== null ? (string) $row['response_json'] : null
            ),
            'expires_at'    => (string) $row['expires_at'],
        ];
    }

    /**
     * Construit l'état d'une clé nouvellement réservée (processing).
     *
     * @return array{status:string, operation_id:?string, response_json:mixed, expires_at:string}
     */
    private static function processingState(?string $operationId, string $expiresAt, bool $created): array
    {
        return [
            'created'       => $created,
            'status'        => 'processing',
            'operation_id'  => $operationId,
            'response_json' => null,
            'expires_at'    => $expiresAt,
        ];
    }

    /**
     * Une clé est expirée si `expires_at` (UTC, colonne DATETIME) est
     * strictement antérieure à maintenant. La comparaison est faite en UTC
     * explicite pour être indépendante du fuseau par défaut de PHP.
     */
    private static function isExpired(string $expiresAt): bool
    {
        $expTs = strtotime($expiresAt . ' UTC');

        return $expTs !== false && $expTs < time();
    }

    /**
     * Valide la clé d'idempotence (non vide, longueur max VARCHAR(64)).
     */
    private static function assertKey(string $key): void
    {
        if ($key === '') {
            throw new RuntimeException('La clé d\'idempotence ne peut pas être vide.');
        }
        if (strlen($key) > self::KEY_MAX_LEN) {
            throw new RuntimeException(
                sprintf('Clé d\'idempotence trop longue : %d caractères (max %d).', strlen($key), self::KEY_MAX_LEN)
            );
        }
    }

    /**
     * Valide l'identifiant utilisateur (doit être un entier positif).
     */
    private static function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException(
                sprintf('user_id invalide : %d (doit être un entier positif).', $userId)
            );
        }
    }

    /**
     * Valide un operation_id optionnel (longueur max VARCHAR(36)).
     */
    private static function assertOperationId(?string $operationId): void
    {
        if ($operationId === null) {
            return;
        }
        if ($operationId === '') {
            throw new RuntimeException('operation_id ne peut pas être une chaîne vide (utiliser null).');
        }
        if (strlen($operationId) > self::OPERATION_ID_MAX_LEN) {
            throw new RuntimeException(
                sprintf('operation_id trop long : %d caractères (max %d).', strlen($operationId), self::OPERATION_ID_MAX_LEN)
            );
        }
    }
}
