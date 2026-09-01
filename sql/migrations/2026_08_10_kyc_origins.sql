-- NEXUS — Migration 0.6 : KYC Residence & Origines autorisées
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.4/0.5) :
--   mysql -u root nexus < database/migrations/2026_08_10_kyc_origins.sql
--
-- Sépare strictement les trois notions :
--   1. Pays de résidence KYC (users.country_of_residence)
--   2. Pays d'origine des fonds (payment_accounts vérifiés → origines autorisées)
--   3. Pays de destination (couverture providers existante, inchangée)
--
-- Sécurité :
--   - Le pays de résidence provient du KYC validé, pas du formulaire de transfert.
--   - Les origines autorisées sont calculées côté backend à partir des sources
--     de financement réellement vérifiées et autorisées.
--   - Le frontend ne décide jamais des origines disponibles.

USE nexus;

-- ==========================================================================
-- 1. Pays de résidence KYC sur la table users
-- ==========================================================================

SET @nx_4 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'country_of_residence');
SET @nx_sql_4 := IF(@nx_4 = 0, 'ALTER TABLE users ADD COLUMN country_of_residence              CHAR(2)  NULL     AFTER kyc_level', 'DO 0');
PREPARE nx_stmt_4 FROM @nx_sql_4;
EXECUTE nx_stmt_4;
DEALLOCATE PREPARE nx_stmt_4;

SET @nx_5 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'kyc_verified_at');
SET @nx_sql_5 := IF(@nx_5 = 0, 'ALTER TABLE users ADD COLUMN kyc_verified_at                   DATETIME NULL     AFTER country_of_residence', 'DO 0');
PREPARE nx_stmt_5 FROM @nx_sql_5;
EXECUTE nx_stmt_5;
DEALLOCATE PREPARE nx_stmt_5;

SET @nx_6 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'country_of_residence_verified_at');
SET @nx_sql_6 := IF(@nx_6 = 0, 'ALTER TABLE users ADD COLUMN country_of_residence_verified_at  DATETIME NULL     AFTER kyc_verified_at', 'DO 0');
PREPARE nx_stmt_6 FROM @nx_sql_6;
EXECUTE nx_stmt_6;
DEALLOCATE PREPARE nx_stmt_6;

-- ==========================================================================
-- 2. Vérification et statut des sources de financement (payment_accounts)
-- ==========================================================================

SET @nx_7 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_accounts'
      AND COLUMN_NAME = 'verification_status');
SET @nx_sql_7 := IF(@nx_7 = 0, 'ALTER TABLE payment_accounts ADD COLUMN verification_status   ENUM(''unverified'',''pending'',''verified'',''rejected'')
                                          NOT NULL DEFAULT ''unverified''  AFTER is_default', 'DO 0');
PREPARE nx_stmt_7 FROM @nx_sql_7;
EXECUTE nx_stmt_7;
DEALLOCATE PREPARE nx_stmt_7;

SET @nx_8 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_accounts'
      AND COLUMN_NAME = 'supported_for_transfer');
SET @nx_sql_8 := IF(@nx_8 = 0, 'ALTER TABLE payment_accounts ADD COLUMN supported_for_transfer TINYINT(1) NOT NULL DEFAULT 0      AFTER verification_status', 'DO 0');
PREPARE nx_stmt_8 FROM @nx_sql_8;
EXECUTE nx_stmt_8;
DEALLOCATE PREPARE nx_stmt_8;

SET @nx_9 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_accounts'
      AND COLUMN_NAME = 'status');
SET @nx_sql_9 := IF(@nx_9 = 0, 'ALTER TABLE payment_accounts ADD COLUMN status                ENUM(''active'',''inactive'',''suspended'')
                                          NOT NULL DEFAULT ''active''      AFTER supported_for_transfer', 'DO 0');
PREPARE nx_stmt_9 FROM @nx_sql_9;
EXECUTE nx_stmt_9;
DEALLOCATE PREPARE nx_stmt_9;

SET @nx_10 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_accounts'
      AND COLUMN_NAME = 'provider_slug');
SET @nx_sql_10 := IF(@nx_10 = 0, 'ALTER TABLE payment_accounts ADD COLUMN provider_slug         VARCHAR(50) NULL                    AFTER status', 'DO 0');
PREPARE nx_stmt_10 FROM @nx_sql_10;
EXECUTE nx_stmt_10;
DEALLOCATE PREPARE nx_stmt_10;

SET @nx_11 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_accounts'
      AND INDEX_NAME = 'idx_accounts_origin');
SET @nx_sql_11 := IF(@nx_11 = 0, 'ALTER TABLE payment_accounts ADD INDEX idx_accounts_origin (user_id, role, verification_status, status)', 'DO 0');
PREPARE nx_stmt_11 FROM @nx_sql_11;
EXECUTE nx_stmt_11;
DEALLOCATE PREPARE nx_stmt_11;

-- ==========================================================================
-- 3. Mise à jour des comptes de démonstration existants
--    → marqués comme vérifiés, actifs, supportés pour transfert
-- ==========================================================================

UPDATE payment_accounts
SET verification_status   = 'verified',
    supported_for_transfer = 1,
    status                = 'active'
WHERE role = 'source'
  AND verification_status = 'unverified'
  AND supported_for_transfer = 0;

-- ==========================================================================
-- 4. Colonne origin_country sur la table quotes
--    Permet de persister le pays d'origine des fonds sélectionné
-- ==========================================================================

SET @nx_12 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes'
      AND COLUMN_NAME = 'origin_country');
SET @nx_sql_12 := IF(@nx_12 = 0, 'ALTER TABLE quotes ADD COLUMN origin_country CHAR(2) NULL AFTER source_currency', 'DO 0');
PREPARE nx_stmt_12 FROM @nx_sql_12;
EXECUTE nx_stmt_12;
DEALLOCATE PREPARE nx_stmt_12;

-- ==========================================================================
-- 5. Données de démonstration : pays de résidence KYC
--    Pour les utilisateurs existants (démo), on définit le Congo (CG)
--    comme pays de résidence vérifié.
-- ==========================================================================

UPDATE users
SET country_of_residence = 'CG',
    kyc_verified_at = NOW(),
    country_of_residence_verified_at = NOW()
WHERE country_of_residence IS NULL
  AND kyc_level != 'none';

-- ==========================================================================
-- 6. Origine de démonstration — DÉPLACÉE
--
-- Cette migration insérait un compte source « Mobile Money Ghana — MTN »
-- marqué `verified` pour un maximum de 10 utilisateurs réels. Une migration
-- de structure ne doit pas créer de source de financement vérifiée : une
-- source vérifiée autorise des transferts (§4), et une donnée de démo n'a
-- rien à faire dans un schéma de production (§8).
--
-- Le jeu de démonstration vit désormais dans :
--     database/seeds/demo_payment_accounts.sql   (SANDBOX / DEVELOPMENT ONLY)
-- ==========================================================================
