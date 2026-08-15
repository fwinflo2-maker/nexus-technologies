-- =============================================================================
-- NEXUS — SCHÉMA COMPLET (structure seule)
--
-- Ce fichier permet de reconstruire l'intégralité de la base sur une instance
-- vierge, sans jouer les migrations une par une :
--
--     DROP DATABASE IF EXISTS nexus;
--     CREATE DATABASE nexus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--     mysql nexus < database/full_schema.sql
--
-- IMPORTANT
--   * Fichier GÉNÉRÉ — ne pas éditer à la main.
--     Régénérer avec : bash scripts/build_full_schema.sh
--   * STRUCTURE UNIQUEMENT : aucune donnée métier, aucun solde, aucune
--     transaction, aucun provider actif. Les jeux de démonstration vivent
--     dans database/seeds/ et ne doivent jamais être joués en production.
--   * Équivalence avec le runner de migrations vérifiée par
--     scripts/compare_schemas.sh (tables, colonnes, types, index, clés
--     étrangères, ENUM, valeurs par défaut, nullabilité).
--   * PORTABILITÉ : généré depuis MariaDB, ce dump rendait les colonnes JSON
--     sous leur forme interne MariaDB (longtext + CHECK json_valid). MySQL 8
--     possède un vrai type JSON : les deux chemins d'installation
--     divergeaient donc selon le moteur ayant servi à la génération. Le
--     générateur renormalise ces colonnes en `json`, accepté par les deux.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `environment` enum('sandbox','production') DEFAULT NULL COMMENT 'Environnement de la dÃ©cision. NULL si la demande Ã©tait invalide (aucune valeur valide Ã  consigner).',
  `metadata` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action_time` (`action`,`created_at`),
  KEY `idx_audit_environment` (`environment`,`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `beneficiaries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(190) NOT NULL,
  `country` char(2) NOT NULL,
  `currency` varchar(5) NOT NULL DEFAULT 'XAF',
  `method` enum('mobile_money','bank','crypto','cash_pickup') NOT NULL DEFAULT 'mobile_money',
  `account_reference_enc` text DEFAULT NULL,
  `operator` varchar(50) DEFAULT NULL,
  `bank_name` varchar(190) DEFAULT NULL,
  `status` enum('active','inactive','pending_verification') NOT NULL DEFAULT 'active',
  `verification_status` enum('unverified','verified','rejected') NOT NULL DEFAULT 'unverified',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_beneficiaries_user` (`user_id`,`status`),
  CONSTRAINT `fk_beneficiaries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `connect_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `company_name` varchar(190) NOT NULL,
  `email` varchar(190) NOT NULL,
  `status` enum('active','pending','suspended','closed') NOT NULL DEFAULT 'pending',
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `api_key_hash` varchar(255) DEFAULT NULL,
  `api_key_prefix` varchar(20) DEFAULT NULL,
  `webhook_url` varchar(500) DEFAULT NULL,
  `webhook_secret_enc` text DEFAULT NULL,
  `country` char(2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_connect_email` (`email`),
  UNIQUE KEY `uq_connect_api_key_prefix` (`api_key_prefix`),
  KEY `idx_connect_user` (`user_id`),
  KEY `idx_connect_status` (`status`),
  CONSTRAINT `fk_connect_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `connect_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `connect_account_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_status` varchar(30) DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_connect_events_account` (`connect_account_id`),
  CONSTRAINT `fk_connect_events_account` FOREIGN KEY (`connect_account_id`) REFERENCES `connect_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'operations_manager',
  `permissions` json DEFAULT NULL,
  `status` enum('active','invited','disabled') NOT NULL DEFAULT 'invited',
  `manager_id` bigint(20) unsigned DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_user` (`user_id`),
  KEY `idx_employee_role` (`role`),
  KEY `idx_employee_status` (`status`),
  KEY `fk_employee_manager` (`manager_id`),
  CONSTRAINT `fk_employee_manager` FOREIGN KEY (`manager_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employee_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fx_rates_cache` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `base_currency` varchar(5) NOT NULL,
  `quote_currency` varchar(5) NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `spread_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox' COMMENT 'Environnement du taux. Un taux sandbox ne doit jamais coter de l''argent reel.',
  `fetched_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fx_pair_env_fetched` (`base_currency`,`quote_currency`,`environment`,`fetched_at`),
  KEY `idx_fx_pair_env` (`base_currency`,`quote_currency`,`environment`,`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `idempotency_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(64) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox' COMMENT 'Scope de la clÃ©. Deux environnements ne partagent jamais un espace de noms d''idempotence.',
  `operation_id` varchar(36) DEFAULT NULL,
  `response_json` mediumtext DEFAULT NULL,
  `status` enum('processing','completed','error') NOT NULL DEFAULT 'processing',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idem_key_env` (`idempotency_key`,`user_id`,`environment`),
  KEY `idx_idem_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kyc_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(50) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `subject_type` enum('individual','company') NOT NULL DEFAULT 'individual',
  `applicant_id` varchar(128) NOT NULL,
  `level_name` varchar(100) DEFAULT NULL,
  `status` enum('not_started','in_progress','pending','verified','resubmission_requested','rejected','on_hold') NOT NULL DEFAULT 'not_started',
  `reason` varchar(500) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kyc_user_subject` (`user_id`,`provider`,`environment`,`subject_type`),
  UNIQUE KEY `uq_kyc_applicant` (`provider`,`environment`,`applicant_id`),
  KEY `idx_kyc_status` (`status`),
  CONSTRAINT `fk_kyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kyc_webhook_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `event_id` varchar(191) NOT NULL,
  `applicant_id` varchar(128) DEFAULT NULL,
  `verification_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `processed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kyc_event` (`provider`,`environment`,`event_id`),
  KEY `idx_kyc_event_applicant` (`applicant_id`),
  KEY `fk_kyc_event_verification` (`verification_id`),
  CONSTRAINT `fk_kyc_event_verification` FOREIGN KEY (`verification_id`) REFERENCES `kyc_verifications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledger_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(36) NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `entry_type` enum('debit','credit') NOT NULL,
  `wallet_id` bigint(20) unsigned NOT NULL,
  `wallet_currency` varchar(5) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox' COMMENT 'Environnement de l''Ã©criture comptable. HÃ©ritÃ© de l''opÃ©ration source.',
  `amount` decimal(20,8) NOT NULL,
  `balance_after` decimal(20,8) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` varchar(36) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ledger_operation_sequence` (`operation_id`,`sequence`),
  KEY `idx_ledger_operation` (`operation_id`),
  KEY `idx_ledger_wallet_time` (`wallet_id`,`created_at`),
  KEY `idx_ledger_ref` (`reference_type`,`reference_id`),
  KEY `idx_ledger_environment` (`environment`,`created_at`),
  KEY `fk_ledger_operation_env` (`operation_id`,`environment`),
  CONSTRAINT `fk_ledger_operation_env` FOREIGN KEY (`operation_id`, `environment`) REFERENCES `wallet_operations` (`id`, `environment`) ON DELETE CASCADE,
  CONSTRAINT `fk_ledger_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email_time` (`email`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `title` varchar(190) NOT NULL,
  `message` text DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`,`read_at`),
  KEY `idx_notifications_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` enum('source','destination') NOT NULL,
  `kind` enum('bank_iban','mobile_money','crypto_wallet','card','virtual_iban','cash_pickup') NOT NULL,
  `label` varchar(120) NOT NULL,
  `holder_name` varchar(190) DEFAULT NULL,
  `country` char(2) DEFAULT NULL,
  `currency` varchar(5) DEFAULT NULL,
  `operator` varchar(50) DEFAULT NULL,
  `network` varchar(30) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `iban_enc` text DEFAULT NULL,
  `bic` varchar(20) DEFAULT NULL,
  `phone_enc` text DEFAULT NULL,
  `pan_enc` text DEFAULT NULL,
  `expiry` varchar(7) DEFAULT NULL,
  `address_enc` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `verification_status` enum('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
  `supported_for_transfer` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `provider_slug` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_accounts_user_role` (`user_id`,`role`),
  KEY `idx_accounts_origin` (`user_id`,`role`,`verification_status`,`status`),
  CONSTRAINT `fk_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `beneficiary_id` bigint(20) unsigned DEFAULT NULL,
  `purpose` varchar(120) DEFAULT NULL,
  `source_currency` varchar(5) NOT NULL,
  `dest_currency` varchar(5) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `amount_ref` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fee` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fee_currency` varchar(5) NOT NULL DEFAULT 'EUR',
  `dest_amount` decimal(20,2) DEFAULT NULL,
  `fx_rate` decimal(20,8) DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox' COMMENT 'Environnement d''exÃ©cution rÃ©el du paiement (jamais dÃ©duit d''une credential disponible).',
  `route_id` varchar(10) DEFAULT NULL,
  `destination` varchar(190) DEFAULT NULL,
  `status` enum('draft','pending_approval','approved','executing','completed','failed','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  `transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_payments_user_status` (`user_id`,`status`),
  KEY `idx_payments_beneficiary` (`beneficiary_id`),
  KEY `idx_payments_environment` (`environment`,`created_at`),
  CONSTRAINT `fk_payments_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `provider_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `provider_slug` varchar(50) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `credentials_enc` text DEFAULT NULL,
  `status` enum('not_configured','sandbox_only','active','error') NOT NULL DEFAULT 'not_configured',
  `configured_by` bigint(20) unsigned DEFAULT NULL,
  `last_tested_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `owner_scope` bigint(20) unsigned GENERATED ALWAYS AS (ifnull(`user_id`,0)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider_creds_scope` (`owner_scope`,`provider_slug`,`environment`),
  KEY `idx_provider_creds_user` (`user_id`),
  CONSTRAINT `fk_provider_creds_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotes` (
  `id` varchar(22) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `source_currency` varchar(5) NOT NULL DEFAULT 'EUR',
  `origin_country` char(2) DEFAULT NULL,
  `dest_country` char(2) NOT NULL,
  `dest_currency` varchar(5) NOT NULL DEFAULT 'XAF',
  `receiving_method` varchar(30) NOT NULL DEFAULT 'mobile_money',
  `amount_sent` decimal(20,2) NOT NULL DEFAULT 0.00,
  `objective` varchar(30) NOT NULL DEFAULT 'optimized',
  `routes_json` json NOT NULL,
  `selected_route_id` varchar(10) DEFAULT NULL,
  `status` enum('QUOTED','SELECTED','EXECUTED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'QUOTED',
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox' COMMENT 'Environnement dans lequel la quote a Ã©tÃ© calculÃ©e. ComparÃ© au contexte lors de l''exÃ©cution.',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quotes_user_status` (`user_id`,`status`),
  KEY `idx_quotes_expires` (`expires_at`,`status`),
  KEY `idx_quotes_environment` (`environment`,`created_at`),
  CONSTRAINT `fk_quotes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `transaction_id` bigint(20) unsigned DEFAULT NULL,
  `provider_reference` varchar(190) DEFAULT NULL,
  `expected_amount` decimal(20,2) NOT NULL,
  `actual_amount` decimal(20,2) DEFAULT NULL,
  `currency` varchar(5) NOT NULL,
  `status` enum('pending','matched','unmatched','discrepancy','resolved') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recon_tx` (`transaction_id`),
  KEY `idx_recon_user_status` (`user_id`,`status`),
  CONSTRAINT `fk_recon_tx` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_recon_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revoked_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `jti` char(32) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `revoked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_revoked_tokens_jti` (`jti`),
  KEY `fk_revoked_tokens_user` (`user_id`),
  CONSTRAINT `fk_revoked_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_user_id` bigint(20) unsigned NOT NULL,
  `member_user_id` bigint(20) unsigned NOT NULL,
  `role` enum('owner','admin','finance_manager','accountant','operator','viewer') NOT NULL DEFAULT 'viewer',
  `status` enum('active','invited','disabled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_member` (`business_user_id`,`member_user_id`),
  KEY `idx_team_business` (`business_user_id`),
  KEY `fk_team_member` (`member_user_id`),
  CONSTRAINT `fk_team_business` FOREIGN KEY (`business_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `quote_id` varchar(22) DEFAULT NULL,
  `route_id` varchar(10) DEFAULT NULL,
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
  `dest_amount` decimal(20,2) DEFAULT NULL,
  `dest_currency` varchar(5) DEFAULT NULL,
  `fx_rate` decimal(20,8) DEFAULT NULL,
  `fee` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fee_currency` varchar(5) NOT NULL DEFAULT 'EUR',
  `status` enum('completed','processing','pending','failed','cancelled') NOT NULL DEFAULT 'pending',
  `provider` varchar(50) DEFAULT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox' COMMENT 'Environnement d''exÃ©cution rÃ©el de l''opÃ©ration (jamais dÃ©duit d''une credential disponible).',
  `destination` varchar(190) DEFAULT NULL,
  `execution_time_seconds` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tx_user_created` (`user_id`,`created_at`),
  KEY `idx_tx_user_status` (`user_id`,`status`),
  KEY `idx_transactions_environment` (`environment`,`created_at`),
  CONSTRAINT `fk_tx_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `account_type` enum('personal','business') NOT NULL DEFAULT 'personal',
  `platform_role` enum('user','superadmin','operations_manager','finance_treasury','treasury_manager','compliance_officer','risk_fraud','risk_analyst','provider_manager','customer_support','security_technical','security_admin','technical_admin','business_manager','support_operator','compliance_operator','finance_operator','security_engineer','provider_engineer','backend_engineer','qa_engineer','sre_operator','ai_agent') NOT NULL DEFAULT 'user' COMMENT 'RÃ´le d''exploitation de la plateforme. Distinct de account_type (type de client).',
  `auth_provider` enum('local','google') NOT NULL DEFAULT 'local',
  `provider_id` varchar(191) DEFAULT NULL,
  `status` enum('PENDING','ACTIVE','SUSPENDED','CLOSED') NOT NULL DEFAULT 'PENDING',
  `kyc_level` enum('none','basic','standard','advanced') NOT NULL DEFAULT 'none',
  `country_of_residence` char(2) DEFAULT NULL,
  `kyc_verified_at` datetime DEFAULT NULL,
  `country_of_residence_verified_at` datetime DEFAULT NULL,
  `avatar` text DEFAULT NULL COMMENT 'Image de profil (URL ou data URI). NULL = fallback emoji.',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_provider` (`auth_provider`,`provider_id`),
  KEY `idx_users_phone` (`phone`),
  KEY `idx_users_platform_role` (`platform_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_operations` (
  `id` varchar(36) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` enum('deposit','withdrawal','send','receive','convert','fee','refund','welcome_bonus','hold') NOT NULL,
  `status` enum('initiated','pending','processing','completed','failed','cancelled','reversed') NOT NULL DEFAULT 'initiated',
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox' COMMENT 'Environnement de l''opÃ©ration. Lu depuis la ligne lors de la capture/annulation, jamais recalculÃ©.',
  `expires_at` datetime DEFAULT NULL,
  `source_wallet_id` bigint(20) unsigned DEFAULT NULL,
  `source_currency` varchar(5) DEFAULT NULL,
  `source_amount` decimal(20,8) DEFAULT NULL,
  `dest_wallet_id` bigint(20) unsigned DEFAULT NULL,
  `dest_currency` varchar(5) DEFAULT NULL,
  `dest_amount` decimal(20,8) DEFAULT NULL,
  `fee_amount` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `fee_currency` varchar(5) DEFAULT NULL,
  `fx_rate` decimal(20,8) DEFAULT NULL,
  `fx_source` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `idempotency_key` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_op_idempotency_env` (`idempotency_key`,`environment`),
  UNIQUE KEY `uq_op_id_env` (`id`,`environment`),
  KEY `idx_op_user_status` (`user_id`,`status`),
  KEY `idx_op_user_created` (`user_id`,`created_at`),
  KEY `fk_op_source_wallet` (`source_wallet_id`),
  KEY `fk_op_dest_wallet` (`dest_wallet_id`),
  KEY `idx_wallet_operations_environment` (`environment`,`created_at`),
  CONSTRAINT `fk_op_dest_wallet` FOREIGN KEY (`dest_wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_op_source_wallet` FOREIGN KEY (`source_wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_op_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallets` (
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
  UNIQUE KEY `uq_wallets_user_currency` (`user_id`,`currency`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;


SET FOREIGN_KEY_CHECKS = 1;
