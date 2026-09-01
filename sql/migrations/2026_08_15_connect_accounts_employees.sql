-- =====================================================================
-- Migration 0.25 — NEXUS CONNECT (comptes B2B/API) + EMPLOYÉS INTERNES
-- =====================================================================
--
-- Deux structures nouvelles, conformes à l'architecture cible :
--
-- 1) `connect_accounts` — comptes Nexus Connect (produit B2B/API
--    indépendant). Un compte Connect n'est PAS un compte Personal/Business
--    client : il appartient à une entreprise/fintech qui intègre
--    l'infrastructure Nexus dans sa propre application. Il porte ses
--    propres clés API, webhooks et environnement.
--
-- 2) `employees` — employés internes Nexus (opérations, treasury,
--    compliance, risk, providers, support, security, technical,
--    business manager). Distingue clairement le personnel interne des
--    clients. Porte department, role, status et permissions.
--
-- NON DESTRUCTIF : ne touche à aucune table existante ni donnée.
-- =====================================================================

-- ─────────────────────────────────────────────────────────────────────
-- NEXUS CONNECT — comptes B2B/API
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `connect_accounts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NULL,            -- rattachement optionnel à un utilisateur
  `company_name`  VARCHAR(190) NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `status`        ENUM('active','pending','suspended','closed') NOT NULL DEFAULT 'pending',
  `environment`   ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `api_key_hash`  VARCHAR(255) NULL,               -- hash de la clé API (jamais en clair)
  `api_key_prefix` VARCHAR(20) NULL,               -- préfixe visible (ex. nk_live_…)
  `webhook_url`   VARCHAR(500) NULL,
  `webhook_secret_enc` TEXT NULL,                  -- secret webhook chiffré
  `country`       CHAR(2) NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_connect_email` (`email`),
  UNIQUE KEY `uq_connect_api_key_prefix` (`api_key_prefix`),
  KEY `idx_connect_user` (`user_id`),
  KEY `idx_connect_status` (`status`),
  CONSTRAINT `fk_connect_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des événements Connect (webhooks reçus/émis, usage API).
CREATE TABLE IF NOT EXISTS `connect_events` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `connect_account_id` BIGINT UNSIGNED NOT NULL,
  `event_type`       VARCHAR(50) NOT NULL,         -- api_request, webhook, error…
  `event_status`     VARCHAR(30) NULL,
  `payload`          JSON NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_connect_events_account` (`connect_account_id`),
  CONSTRAINT `fk_connect_events_account` FOREIGN KEY (`connect_account_id`)
    REFERENCES `connect_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- EMPLOYÉS INTERNES NEXUS
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `employees` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,        -- lien vers users (authentification)
  `department`    VARCHAR(100) NULL,               -- Operations, Treasury, Compliance…
  `role`          VARCHAR(50) NOT NULL DEFAULT 'operations_manager', -- platform_role
  `permissions`   JSON NULL,                       -- permissions fines (read/create/update…)
  `status`        ENUM('active','invited','disabled') NOT NULL DEFAULT 'invited',
  `manager_id`    BIGINT UNSIGNED NULL,            -- supérieur hiérarchique (optionnel)
  `last_login_at` DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_user` (`user_id`),
  KEY `idx_employee_role` (`role`),
  KEY `idx_employee_status` (`status`),
  CONSTRAINT `fk_employee_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_manager` FOREIGN KEY (`manager_id`)
    REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
