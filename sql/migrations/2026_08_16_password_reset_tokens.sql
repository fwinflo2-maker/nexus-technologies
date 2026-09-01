-- =============================================================================
-- 0.27 — Réinitialisation de mot de passe réelle (tokens stockés en base)
--
-- Ajoute la table `password_reset_tokens` pour un flow de mot de passe oublié
-- sécurisé et traçable :
--   * un jeton aléatoire, HACHÉ (jamais stocké en clair),
--   * une expiration (30 minutes),
--   * une consommation unique (used_at),
--   * lier au compte utilisateur (FK users).
--
-- La fonctionnalité n'affiche plus un faux « e-mail envoyé » : elle crée un
-- vrai jeton côté serveur, le persiste, et (selon la capacité d'envoi
-- configurée) l'achemine à l'utilisateur.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 du jeton brut (jamais stocké en clair).',
  `expires_at`  DATETIME NOT NULL COMMENT 'Date d''expiration du jeton.',
  `used_at`     DATETIME DEFAULT NULL COMMENT 'Consommé (NULL tant que non utilisé).',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_token_hash` (`token_hash`),
  KEY `idx_reset_user` (`user_id`),
  KEY `idx_reset_expires` (`expires_at`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
