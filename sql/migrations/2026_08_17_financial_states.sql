-- NEXUS — Migration 0.36 : états financiers cibles (enums additifs)
--
--  1. wallet_operations.type  += 'opening_balance' : les postings d'ouverture
--     comptable (migration historique) ont besoin d'un type dédié.
--  2. transactions.status     += 'reversed','refunded','reconciliation_required'
--     (machine à états financière cible ; les valeurs existantes restent
--     valides — extension d'enum sans reclassement de données).
--
-- Additif, gardé par information_schema, réversible (les nouvelles valeurs
-- n'impactent aucune ligne existante).

USE nexus;

SET @nx_36a := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_operations'
      AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%opening_balance%');
SET @nx_sql_36a := IF(@nx_36a = 0,
    'ALTER TABLE wallet_operations
     MODIFY type ENUM(''deposit'',''withdrawal'',''send'',''receive'',''convert'',''fee'',''refund'',''welcome_bonus'',''hold'',''opening_balance'') NOT NULL',
    'DO 0');
PREPARE nx_stmt_36a FROM @nx_sql_36a;
EXECUTE nx_stmt_36a;
DEALLOCATE PREPARE nx_stmt_36a;

SET @nx_36b := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%reversed%');
SET @nx_sql_36b := IF(@nx_36b = 0,
    'ALTER TABLE transactions
     MODIFY status ENUM(''completed'',''processing'',''pending'',''failed'',''cancelled'',''reversed'',''refunded'',''reconciliation_required'') NOT NULL DEFAULT ''pending''',
    'DO 0');
PREPARE nx_stmt_36b FROM @nx_sql_36b;
EXECUTE nx_stmt_36b;
DEALLOCATE PREPARE nx_stmt_36b;
