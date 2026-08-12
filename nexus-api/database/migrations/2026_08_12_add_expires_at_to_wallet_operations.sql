-- Migration: add expires_at column to wallet_operations for Hold expiration
-- Applies to both nexus and nexus_test databases.
-- Idempotent: adds a nullable DATETIME column if it does not exist.

USE nexus;

-- Add column if not already present
ALTER TABLE wallet_operations
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER status;
