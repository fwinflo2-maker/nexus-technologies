-- NEXUS — Migration 0.2 : Authentification étendue (Google OAuth + téléphone)
-- À appliquer sur une base déjà initialisée (schema.sql) :
--   mysql -u root nexus < database/migrations/2026_08_10_oauth_phone.sql
--
-- IDEMPOTENTE : les colonnes/keys déjà présentes (le schema.sql racine inclut
-- déjà phone / auth_provider / provider_id) sont ignorées via IF NOT EXISTS.
-- Réappliquer cette migration est sans effet.

USE nexus;

-- --- Étape 1 : phone optionnel (pour login/inscription par téléphone) ---------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER email,
    ADD KEY IF NOT EXISTS idx_users_phone (phone);

-- --- Étape 2 : colonnes OAuth (Google) ----------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS auth_provider ENUM('local','google') NOT NULL DEFAULT 'local' AFTER account_type,
    ADD COLUMN IF NOT EXISTS provider_id   VARCHAR(191) NULL AFTER auth_provider,
    ADD UNIQUE KEY IF NOT EXISTS uq_users_provider (auth_provider, provider_id);

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
