-- 0.21 — password_changed_at : invalide les JWT émis avant un reset / changement
-- de mot de passe (sans connaître leurs jti individuels).
-- Idempotent (information_schema + PREPARE).

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'password_changed_at'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL AFTER password_hash',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
