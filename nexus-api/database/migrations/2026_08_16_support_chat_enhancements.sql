-- =============================================================================
-- 0.29 — Support chat : notes internes, pièces jointes
--
-- Étend support_messages :
--   * is_internal : note visible UNIQUEMENT par les agents (jamais par le client)
--   * attachment_name / attachment_url : pièce jointe optionnelle sur un message
-- =============================================================================

ALTER TABLE `support_messages`
  ADD COLUMN `is_internal` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_bot`,
  ADD COLUMN `attachment_name` VARCHAR(255) DEFAULT NULL AFTER `is_internal`,
  ADD COLUMN `attachment_url` VARCHAR(500) DEFAULT NULL AFTER `attachment_name`;
