-- NEXUS — Migration 0.39 : messagerie interne des employés
--
-- Chat interne du personnel Nexus (tous les rôles internes + superadmin) :
--   - internal_chats           : fil de discussion (titre, créateur, ticket lié
--                                pour les escalades support) ;
--   - internal_chat_members    : participants + last_read_at (compteur de non-lus
--                                par membre) ;
--   - internal_chat_messages   : messages (sender_id, is_system pour les messages
--                                générés par le système — ex. escalade).
--
-- Une escalade support (support_conversations.assigned_to) crée automatiquement
-- un chat interne lié au ticket (related_conversation_id) entre l'agent et le
-- spécialiste, avec un message système résumant difficulté + motif.
--
-- Additif (nouvelles tables uniquement), réversible (DROP), sans impact sur les
-- lignes existantes. Aucun secret stocké.

USE nexus;

CREATE TABLE IF NOT EXISTS internal_chats (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL DEFAULT '',
  `creator_id` bigint(20) unsigned NOT NULL,
  `related_conversation_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Ticket support lié (escalade).',
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ichat_creator` (`creator_id`),
  KEY `idx_ichat_conv` (`related_conversation_id`),
  CONSTRAINT `fk_ichat_creator` FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ichat_conv` FOREIGN KEY (`related_conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_chat_members (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `last_read_at` datetime DEFAULT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ichat_member` (`chat_id`,`user_id`),
  KEY `idx_ichatmem_user` (`user_id`),
  CONSTRAINT `fk_ichatmem_chat` FOREIGN KEY (`chat_id`) REFERENCES `internal_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ichatmem_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_chat_messages (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) unsigned NOT NULL,
  `sender_id` bigint(20) unsigned NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ichatmsg_chat` (`chat_id`,`id`),
  CONSTRAINT `fk_ichatmsg_chat` FOREIGN KEY (`chat_id`) REFERENCES `internal_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ichatmsg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
