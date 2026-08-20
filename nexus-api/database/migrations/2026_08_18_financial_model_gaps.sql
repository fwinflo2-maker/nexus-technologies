-- NEXUS — Migration 0.38 : rattrapage modèle cible §12 (EXPAND, phase 2)
--
-- Aligne le schéma sur le modèle financier cible (docs/NEXUS-FINANCIAL-MODEL-TARGET.md
-- §12.2/§12.3) — uniquement des AJOUTS compatibles, aucune modification destructive :
--
--  1. transactions.status  += 'created','quoted','authorized' (machine à états §11)
--  2. transactions         += source_provider_account_id (multi-provider §7/P5)
--  3. transactions         += FK provider_account_id → provider_accounts (§12.3)
--  4. reconciliation_items += run_id (FK reconciliation_runs), source
--     ENUM('polling','webhook','balance','statement'), operation_id (§9.1 unifié)
--  5. provider_balances    += reserved, method (§3.3)
--
-- Chaque ajout est gardé par information_schema : réappliquer est sans effet.

USE nexus;

-- ════════════════════════════════════════════════════════════════════════
-- 1. transactions.status — machine à états financière cible (§11)
--    created → quoted → authorized → processing → completed
--    (valeurs existantes conservées ; extension d'enum sans reclassement)
-- ════════════════════════════════════════════════════════════════════════
SET @nx_38a := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%created%');
SET @nx_sql_38a := IF(@nx_38a = 0,
    'ALTER TABLE transactions
     MODIFY status ENUM(''created'',''quoted'',''authorized'',''processing'',''completed'',''pending'',''failed'',''cancelled'',''reversed'',''refunded'',''reconciliation_required'') NOT NULL DEFAULT ''pending''',
    'DO 0');
PREPARE nx_stmt_38a FROM @nx_sql_38a;
EXECUTE nx_stmt_38a;
DEALLOCATE PREPARE nx_stmt_38a;

-- ════════════════════════════════════════════════════════════════════════
-- 2. transactions.source_provider_account_id — multi-provider (§7/P5)
--    = le provider account d'ORIGINE des fonds (rail source), distinct du
--    provider_account_id = rail de payout. NULL = mono-provider.
-- ════════════════════════════════════════════════════════════════════════
SET @nx_38b := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'source_provider_account_id');
SET @nx_sql_38b := IF(@nx_38b = 0,
    'ALTER TABLE transactions ADD COLUMN source_provider_account_id BIGINT UNSIGNED NULL AFTER provider_account_id',
    'DO 0');
PREPARE nx_stmt_38b FROM @nx_sql_38b;
EXECUTE nx_stmt_38b;
DEALLOCATE PREPARE nx_stmt_38b;

SET @nx_38c := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_tx_source_provider_account');
SET @nx_sql_38c := IF(@nx_38c = 0,
    'ALTER TABLE transactions ADD KEY idx_tx_source_provider_account (source_provider_account_id)',
    'DO 0');
PREPARE nx_stmt_38c FROM @nx_sql_38c;
EXECUTE nx_stmt_38c;
DEALLOCATE PREPARE nx_stmt_38c;

-- ════════════════════════════════════════════════════════════════════════
-- 3. FK transactions.provider_account_id → provider_accounts (§12.3)
--    (0 orphelin constaté en dev/test ; garde par TABLE_CONSTRAINTS)
-- ════════════════════════════════════════════════════════════════════════
SET @nx_38d := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND CONSTRAINT_NAME = 'fk_tx_provider_account' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @nx_sql_38d := IF(@nx_38d = 0,
    'ALTER TABLE transactions
     ADD CONSTRAINT fk_tx_provider_account FOREIGN KEY (provider_account_id)
     REFERENCES provider_accounts (id) ON DELETE SET NULL',
    'DO 0');
PREPARE nx_stmt_38d FROM @nx_sql_38d;
EXECUTE nx_stmt_38d;
DEALLOCATE PREPARE nx_stmt_38d;

SET @nx_38e := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND CONSTRAINT_NAME = 'fk_tx_source_provider_account' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @nx_sql_38e := IF(@nx_38e = 0,
    'ALTER TABLE transactions
     ADD CONSTRAINT fk_tx_source_provider_account FOREIGN KEY (source_provider_account_id)
     REFERENCES provider_accounts (id) ON DELETE SET NULL',
    'DO 0');
PREPARE nx_stmt_38e FROM @nx_sql_38e;
EXECUTE nx_stmt_38e;
DEALLOCATE PREPARE nx_stmt_38e;

-- ════════════════════════════════════════════════════════════════════════
-- 4. reconciliation_items — pipeline unifié (§9.1)
--    + run_id        → reconciliation_runs (lien au contrôle quotidien)
--    + source        → polling / webhook / balance / statement
--    + operation_id  → lien posting suspense (ledger_entries)
-- ════════════════════════════════════════════════════════════════════════
SET @nx_38f := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reconciliation_items'
      AND COLUMN_NAME = 'run_id');
SET @nx_sql_38f := IF(@nx_38f = 0,
    'ALTER TABLE reconciliation_items ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER transaction_id',
    'DO 0');
PREPARE nx_stmt_38f FROM @nx_sql_38f;
EXECUTE nx_stmt_38f;
DEALLOCATE PREPARE nx_stmt_38f;

SET @nx_38g := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reconciliation_items'
      AND COLUMN_NAME = 'source');
SET @nx_sql_38g := IF(@nx_38g = 0,
    'ALTER TABLE reconciliation_items ADD COLUMN source ENUM(''polling'',''webhook'',''balance'',''statement'') NOT NULL DEFAULT ''polling'' AFTER run_id',
    'DO 0');
PREPARE nx_stmt_38g FROM @nx_sql_38g;
EXECUTE nx_stmt_38g;
DEALLOCATE PREPARE nx_stmt_38g;

SET @nx_38h := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reconciliation_items'
      AND COLUMN_NAME = 'operation_id');
SET @nx_sql_38h := IF(@nx_38h = 0,
    'ALTER TABLE reconciliation_items ADD COLUMN operation_id VARCHAR(36) NULL AFTER source',
    'DO 0');
PREPARE nx_stmt_38h FROM @nx_sql_38h;
EXECUTE nx_stmt_38h;
DEALLOCATE PREPARE nx_stmt_38h;

SET @nx_38i := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reconciliation_items'
      AND INDEX_NAME = 'idx_recon_run');
SET @nx_sql_38i := IF(@nx_38i = 0,
    'ALTER TABLE reconciliation_items ADD KEY idx_recon_run (run_id)',
    'DO 0');
PREPARE nx_stmt_38i FROM @nx_sql_38i;
EXECUTE nx_stmt_38i;
DEALLOCATE PREPARE nx_stmt_38i;

SET @nx_38j := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'reconciliation_items'
      AND CONSTRAINT_NAME = 'fk_recon_run_item' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @nx_sql_38j := IF(@nx_38j = 0,
    'ALTER TABLE reconciliation_items
     ADD CONSTRAINT fk_recon_run_item FOREIGN KEY (run_id)
     REFERENCES reconciliation_runs (id) ON DELETE SET NULL',
    'DO 0');
PREPARE nx_stmt_38j FROM @nx_sql_38j;
EXECUTE nx_stmt_38j;
DEALLOCATE PREPARE nx_stmt_38j;

-- ════════════════════════════════════════════════════════════════════════
-- 5. provider_balances — observations externes (§3.3)
--    + reserved : réservé par le provider (encours de settlement)
--    + method   : endpoint / numéro de relevé qui a produit l'observation
-- ════════════════════════════════════════════════════════════════════════
SET @nx_38k := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_balances'
      AND COLUMN_NAME = 'reserved');
SET @nx_sql_38k := IF(@nx_38k = 0,
    'ALTER TABLE provider_balances ADD COLUMN reserved DECIMAL(20,8) NOT NULL DEFAULT 0.00000000 AFTER pending_balance',
    'DO 0');
PREPARE nx_stmt_38k FROM @nx_sql_38k;
EXECUTE nx_stmt_38k;
DEALLOCATE PREPARE nx_stmt_38k;

SET @nx_38l := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_balances'
      AND COLUMN_NAME = 'method');
SET @nx_sql_38l := IF(@nx_38l = 0,
    'ALTER TABLE provider_balances ADD COLUMN method VARCHAR(50) NULL AFTER source',
    'DO 0');
PREPARE nx_stmt_38l FROM @nx_sql_38l;
EXECUTE nx_stmt_38l;
DEALLOCATE PREPARE nx_stmt_38l;
