-- =============================================================================
-- 0.44 — Messagerie interne : pièces jointes (images + PDF)
--
-- Étend internal_chat_messages avec attachment_name / attachment_url
-- (même modèle que support_messages).
-- =============================================================================

USE nexus;

ALTER TABLE `internal_chat_messages`
  ADD COLUMN `attachment_name` VARCHAR(255) DEFAULT NULL AFTER `body`,
  ADD COLUMN `attachment_url` VARCHAR(500) DEFAULT NULL AFTER `attachment_name`;
