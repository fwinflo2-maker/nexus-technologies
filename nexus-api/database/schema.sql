-- NEXUS — Schéma de base de données (Authentification JWT + Google OAuth + Téléphone)
-- À importer via phpMyAdmin ou : mysql -u root < database/schema.sql

CREATE DATABASE IF NOT EXISTS nexus
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE nexus;

-- --- Utilisateurs -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(120)  NOT NULL,
    email         VARCHAR(190)  NOT NULL,
    phone         VARCHAR(20)   NULL,
    password_hash VARCHAR(255)  NOT NULL DEFAULT '',
    account_type  ENUM('personal','business') NOT NULL DEFAULT 'personal',
    auth_provider ENUM('local','google') NOT NULL DEFAULT 'local',
    provider_id   VARCHAR(191)  NULL,
    status        ENUM('PENDING','ACTIVE','SUSPENDED','CLOSED') NOT NULL DEFAULT 'PENDING',
    kyc_level     ENUM('none','basic','standard','advanced') NOT NULL DEFAULT 'none',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_phone (phone)
) ENGINE=InnoDB;

-- --- Wallets -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallets (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            BIGINT UNSIGNED NOT NULL,
    currency           VARCHAR(5) NOT NULL,
    balance            DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    available_balance  DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    pending_balance    DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    in_transit_balance DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    settlement_balance DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wallets_user_currency (user_id, currency),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Transactions -------------------------------------------------------------
-- Source de vérité des agrégats du dashboard (KPIs, graphique, activité récente).
-- `amount` : montant dans la devise de la transaction.
-- `amount_ref` : équivalent en devise de référence (EUR) au moment de l'exécution.
-- `amount_xaf` : équivalent en XAF (utilisé pour le KPI Volume total).
CREATE TABLE IF NOT EXISTS transactions (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT UNSIGNED NOT NULL,
    type                   ENUM('send','receive','fx','convert') NOT NULL DEFAULT 'send',
    direction              ENUM('in','out','fx') NOT NULL DEFAULT 'out',
    label                  VARCHAR(190) NOT NULL,
    description            VARCHAR(255) NULL,
    amount                 DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    currency               VARCHAR(5) NOT NULL,
    amount_ref             DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    ref_currency           VARCHAR(5) NOT NULL DEFAULT 'EUR',
    amount_xaf             DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    fee                    DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    fee_currency           VARCHAR(5) NOT NULL DEFAULT 'EUR',
    status                 ENUM('completed','processing','pending','failed','cancelled') NOT NULL DEFAULT 'pending',
    provider               VARCHAR(50) NULL,
    destination            VARCHAR(190) NULL,
    execution_time_seconds INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tx_user_created (user_id, created_at),
    KEY idx_tx_user_status  (user_id, status),
    CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Notifications -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    type       VARCHAR(50) NOT NULL DEFAULT 'info',
    title      VARCHAR(190) NOT NULL,
    message    TEXT NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user_read (user_id, read_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Journal d'audit ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id   BIGINT UNSIGNED NULL,
    metadata    JSON NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id),
    KEY idx_audit_action_time (action, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- Tentatives de connexion (anti brute-force) ------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NULL,
    success    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_email_time (email, created_at)
) ENGINE=InnoDB;

-- --- Jetons révoqués (logout côté serveur) -----------------------------------
CREATE TABLE IF NOT EXISTS revoked_tokens (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jti        CHAR(32) NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_revoked_tokens_jti (jti),
    CONSTRAINT fk_revoked_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
