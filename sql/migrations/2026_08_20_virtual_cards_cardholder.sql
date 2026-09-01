-- NEXUS — Migration 0.43 : cardholder Stripe Issuing sur virtual_cards
--
-- Stocke l'id cardholder (ich_…) séparé de l'id carte (ic_…).
-- Jamais de PAN / CVV.

SET @nx_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'virtual_cards'
     AND COLUMN_NAME = 'issuer_cardholder_id'
);
SET @nx_sql := IF(
  @nx_col = 0,
  'ALTER TABLE virtual_cards ADD COLUMN issuer_cardholder_id VARCHAR(190) NULL AFTER issuer_ref',
  'DO 0'
);
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
