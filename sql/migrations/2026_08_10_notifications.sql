-- NEXUS — Migration 0.4 : Centre de notifications
-- À appliquer sur une base déjà initialisée (schema.sql) :
--   mysql -u root nexus < database/migrations/2026_08_10_notifications.sql
-- Compatible MySQL 8+ et MariaDB 10.4+.

USE nexus;

-- --- Étape 1 : table des notifications (créée aussi dans schema.sql) ----------
-- Le centre de notifications s'appuie sur cette table :
--  - type    : transfert | quote | kyc | securite | business | systeme ;
--  - read_at : NULL tant que la notification est non lue.
CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    type       VARCHAR(50) NOT NULL DEFAULT 'systeme',
    title      VARCHAR(190) NOT NULL,
    message    TEXT NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user_read (user_id, read_at),
    KEY idx_notifications_user_created (user_id, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Étape 2 : index de tri pour la liste paginée ------------------------------
-- Ajouté conditionnellement pour les bases créées avant l'introduction de cet
-- index (MySQL 8 ne supporte pas `ADD KEY IF NOT EXISTS`, contrairement à MariaDB).
SET @nexus_idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name   = 'notifications'
      AND index_name   = 'idx_notifications_user_created'
);
SET @nexus_sql = IF(
    @nexus_idx_exists = 0,
    'ALTER TABLE notifications ADD INDEX idx_notifications_user_created (user_id, created_at)',
    'SELECT 1'
);
PREPARE nexus_stmt FROM @nexus_sql;
EXECUTE nexus_stmt;
DEALLOCATE PREPARE nexus_stmt;

-- --- Étape 3 : normalisation des types de notification ------------------------
-- L'ancien type « welcome » est migré vers « systeme » pour correspondre aux
-- types filtrables du centre de notifications.
UPDATE notifications SET type = 'systeme' WHERE type = 'welcome';
