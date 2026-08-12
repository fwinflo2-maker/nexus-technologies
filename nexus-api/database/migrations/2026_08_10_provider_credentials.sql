-- NEXUS — Migration 0.5 : Credentials des providers (API keys chiffrées)
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.4) :
--   mysql -u root nexus < database/migrations/2026_08_10_provider_credentials.sql
--
-- Stocke les identifiants API de chaque provider externe, chiffrés en
-- AES-256-GCM via APP_KEY. La colonne `credentials_enc` contient un
-- blob JSON chiffré — la structure dépend du provider (voir ProviderCatalog).
--
-- Sécurité : les secrets ne sont JAMAIS stockés en clair et l'API
-- ne renvoie que les métadonnées (status, environment, last_tested_at).

USE nexus;

CREATE TABLE IF NOT EXISTS provider_credentials (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    provider_slug   VARCHAR(50) NOT NULL,
    environment     ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    credentials_enc TEXT NULL,
    status          ENUM('not_configured','sandbox_only','active','error') NOT NULL DEFAULT 'not_configured',
    last_tested_at  DATETIME NULL,
    last_error      TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_creds (user_id, provider_slug),
    CONSTRAINT fk_provider_creds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
