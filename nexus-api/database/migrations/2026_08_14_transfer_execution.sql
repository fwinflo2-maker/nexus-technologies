-- NEXUS — Migration 0.9 : Transfer execution (Execution Engine)
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.3–0.8) :
--   mysql -u root nexus < database/migrations/2026_08_14_transfer_execution.sql
--
-- Enrichit la table `transactions` pour rendre chaque transfert exécuté
-- auto-porteur (détail complet sans re-calcul) :
--   - quote_id / route_id : traçabilité vers la quote source (audit)
--   - dest_amount / dest_currency : montant réellement reçu par le bénéficiaire
--   - fx_rate : taux appliqué lors de l'exécution
--
-- Idempotente (ajouts gardés par information_schema) : réappliquer est sans effet.

USE nexus;

SET @nx_23 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'quote_id');
SET @nx_sql_23 := IF(@nx_23 = 0, 'ALTER TABLE transactions ADD COLUMN quote_id      VARCHAR(22)   NULL AFTER id', 'DO 0');
PREPARE nx_stmt_23 FROM @nx_sql_23;
EXECUTE nx_stmt_23;
DEALLOCATE PREPARE nx_stmt_23;

SET @nx_24 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'route_id');
SET @nx_sql_24 := IF(@nx_24 = 0, 'ALTER TABLE transactions ADD COLUMN route_id      VARCHAR(10)   NULL AFTER quote_id', 'DO 0');
PREPARE nx_stmt_24 FROM @nx_sql_24;
EXECUTE nx_stmt_24;
DEALLOCATE PREPARE nx_stmt_24;

SET @nx_25 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'dest_amount');
SET @nx_sql_25 := IF(@nx_25 = 0, 'ALTER TABLE transactions ADD COLUMN dest_amount   DECIMAL(20,2) NULL AFTER amount_xaf', 'DO 0');
PREPARE nx_stmt_25 FROM @nx_sql_25;
EXECUTE nx_stmt_25;
DEALLOCATE PREPARE nx_stmt_25;

SET @nx_26 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'dest_currency');
SET @nx_sql_26 := IF(@nx_26 = 0, 'ALTER TABLE transactions ADD COLUMN dest_currency VARCHAR(5)    NULL AFTER dest_amount', 'DO 0');
PREPARE nx_stmt_26 FROM @nx_sql_26;
EXECUTE nx_stmt_26;
DEALLOCATE PREPARE nx_stmt_26;

SET @nx_27 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'fx_rate');
SET @nx_sql_27 := IF(@nx_27 = 0, 'ALTER TABLE transactions ADD COLUMN fx_rate       DECIMAL(20,8) NULL AFTER dest_currency', 'DO 0');
PREPARE nx_stmt_27 FROM @nx_sql_27;
EXECUTE nx_stmt_27;
DEALLOCATE PREPARE nx_stmt_27;
