-- =============================================================================
-- 0.45 — Confirmation d'adresse e-mail à l'inscription
--
--   * users.email_verified_at : NULL = non confirmé. DEFAULT CURRENT_TIMESTAMP
--     pour que les comptes déjà existants (et les INSERT de tests) restent
--     utilisables. L'inscription passe explicitement NULL.
--   * email_verification_tokens : jeton SHA-256, usage unique, expiration 24 h.
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP
  COMMENT 'NULL = adresse e-mail non confirmée.'
  AFTER `email`;

CREATE TABLE IF NOT EXISTS `email_verification_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 du jeton brut (jamais stocké en clair).',
  `expires_at`  DATETIME NOT NULL COMMENT 'Date d expiration du jeton.',
  `used_at`     DATETIME DEFAULT NULL COMMENT 'Consommé (NULL tant que non utilisé).',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_verify_token_hash` (`token_hash`),
  KEY `idx_verify_user` (`user_id`),
  KEY `idx_verify_expires` (`expires_at`),
  CONSTRAINT `fk_verify_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
