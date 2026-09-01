-- NEXUS — Migration 0.2 : Authentification étendue (Google OAuth + téléphone)
-- À appliquer sur une base déjà initialisée (schema.sql) :
--   mysql -u root nexus < database/migrations/2026_08_10_oauth_phone.sql
--
-- IDEMPOTENTE : les colonnes/keys déjà présentes (le schema.sql racine inclut
-- déjà phone / auth_provider / provider_id) sont ignorées via un test
-- information_schema (portable MySQL 8 / MariaDB).
-- Réappliquer cette migration est sans effet.

USE nexus;

-- --- Étape 1 : phone optionnel (pour login/inscription par téléphone) ---------
SET @nx_13 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'phone');
SET @nx_sql_13 := IF(@nx_13 = 0, 'ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email', 'DO 0');
PREPARE nx_stmt_13 FROM @nx_sql_13;
EXECUTE nx_stmt_13;
DEALLOCATE PREPARE nx_stmt_13;

SET @nx_14 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_phone');
SET @nx_sql_14 := IF(@nx_14 = 0, 'ALTER TABLE users ADD KEY idx_users_phone (phone)', 'DO 0');
PREPARE nx_stmt_14 FROM @nx_sql_14;
EXECUTE nx_stmt_14;
DEALLOCATE PREPARE nx_stmt_14;

-- --- Étape 2 : colonnes OAuth (Google) ----------------------------------------
SET @nx_15 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'auth_provider');
SET @nx_sql_15 := IF(@nx_15 = 0, 'ALTER TABLE users ADD COLUMN auth_provider ENUM(''local'',''google'') NOT NULL DEFAULT ''local'' AFTER account_type', 'DO 0');
PREPARE nx_stmt_15 FROM @nx_sql_15;
EXECUTE nx_stmt_15;
DEALLOCATE PREPARE nx_stmt_15;

SET @nx_16 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'provider_id');
SET @nx_sql_16 := IF(@nx_16 = 0, 'ALTER TABLE users ADD COLUMN provider_id   VARCHAR(191) NULL AFTER auth_provider', 'DO 0');
PREPARE nx_stmt_16 FROM @nx_sql_16;
EXECUTE nx_stmt_16;
DEALLOCATE PREPARE nx_stmt_16;

SET @nx_17 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uq_users_provider');
SET @nx_sql_17 := IF(@nx_17 = 0, 'ALTER TABLE users ADD UNIQUE KEY uq_users_provider (auth_provider, provider_id)', 'DO 0');
PREPARE nx_stmt_17 FROM @nx_sql_17;
EXECUTE nx_stmt_17;
DEALLOCATE PREPARE nx_stmt_17;

-- --- Étape 3 : password_hash devient optionnel (utilisateurs Google) -----------
ALTER TABLE users
    MODIFY COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '';

-- --- Étape 4 : identifiants Google OAuth ---------------------------------------
CREATE TABLE IF NOT EXISTS oauth_identities (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    provider     VARCHAR(20) NOT NULL,
    provider_sub VARCHAR(191) NOT NULL,
    email        VARCHAR(190) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_oauth_provider_sub (provider, provider_sub),
    KEY idx_oauth_user (user_id),
    CONSTRAINT fk_oauth_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
