-- =============================================================================
-- 0.17 — Les credentials providers deviennent des actifs de la PLATEFORME
-- =============================================================================
--
-- LE DÉFAUT CORRIGÉ (CRITICAL, chemin d'exécution)
-- ------------------------------------------------
-- `provider_credentials` était scopée par `user_id`, avec
-- `UNIQUE (user_id, provider_slug, environment)`. Chaque client était donc
-- censé apporter ses propres identifiants Stripe / pawaPay.
--
-- La boucle 7 a réservé l'écriture au personnel plateforme — ce qui est
-- correct : c'est Nexus qui contracte avec les providers, pas le client.
-- Mais la LECTURE est restée scopée au client :
--
--     ProviderResolver::hasCredentialFor()
--         -> findRow($pdo, $context->subjectUserId, …)
--
-- Résultat : la credential déposée par le superadmin porte SON user_id et
-- reste invisible à tous les clients. Plus aucun transfert ne pouvait
-- résoudre un provider.
--
-- LE MODÈLE CORRECT
-- -----------------
-- Une credential provider appartient à la plateforme, pour un environnement
-- donné. Elle vaut pour tous les clients ; aucun client ne peut la lire, la
-- deviner ni la modifier.
--
-- `user_id` devient donc `NULL` = credential de plateforme, et l'unicité
-- porte sur (provider_slug, environment).
--
-- CE QUE CETTE MIGRATION NE FAIT PAS
-- ----------------------------------
-- Elle ne supprime AUCUNE donnée et ne réattribue aucune credential
-- existante à la plateforme : promouvoir automatiquement la credential d'un
-- client en credential globale l'exposerait à tous les autres clients. Les
-- lignes existantes sont conservées telles quelles et devront être traitées
-- explicitement par un opérateur.
--
-- Idempotente : rejouable sans effet de bord.
-- =============================================================================

USE nexus;

-- 1) `user_id` devient nullable : NULL = credential de plateforme.
SET @col_nullable := (
    SELECT IS_NULLABLE
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provider_credentials'
       AND COLUMN_NAME  = 'user_id'
);

SET @sql := IF(
    @col_nullable = 'NO',
    'ALTER TABLE provider_credentials MODIFY COLUMN user_id BIGINT UNSIGNED NULL DEFAULT NULL',
    'SELECT "user_id deja nullable" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Unicité de la credential de plateforme : un seul couple
--    (provider, environnement) pour les lignes globales.
--
--    MySQL/MariaDB traite les NULL comme distincts dans un index UNIQUE :
--    `UNIQUE (user_id, provider_slug, environment)` n'empêcherait donc PAS
--    deux credentials de plateforme concurrentes pour le même provider —
--    exactement la situation qui rendrait le choix non déterministe.
--
--    Une colonne générée résout le problème : elle vaut 0 pour une ligne de
--    plateforme, et l'identifiant client sinon.
SET @has_scope := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provider_credentials'
       AND COLUMN_NAME  = 'owner_scope'
);

SET @sql := IF(
    @has_scope = 0,
    'ALTER TABLE provider_credentials
        ADD COLUMN owner_scope BIGINT UNSIGNED
        AS (IFNULL(user_id, 0)) PERSISTENT',
    'SELECT "owner_scope deja presente" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Remplace l'ancienne unicité par une unicité fondée sur owner_scope.
--
--    ORDRE IMPOSÉ PAR INNODB : la clé étrangère `fk_provider_creds_user`
--    s'appuie sur `uq_provider_creds_env` (préfixe `user_id`). Tenter de
--    supprimer cet index d'abord échoue avec :
--        ERROR 1553 : Cannot drop index … needed in a foreign key constraint
--    Il faut donc retirer la FK, échanger les index, puis la recréer (étape 5).
SET @has_fk_before := (
    SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA    = DATABASE()
       AND TABLE_NAME      = 'provider_credentials'
       AND CONSTRAINT_NAME = 'fk_provider_creds_user'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
    @has_fk_before > 0,
    'ALTER TABLE provider_credentials DROP FOREIGN KEY fk_provider_creds_user',
    'SELECT "fk deja retiree" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index dédié à la FK : sans lui, la recréer réintroduirait une dépendance
-- sur l'index d'unicité, et le même blocage se reproduirait à la prochaine
-- évolution du schéma.
SET @has_user_idx := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provider_credentials'
       AND INDEX_NAME   = 'idx_provider_creds_user'
);

SET @sql := IF(
    @has_user_idx = 0,
    'ALTER TABLE provider_credentials ADD INDEX idx_provider_creds_user (user_id)',
    'SELECT "index user_id deja present" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_old := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provider_credentials'
       AND INDEX_NAME   = 'uq_provider_creds_env'
);

SET @sql := IF(
    @has_old > 0,
    'ALTER TABLE provider_credentials DROP INDEX uq_provider_creds_env',
    'SELECT "index historique deja retire" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_new := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provider_credentials'
       AND INDEX_NAME   = 'uq_provider_creds_scope'
);

SET @sql := IF(
    @has_new = 0,
    'ALTER TABLE provider_credentials
        ADD UNIQUE KEY uq_provider_creds_scope (owner_scope, provider_slug, environment)',
    'SELECT "index de portee deja present" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Traçabilité : qui a déposé cette credential ?
--    La colonne n'existait pas ; sans elle, aucune enquête n'est possible
--    après coup sur un secret de production.
SET @has_by := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'provider_credentials'
       AND COLUMN_NAME  = 'configured_by'
);

SET @sql := IF(
    @has_by = 0,
    'ALTER TABLE provider_credentials
        ADD COLUMN configured_by BIGINT UNSIGNED NULL DEFAULT NULL AFTER status',
    'SELECT "configured_by deja presente" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) La contrainte de clé étrangère doit tolérer NULL (plateforme).
--    ON DELETE CASCADE resterait correct pour une ligne client ; une ligne de
--    plateforme n'a pas de propriétaire à supprimer.
SET @has_fk := (
    SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA    = DATABASE()
       AND TABLE_NAME      = 'provider_credentials'
       AND CONSTRAINT_NAME = 'fk_provider_creds_user'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
    @has_fk = 0,
    'ALTER TABLE provider_credentials
        ADD CONSTRAINT fk_provider_creds_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
    'SELECT "fk deja presente" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
