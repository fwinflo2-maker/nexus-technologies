-- NEXUS — Migration 0.10 : Business suite (bénéficiaires, paiements, équipe, réconciliation)
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.2–0.9) :
--   mysql -u root nexus < database/migrations/2026_08_14_business_suite.sql
--
-- Idempotente (CREATE TABLE IF NOT EXISTS) : réappliquer est sans effet.
--
-- Tables :
--   beneficiaries         → bénéficiaires des paiements (références chiffrées)
--   payments              → workflow paiement (draft → approbation → exécution)
--   team_members          → RBAC (rôles & permissions côté backend)
--   reconciliation_items  → rapprochement ledger ↔ relevés provider

USE nexus;

-- ==========================================================================
-- 1. Bénéficiaires
-- ==========================================================================
CREATE TABLE IF NOT EXISTS beneficiaries (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               BIGINT UNSIGNED NOT NULL,             -- compte Business propriétaire
    name                  VARCHAR(190)  NOT NULL,
    country               CHAR(2)       NOT NULL,
    currency              VARCHAR(5)    NOT NULL DEFAULT 'XAF',
    method                ENUM('mobile_money','bank','crypto','cash_pickup') NOT NULL DEFAULT 'mobile_money',
    account_reference_enc TEXT          NULL,                   -- IBAN / téléphone / adresse, chiffré
    operator              VARCHAR(50)   NULL,                   -- opérateur Mobile Money (optionnel)
    bank_name             VARCHAR(190)  NULL,                   -- banque (optionnel)
    status                ENUM('active','inactive','pending_verification') NOT NULL DEFAULT 'active',
    verification_status   ENUM('unverified','verified','rejected') NOT NULL DEFAULT 'unverified',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_beneficiaries_user (user_id, status),
    CONSTRAINT fk_beneficiaries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================================
-- 2. Paiements (workflow d'approbation + exécution)
-- ==========================================================================
CREATE TABLE IF NOT EXISTS payments (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           BIGINT UNSIGNED NOT NULL,             -- compte Business propriétaire
    beneficiary_id    BIGINT UNSIGNED NULL,                 -- nullable si bénéficiaire supprimé
    purpose           VARCHAR(120)   NULL,
    source_currency   VARCHAR(5)     NOT NULL,
    dest_currency     VARCHAR(5)     NOT NULL,
    amount            DECIMAL(20,2)  NOT NULL,              -- montant source
    amount_ref        DECIMAL(20,2)  NOT NULL DEFAULT 0.00, -- équivalent EUR
    fee               DECIMAL(20,2)  NOT NULL DEFAULT 0.00, -- frais en devise source
    fee_currency      VARCHAR(5)     NOT NULL DEFAULT 'EUR',
    dest_amount       DECIMAL(20,2)  NULL,                  -- montant reçu (quote)
    fx_rate           DECIMAL(20,8)  NULL,                  -- taux appliqué (quote)
    provider          VARCHAR(50)    NULL,                  -- provider de la route retenue
    route_id          VARCHAR(10)    NULL,                  -- A/B/C
    destination       VARCHAR(190)   NULL,                  -- libellé destination
    status            ENUM('draft','pending_approval','approved','executing','completed','failed','rejected','cancelled')
                                    NOT NULL DEFAULT 'draft',
    created_by        BIGINT UNSIGNED NULL,                 -- utilisateur à l'origine
    approved_by       BIGINT UNSIGNED NULL,                 -- approbateur
    approved_at       DATETIME NULL,
    executed_at       DATETIME NULL,
    transaction_id    BIGINT UNSIGNED NULL,                 -- transaction ledger liée
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_payments_user_status (user_id, status),
    KEY idx_payments_beneficiary (beneficiary_id),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_beneficiary FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================================
-- 3. Équipe & permissions (RBAC)
-- ==========================================================================
CREATE TABLE IF NOT EXISTS team_members (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_user_id BIGINT UNSIGNED NOT NULL,               -- compte Business propriétaire
    member_user_id   BIGINT UNSIGNED NOT NULL,               -- compte utilisateur du membre
    role             ENUM('owner','admin','finance_manager','accountant','operator','viewer')
                                   NOT NULL DEFAULT 'viewer',
    status           ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team_member (business_user_id, member_user_id),
    KEY idx_team_business (business_user_id),
    CONSTRAINT fk_team_business FOREIGN KEY (business_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_member     FOREIGN KEY (member_user_id)   REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================================
-- 4. Rapprochement (ledger ↔ relevés provider)
-- ==========================================================================
CREATE TABLE IF NOT EXISTS reconciliation_items (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            BIGINT UNSIGNED NOT NULL,
    transaction_id     BIGINT UNSIGNED NULL,
    provider_reference VARCHAR(190)   NULL,
    expected_amount    DECIMAL(20,2)  NOT NULL,
    actual_amount      DECIMAL(20,2)  NULL,
    currency           VARCHAR(5)     NOT NULL,
    status             ENUM('pending','matched','unmatched','discrepancy','resolved') NOT NULL DEFAULT 'pending',
    notes              VARCHAR(255)   NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at        DATETIME NULL,
    UNIQUE KEY uq_recon_tx (transaction_id),
    KEY idx_recon_user_status (user_id, status),
    CONSTRAINT fk_recon_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_recon_tx   FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB;
