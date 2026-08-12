-- NEXUS — Migration 0.4 : Comptes (Sources & Destinations)
-- À appliquer sur une base déjà initialisée (schema.sql) :
--   mysql -u root nexus < database/migrations/2026_08_10_payment_accounts.sql
--
-- Table `payment_accounts` : sources de financement et destinations
-- de paiement, scopées par user_id (RLS simulée).
--
-- Sécurité des données sensibles :
--   - iban_enc / phone_enc / pan_enc / address_enc sont chiffrés en
--     AES-256-GCM (clé APP_KEY) : jamais de données sensibles en clair
--     au repos, et l'API ne renvoie QUE des valeurs masquées.
--   - bic / operator / network / city restent en clair (non sensibles).

USE nexus;

CREATE TABLE IF NOT EXISTS payment_accounts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    role        ENUM('source','destination') NOT NULL,
    kind        ENUM('bank_iban','mobile_money','crypto_wallet','card','virtual_iban','cash_pickup') NOT NULL,
    label       VARCHAR(120) NOT NULL,
    holder_name VARCHAR(190) NULL,
    country     CHAR(2) NULL,
    currency    VARCHAR(5) NULL,
    operator    VARCHAR(50) NULL,
    network     VARCHAR(30) NULL,
    city        VARCHAR(120) NULL,
    iban_enc    TEXT NULL,
    bic         VARCHAR(20) NULL,
    phone_enc   TEXT NULL,
    pan_enc     TEXT NULL,
    expiry      VARCHAR(7) NULL,
    address_enc TEXT NULL,
    is_default  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_accounts_user_role (user_id, role),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
