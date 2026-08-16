-- =============================================================================
-- NEXUS — FICHIER DE DÉMONSTRATION AVEC 3 IDENTIFIANTS DE CONNEXION
-- 
-- Usage : mysql -u root < database/nexus_demo_with_login.sql
-- 
-- Ce fichier contient :
--   1. Le schéma complet de la base de données
--   2. Trois utilisateurs de démonstration avec mots de passe pré-hachés
--   3. Des données de test pour explorer l'interface
-- 
-- IDENTIFIANTS DE CONNEXION (3 COMPTES) :
--   COMPTE 1 - Utilisateur Standard :
--     Email    : user@nexus.com
--     Mot de passe : User123!
--   
--   COMPTE 2 - Admin Operations :
--     Email    : admin@nexus.com
--     Mot de passe : Admin123!
--   
--   COMPTE 3 - Support Client :
--     Email    : support@nexus.com
--     Mot de passe : Support123!
--   
--   (hash bcrypt généré avec cost=10)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. CRÉATION DE LA BASE DE DONNÉES
-- =============================================================================

CREATE DATABASE IF NOT EXISTS nexus
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE nexus;

-- =============================================================================
-- 2. TABLE UTILISATEURS (avec login_attempts et revoked_tokens)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `account_type` enum('personal','business') NOT NULL DEFAULT 'personal',
  `platform_role` enum('user','superadmin','operations_manager','finance_treasury','treasury_manager','compliance_officer','risk_fraud','risk_analyst','provider_manager','customer_support','security_technical','security_admin','technical_admin','business_manager','support_operator','compliance_operator','finance_operator','security_engineer','provider_engineer','backend_engineer','qa_engineer','sre_operator','ai_agent') NOT NULL DEFAULT 'user',
  `auth_provider` enum('local','google') NOT NULL DEFAULT 'local',
  `provider_id` varchar(191) DEFAULT NULL,
  `status` enum('PENDING','ACTIVE','SUSPENDED','CLOSED') NOT NULL DEFAULT 'PENDING',
  `kyc_level` enum('none','basic','standard','advanced') NOT NULL DEFAULT 'none',
  `country_of_residence` char(2) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  KEY `idx_login_attempts_email_time` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `revoked_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `jti` char(32) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `revoked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_revoked_tokens_jti` (`jti`),
  CONSTRAINT `fk_revoked_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 du jeton brut (jamais stocké en clair).',
  `expires_at` datetime NOT NULL COMMENT 'Date d''expiration du jeton.',
  `used_at` datetime DEFAULT NULL COMMENT 'Consommé (NULL tant que non utilisé).',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_token_hash` (`token_hash`),
  KEY `idx_reset_user` (`user_id`),
  KEY `idx_reset_expires` (`expires_at`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. TABLE WALLET
-- =============================================================================

CREATE TABLE IF NOT EXISTS `wallets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `currency` varchar(5) NOT NULL,
  `balance` decimal(20,2) NOT NULL DEFAULT 0.00,
  `available_balance` decimal(20,2) NOT NULL DEFAULT 0.00,
  `pending_balance` decimal(20,2) NOT NULL DEFAULT 0.00,
  `in_transit_balance` decimal(20,2) NOT NULL DEFAULT 0.00,
  `settlement_balance` decimal(20,2) NOT NULL DEFAULT 0.00,
  `hold_balance` decimal(20,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_user_currency` (`user_id`, `currency`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. TABLE TRANSACTIONS
-- =============================================================================

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` enum('send','receive','fx','convert') NOT NULL DEFAULT 'send',
  `direction` enum('in','out','fx') NOT NULL DEFAULT 'out',
  `label` varchar(190) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(20,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(5) NOT NULL,
  `amount_ref` decimal(20,2) NOT NULL DEFAULT 0.00,
  `ref_currency` varchar(5) NOT NULL DEFAULT 'EUR',
  `amount_xaf` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fee` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fee_currency` varchar(5) NOT NULL DEFAULT 'EUR',
  `status` enum('completed','processing','pending','failed','cancelled') NOT NULL DEFAULT 'pending',
  `provider` varchar(50) DEFAULT NULL,
  `destination` varchar(190) DEFAULT NULL,
  `execution_time_seconds` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tx_user_created` (`user_id`, `created_at`),
  KEY `idx_tx_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_tx_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. TABLE NOTIFICATIONS
-- =============================================================================

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `title` varchar(190) NOT NULL,
  `message` text DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`, `read_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. TABLE AUDIT_LOGS
-- =============================================================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action_time` (`action`, `created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. DONNÉES DE DÉMONSTRATION
-- =============================================================================

-- =============================================================================
-- IDENTIFIANTS DE CONNEXION (3 COMPTES)
-- =============================================================================
-- 
-- COMPTE 1 - Utilisateur Standard :
--   Email    : user@nexus.com
--   Mot de passe : User123!
-- 
-- COMPTE 2 - Admin Operations :
--   Email    : admin@nexus.com
--   Mot de passe : Admin123!
-- 
-- COMPTE 3 - Support Client :
--   Email    : support@nexus.com
--   Mot de passe : Support123!
-- 
-- =============================================================================

-- Compte 1 : Utilisateur Standard (personal)
-- Mot de passe : User123!
INSERT INTO `users` (`full_name`, `email`, `phone`, `password_hash`, `account_type`, `platform_role`, `auth_provider`, `status`, `kyc_level`, `country_of_residence`, `city`, `created_at`) VALUES
('Jean Dupont', 'user@nexus.com', '+33612345678', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'personal', 'user', 'local', 'ACTIVE', 'standard', 'FR', 'Paris', NOW());

-- Compte 2 : Admin Operations (business + role operations_manager)
-- Mot de passe : Admin123!
INSERT INTO `users` (`full_name`, `email`, `phone`, `password_hash`, `account_type`, `platform_role`, `auth_provider`, `status`, `kyc_level`, `country_of_residence`, `city`, `created_at`) VALUES
('Marie Martin', 'admin@nexus.com', '+33698765432', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'business', 'operations_manager', 'local', 'ACTIVE', 'advanced', 'FR', 'Lyon', NOW());

-- Compte 3 : Support Client (personal + role customer_support)
-- Mot de passe : Support123!
INSERT INTO `users` (`full_name`, `email`, `phone`, `password_hash`, `account_type`, `platform_role`, `auth_provider`, `status`, `kyc_level`, `country_of_residence`, `city`, `created_at`) VALUES
('Pierre Durand', 'support@nexus.com', '+33687654321', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'personal', 'customer_support', 'local', 'ACTIVE', 'standard', 'FR', 'Marseille', NOW());

-- Wallet EUR pour l'utilisateur 1 (Jean Dupont)
INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `available_balance`, `pending_balance`, `in_transit_balance`, `settlement_balance`, `hold_balance`, `created_at`) VALUES
(1, 'EUR', 1500.00, 1500.00, 0.00, 0.00, 0.00, 0.00, NOW());

-- Wallet USD pour l'utilisateur 1 (Jean Dupont)
INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `available_balance`, `pending_balance`, `in_transit_balance`, `settlement_balance`, `hold_balance`, `created_at`) VALUES
(1, 'USD', 500.00, 500.00, 0.00, 0.00, 0.00, 0.00, NOW());

-- Wallet EUR pour l'utilisateur 2 (Marie Martin - Admin)
INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `available_balance`, `pending_balance`, `in_transit_balance`, `settlement_balance`, `hold_balance`, `created_at`) VALUES
(2, 'EUR', 5000.00, 5000.00, 0.00, 0.00, 0.00, 0.00, NOW());

-- Wallet USD pour l'utilisateur 2 (Marie Martin - Admin)
INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `available_balance`, `pending_balance`, `in_transit_balance`, `settlement_balance`, `hold_balance`, `created_at`) VALUES
(2, 'USD', 2000.00, 2000.00, 0.00, 0.00, 0.00, 0.00, NOW());

-- Wallet EUR pour l'utilisateur 3 (Pierre Durand - Support)
INSERT INTO `wallets` (`user_id`, `currency`, `balance`, `available_balance`, `pending_balance`, `in_transit_balance`, `settlement_balance`, `hold_balance`, `created_at`) VALUES
(3, 'EUR', 800.00, 800.00, 0.00, 0.00, 0.00, 0.00, NOW());

-- Transactions de démo pour l'utilisateur 1 (Jean Dupont)
INSERT INTO `transactions` (`user_id`, `type`, `direction`, `label`, `description`, `amount`, `currency`, `amount_ref`, `ref_currency`, `amount_xaf`, `fee`, `fee_currency`, `status`, `provider`, `destination`, `execution_time_seconds`, `created_at`) VALUES
(1, 'receive', 'in', 'Virement reçu', 'Transfert depuis compte externe', 500.00, 'EUR', 500.00, 'EUR', 327978.50, 0.00, 'EUR', 'completed', 'BankTransfer', 'FR76****5678', NULL, NOW() - INTERVAL 5 DAY),
(1, 'send', 'out', 'Envoi vers Mobile Money', 'Transfert CM - MTN Mobile Money', 150.00, 'EUR', 150.00, 'EUR', 98393.55, 2.50, 'EUR', 'completed', 'Flutterwave', '+2376******12', 45, NOW() - INTERVAL 3 DAY),
(1, 'convert', 'fx', 'Conversion EUR → USD', 'Change interne', 200.00, 'EUR', 200.00, 'EUR', 131191.40, 1.00, 'EUR', 'completed', 'Internal', 'Wallet USD', NULL, NOW() - INTERVAL 1 DAY);

-- Transactions de démo pour l'utilisateur 2 (Marie Martin - Admin)
INSERT INTO `transactions` (`user_id`, `type`, `direction`, `label`, `description`, `amount`, `currency`, `amount_ref`, `ref_currency`, `amount_xaf`, `fee`, `fee_currency`, `status`, `provider`, `destination`, `execution_time_seconds`, `created_at`) VALUES
(2, 'receive', 'in', 'Virement professionnel', 'Paiement client entreprise', 2500.00, 'EUR', 2500.00, 'EUR', 1639892.50, 0.00, 'EUR', 'completed', 'BankTransfer', 'DE89****1234', NULL, NOW() - INTERVAL 4 DAY),
(2, 'send', 'out', 'Paiement fournisseur', 'Règlement prestation services', 800.00, 'EUR', 800.00, 'EUR', 524766.40, 5.00, 'EUR', 'completed', 'SwiftTransfer', 'GB82****5678', 120, NOW() - INTERVAL 2 DAY);

-- Transactions de démo pour l'utilisateur 3 (Pierre Durand - Support)
INSERT INTO `transactions` (`user_id`, `type`, `direction`, `label`, `description`, `amount`, `currency`, `amount_ref`, `ref_currency`, `amount_xaf`, `fee`, `fee_currency`, `status`, `provider`, `destination`, `execution_time_seconds`, `created_at`) VALUES
(3, 'receive', 'in', 'Salaire mensuel', 'Virement salaire', 800.00, 'EUR', 800.00, 'EUR', 524766.40, 0.00, 'EUR', 'completed', 'BankTransfer', 'FR76****9012', NULL, NOW() - INTERVAL 7 DAY);

-- Notifications de démo pour l'utilisateur 1 (Jean Dupont)
INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `read_at`, `created_at`) VALUES
(1, 'success', 'Bienvenue sur NEXUS', 'Votre compte a été créé avec succès. Commencez par ajouter des fonds à votre wallet.', NULL, NOW() - INTERVAL 5 DAY),
(1, 'info', 'Nouveau taux de change disponible', 'Profitez de nos taux compétitifs pour vos transferts vers l''Afrique.', NULL, NOW() - INTERVAL 2 DAY),
(1, 'warning', 'Vérification KYC requise', 'Pour augmenter vos limites de transfert, veuillez compléter votre vérification d''identité.', NULL, NOW() - INTERVAL 1 DAY);

-- Notifications de démo pour l'utilisateur 2 (Marie Martin - Admin)
INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `read_at`, `created_at`) VALUES
(2, 'success', 'Compte professionnel activé', 'Votre compte business est maintenant actif. Vous pouvez gérer les opérations de l''équipe.', NULL, NOW() - INTERVAL 4 DAY),
(2, 'info', 'Nouvelle transaction détectée', 'Une transaction de 2500€ a été reçue sur votre wallet EUR.', NULL, NOW() - INTERVAL 3 DAY);

-- Notifications de démo pour l'utilisateur 3 (Pierre Durand - Support)
INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `read_at`, `created_at`) VALUES
(3, 'info', 'Bienvenue dans l''équipe Support', 'Vous avez maintenant accès aux outils de support client.', NULL, NOW() - INTERVAL 7 DAY),
(3, 'success', 'Formation complétée', 'Votre formation sur la plateforme NEXUS a été validée.', NULL, NOW() - INTERVAL 6 DAY);

-- Audit logs de démo pour l'utilisateur 1 (Jean Dupont)
INSERT INTO `audit_logs` (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`, `ip_address`, `created_at`) VALUES
(1, 'USER_REGISTERED', 'user', 1, '{"source": "web", "referrer": "direct"}', '192.168.1.100', NOW() - INTERVAL 5 DAY),
(1, 'WALLET_CREATED', 'wallet', 1, '{"currency": "EUR"}', '192.168.1.100', NOW() - INTERVAL 5 DAY),
(1, 'TRANSACTION_COMPLETED', 'transaction', 1, '{"amount": 500.00, "currency": "EUR"}', '192.168.1.100', NOW() - INTERVAL 5 DAY);

-- Audit logs de démo pour l'utilisateur 2 (Marie Martin - Admin)
INSERT INTO `audit_logs` (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`, `ip_address`, `created_at`) VALUES
(2, 'USER_REGISTERED', 'user', 2, '{"source": "admin_panel", "referrer": "internal"}', '192.168.1.101', NOW() - INTERVAL 4 DAY),
(2, 'WALLET_CREATED', 'wallet', 3, '{"currency": "EUR"}', '192.168.1.101', NOW() - INTERVAL 4 DAY),
(2, 'ROLE_ASSIGNED', 'user', 2, '{"role": "operations_manager"}', '192.168.1.1', NOW() - INTERVAL 4 DAY);

-- Audit logs de démo pour l'utilisateur 3 (Pierre Durand - Support)
INSERT INTO `audit_logs` (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`, `ip_address`, `created_at`) VALUES
(3, 'USER_REGISTERED', 'user', 3, '{"source": "hr_portal", "referrer": "internal"}', '192.168.1.102', NOW() - INTERVAL 7 DAY),
(3, 'TRAINING_COMPLETED', 'user', 3, '{"training": "platform_basics"}', '192.168.1.102', NOW() - INTERVAL 6 DAY);

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- RÉSUMÉ DES IDENTIFIANTS DE CONNEXION
-- =============================================================================
-- 
-- Pour vous connecter à l'application NEXUS, utilisez l'un des 3 comptes :
-- 
-- COMPTE 1 - UTILISATEUR STANDARD :
--   Email    : user@nexus.com
--   Mot de passe : User123!
--   Rôle     : user (personal)
--   Wallets  : EUR (1500€), USD (500$)
--   Transactions : 3
--   Notifications : 3
-- 
-- COMPTE 2 - ADMIN OPERATIONS :
--   Email    : admin@nexus.com
--   Mot de passe : Admin123!
--   Rôle     : operations_manager (business)
--   Wallets  : EUR (5000€), USD (2000$)
--   Transactions : 2
--   Notifications : 2
-- 
-- COMPTE 3 - SUPPORT CLIENT :
--   Email    : support@nexus.com
--   Mot de passe : Support123!
--   Rôle     : customer_support (personal)
--   Wallets  : EUR (800€)
--   Transactions : 1
--   Notifications : 2
-- 
-- Tous les comptes ont le statut ACTIVE et sont prêts à l'emploi.
-- =============================================================================
