-- =============================================================================
-- 0.28 — Support chat (tickets / conversations) avec historique persistant
--
-- Deux tables :
--   * support_conversations : un fil de discussion (ticket) rattaché à un
--     utilisateur client, avec statut et catégorie.
--   * support_messages : les messages du fil, signés par l'auteur
--     (customer_id = client, agent_id = employé/agent qui répond).
--
-- Principes :
--   * Chaque message est horodaté et conservé (rétention + audit réglementaire).
--   * Un client ne voit QUE ses conversations ; un agent/superadmin voit toutes.
--   * Le bot auto peut répondre (is_bot = 1) ; un agent humain peut prendre
--     le relais et répondre en tant qu'employé.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `support_conversations` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL COMMENT 'Client propriétaire du ticket.',
  `subject`      VARCHAR(190) NOT NULL DEFAULT '',
  `category`     VARCHAR(60) DEFAULT NULL COMMENT 'ex : compte, transfert, kyc, facturation, autre',
  `status`       ENUM('open','waiting','resolved','closed') NOT NULL DEFAULT 'open',
  `priority`     ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `assigned_to`  BIGINT UNSIGNED DEFAULT NULL COMMENT 'Employé/agent assigné (nullable).',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supconv_user` (`user_id`),
  KEY `idx_supconv_status` (`status`),
  KEY `idx_supconv_assigned` (`assigned_to`),
  CONSTRAINT `fk_supconv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supconv_agent` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `customer_id`     BIGINT UNSIGNED DEFAULT NULL COMMENT 'Auteur = client (NULL si non-client).',
  `agent_id`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'Auteur = agent/employé (NULL sinon).',
  `is_bot`          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 si message généré par le bot auto.',
  `body`            TEXT NOT NULL,
  `read_at`         DATETIME DEFAULT NULL COMMENT 'Lecture par l''autre partie.',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supmsg_conv` (`conversation_id`,`created_at`),
  KEY `idx_supmsg_customer` (`customer_id`),
  KEY `idx_supmsg_agent` (`agent_id`),
  CONSTRAINT `fk_supmsg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supmsg_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supmsg_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
