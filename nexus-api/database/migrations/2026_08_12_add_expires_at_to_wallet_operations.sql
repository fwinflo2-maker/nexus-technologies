-- Migration: add expires_at column to wallet_operations for Hold expiration
-- Applies to both nexus and nexus_test databases.
-- Idempotent: adds a nullable DATETIME column if it does not exist.

USE nexus;

-- Add column if not already present
SET @nx_19 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_operations'
      AND COLUMN_NAME = 'expires_at');
SET @nx_sql_19 := IF(@nx_19 = 0, 'ALTER TABLE wallet_operations ADD COLUMN expires_at DATETIME NULL AFTER status', 'DO 0');
PREPARE nx_stmt_19 FROM @nx_sql_19;
EXECUTE nx_stmt_19;
DEALLOCATE PREPARE nx_stmt_19;
