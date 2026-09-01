-- NEXUS — Migration 0.35 : Modèle comptable cible (EXPAND, phase 1)
--
-- Transformation du ledger en véritable General Ledger, sans rupture :
-- uniquement des AJOUTS compatibles (aucune modification destructive).
--
--  1. chart_of_accounts     — le plan de comptes Nexus (§5 du modèle cible)
--  2. provider_accounts     — comptes de Nexus chez les providers (contrepartie
--                             externe des positions utilisateurs)
--  3. provider_balances     — OBSERVATIONS externes du solde chez le provider
--                             (jamais une source de création d'argent)
--  4. reconciliation_runs   — trace des contrôles quotidien par compte provider
--  5. ledger_entries        — account_code (compte GL), is_legacy (avant-bascule),
--                             migrated_at (date de backfill), wallet_id NULLABLE
--                             (les comptes provider/revenus/suspense ne sont pas
--                             des wallets), balance_after NULLABLE (même raison)
--  6. transactions / wallet_operations — provider_account_id (rattachement au
--                             compte provider réel de l'opération)
--
-- Chaque ajout est gardé par information_schema : réappliquer est sans effet.
-- La contrepartie comptable des legs non-wallet est garantie au niveau SERVICE
-- (LedgerService::post — invariant Σdebit = Σcredit par opération/devise/env).

USE nexus;

-- ════════════════════════════════════════════════════════════════════════
-- 1. chart_of_accounts
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(60)  NOT NULL,
    name         VARCHAR(190) NOT NULL,
    currency     VARCHAR(5)   NULL,          -- NULL = compte multi-devises
    account_type ENUM('asset','liability','equity','revenue','expense','gain_loss') NOT NULL,
    environment  ENUM('sandbox','production') NULL,  -- NULL = applicable aux deux
    active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chart_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comptes obligatoires du modèle cible (§5). Idempotent : INSERT IGNORE.
INSERT IGNORE INTO chart_of_accounts (code, name, currency, account_type, environment) VALUES
    ('USER_POSITION.EUR',            'Position utilisateur EUR',               'EUR', 'liability', NULL),
    ('USER_POSITION.USD',            'Position utilisateur USD',               'USD', 'liability', NULL),
    ('USER_POSITION.XAF',            'Position utilisateur XAF',               'XAF', 'liability', NULL),
    ('SUSPENSE.EUR',                 'Fonds sans contrepartie identifiée EUR', 'EUR', 'asset',     NULL),
    ('SUSPENSE.USD',                 'Fonds sans contrepartie identifiée USD', 'USD', 'asset',     NULL),
    ('SUSPENSE.XAF',                 'Fonds sans contrepartie identifiée XAF', 'XAF', 'asset',     NULL),
    ('PROVIDER_ASSET.pawapay.EUR',   'Fonds détenus chez pawaPay EUR',         'EUR', 'asset',     NULL),
    ('PROVIDER_ASSET.pawapay.XAF',   'Fonds détenus chez pawaPay XAF',         'XAF', 'asset',     NULL),
    ('PROVIDER_SETTLEMENT.pawapay.EUR', 'Transit settlement pawaPay EUR',      'EUR', 'asset',     NULL),
    ('PROVIDER_SETTLEMENT.pawapay.XAF', 'Transit settlement pawaPay XAF',      'XAF', 'asset',     NULL),
    ('PROVIDER_FEES.pawapay',        'Frais prélevés par pawaPay',             'EUR', 'expense',   NULL),
    ('NEXUS_REVENUE.fee',            'Revenus de frais Nexus',                 'EUR', 'revenue',   NULL),
    ('FX_TRANSIT.EURXAF',            'Transit de conversion EUR/XAF',          NULL,  'asset',     NULL),
    ('FX_GAIN_LOSS.EURXAF',          'Gain/perte de change EUR/XAF',           NULL,  'gain_loss', NULL),
    ('REFUND',                       'Réserve de remboursements',              NULL,  'liability', NULL),
    ('CHARGEBACK',                   'Réserve de contre-passations',           NULL,  'liability', NULL);

-- ════════════════════════════════════════════════════════════════════════
-- 2. provider_accounts
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS provider_accounts (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_slug           VARCHAR(50)  NOT NULL,
    environment             ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    external_account_id     VARCHAR(190) NULL,   -- identifiant réel chez le provider
    currency                VARCHAR(5)   NOT NULL,
    account_type            ENUM('safeguarding','settlement','operating','pool') NOT NULL DEFAULT 'safeguarding',
    status                  ENUM('active','paused','closed') NOT NULL DEFAULT 'active',
    provider_credentials_id BIGINT UNSIGNED NULL,
    label                   VARCHAR(190) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_account (provider_slug, environment, currency),
    KEY idx_provider_account_cred (provider_credentials_id),
    CONSTRAINT fk_provider_account_cred FOREIGN KEY (provider_credentials_id)
        REFERENCES provider_credentials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 3. provider_balances — OBSERVATIONS externes, jamais créatrices d'argent
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS provider_balances (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_account_id BIGINT UNSIGNED NOT NULL,
    currency            VARCHAR(5)  NOT NULL,
    available_balance   DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    pending_balance     DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    reported_at         DATETIME    NOT NULL,
    source              ENUM('api','webhook','statement') NOT NULL DEFAULT 'api',
    raw                 LONGTEXT    NULL,       -- réponse brute du provider
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_provider_balance_account (provider_account_id, reported_at),
    CONSTRAINT fk_provider_balance_account FOREIGN KEY (provider_account_id)
        REFERENCES provider_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 4. reconciliation_runs — contrôles quotidien par compte provider
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS reconciliation_runs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_account_id BIGINT UNSIGNED NOT NULL,
    environment         ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    opening_balance     DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    expected_balance    DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    provider_balance    DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    difference          DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    status              ENUM('pending','matched','discrepancy') NOT NULL DEFAULT 'pending',
    notes               VARCHAR(255) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recon_run (provider_account_id, period_start),
    CONSTRAINT fk_recon_run_account FOREIGN KEY (provider_account_id)
        REFERENCES provider_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 5. ledger_entries — passage au General Ledger (additif)
-- ════════════════════════════════════════════════════════════════════════

-- 5a. account_code : compte GL du leg (USER_POSITION, PROVIDER_ASSET, ...).
--     NULL pendant la phase EXPAND ; backfillé par scripts/ledger_migrate.php
--     puis contraint au niveau service (jamais NULL pour une écriture post-bascule).
SET @nx_35a := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND COLUMN_NAME = 'account_code');
SET @nx_sql_35a := IF(@nx_35a = 0,
    'ALTER TABLE ledger_entries ADD COLUMN account_code VARCHAR(60) NULL AFTER entry_type',
    'DO 0');
PREPARE nx_stmt_35a FROM @nx_sql_35a;
EXECUTE nx_stmt_35a;
DEALLOCATE PREPARE nx_stmt_35a;

-- 5b. is_legacy : 1 = écriture antérieure à la bascule (hors calculs GL courants,
--     conservée pour l'audit). Toujours 0 pour les écritures post-bascule.
SET @nx_35b := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND COLUMN_NAME = 'is_legacy');
SET @nx_sql_35b := IF(@nx_35b = 0,
    'ALTER TABLE ledger_entries ADD COLUMN is_legacy TINYINT(1) NOT NULL DEFAULT 0 AFTER account_code',
    'DO 0');
PREPARE nx_stmt_35b FROM @nx_sql_35b;
EXECUTE nx_stmt_35b;
DEALLOCATE PREPARE nx_stmt_35b;

-- 5c. migrated_at : horodatage du backfill (audit).
SET @nx_35c := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND COLUMN_NAME = 'migrated_at');
SET @nx_sql_35c := IF(@nx_35c = 0,
    'ALTER TABLE ledger_entries ADD COLUMN migrated_at DATETIME NULL AFTER is_legacy',
    'DO 0');
PREPARE nx_stmt_35c FROM @nx_sql_35c;
EXECUTE nx_stmt_35c;
DEALLOCATE PREPARE nx_stmt_35c;

-- 5d. wallet_id NULLABLE : les legs provider/revenus/suspense n'ont pas de wallet.
--     La FK est supprimée puis recréée (les FK existantes sur wallet_id doivent
--     accepter NULL). NOTE : la garde utilise TABLE_CONSTRAINTS, pas STATISTICS
--     — MySQL réutilise l'index idx_ledger_wallet_time pour la FK, donc aucun
--     index nommé fk_ledger_wallet n'existe dans STATISTICS.
SET @nx_35d := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND CONSTRAINT_NAME = 'fk_ledger_wallet' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @nx_sql_35d := IF(@nx_35d > 0, 'ALTER TABLE ledger_entries DROP FOREIGN KEY fk_ledger_wallet', 'DO 0');
PREPARE nx_stmt_35d FROM @nx_sql_35d;
EXECUTE nx_stmt_35d;
DEALLOCATE PREPARE nx_stmt_35d;

SET @nx_35e := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND COLUMN_NAME = 'wallet_id');
SET @nx_sql_35e := IF(@nx_35e = 'NO',
    'ALTER TABLE ledger_entries MODIFY wallet_id BIGINT UNSIGNED NULL',
    'DO 0');
PREPARE nx_stmt_35e FROM @nx_sql_35e;
EXECUTE nx_stmt_35e;
DEALLOCATE PREPARE nx_stmt_35e;

SET @nx_35f := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND CONSTRAINT_NAME = 'fk_ledger_wallet' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @nx_sql_35f := IF(@nx_35f = 0,
    'ALTER TABLE ledger_entries ADD CONSTRAINT fk_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE',
    'DO 0');
PREPARE nx_stmt_35f FROM @nx_sql_35f;
EXECUTE nx_stmt_35f;
DEALLOCATE PREPARE nx_stmt_35f;

-- 5g. balance_after NULLABLE : un leg provider/revenu n'a pas de solde de wallet.
SET @nx_35g := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND COLUMN_NAME = 'balance_after');
SET @nx_sql_35g := IF(@nx_35g = 'NO',
    'ALTER TABLE ledger_entries MODIFY balance_after DECIMAL(20,8) NULL',
    'DO 0');
PREPARE nx_stmt_35g FROM @nx_sql_35g;
EXECUTE nx_stmt_35g;
DEALLOCATE PREPARE nx_stmt_35g;

-- 5h. Index sur account_code (rapports GL).
SET @nx_35h := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND INDEX_NAME = 'idx_ledger_account');
SET @nx_sql_35h := IF(@nx_35h = 0,
    'ALTER TABLE ledger_entries ADD KEY idx_ledger_account (account_code)',
    'DO 0');
PREPARE nx_stmt_35h FROM @nx_sql_35h;
EXECUTE nx_stmt_35h;
DEALLOCATE PREPARE nx_stmt_35h;

-- ════════════════════════════════════════════════════════════════════════
-- 6. provider_account_id sur transactions / wallet_operations (phase 5)
-- ════════════════════════════════════════════════════════════════════════
SET @nx_35i := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'provider_account_id');
SET @nx_sql_35i := IF(@nx_35i = 0,
    'ALTER TABLE transactions ADD COLUMN provider_account_id BIGINT UNSIGNED NULL AFTER provider',
    'DO 0');
PREPARE nx_stmt_35i FROM @nx_sql_35i;
EXECUTE nx_stmt_35i;
DEALLOCATE PREPARE nx_stmt_35i;

SET @nx_35j := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_operations'
      AND COLUMN_NAME = 'provider_account_id');
SET @nx_sql_35j := IF(@nx_35j = 0,
    'ALTER TABLE wallet_operations ADD COLUMN provider_account_id BIGINT UNSIGNED NULL AFTER environment',
    'DO 0');
PREPARE nx_stmt_35j FROM @nx_sql_35j;
EXECUTE nx_stmt_35j;
DEALLOCATE PREPARE nx_stmt_35j;

SET @nx_35k := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_tx_provider_account');
SET @nx_sql_35k := IF(@nx_35k = 0,
    'ALTER TABLE transactions ADD KEY idx_tx_provider_account (provider_account_id)',
    'DO 0');
PREPARE nx_stmt_35k FROM @nx_sql_35k;
EXECUTE nx_stmt_35k;
DEALLOCATE PREPARE nx_stmt_35k;
