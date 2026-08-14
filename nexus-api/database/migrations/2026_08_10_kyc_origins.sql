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

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS country_of_residence              CHAR(2)  NULL     AFTER kyc_level,
    ADD COLUMN IF NOT EXISTS kyc_verified_at                   DATETIME NULL     AFTER country_of_residence,
    ADD COLUMN IF NOT EXISTS country_of_residence_verified_at  DATETIME NULL     AFTER kyc_verified_at;

-- ==========================================================================
-- 2. Vérification et statut des sources de financement (payment_accounts)
-- ==========================================================================

ALTER TABLE payment_accounts
    ADD COLUMN IF NOT EXISTS verification_status   ENUM('unverified','pending','verified','rejected')
                                          NOT NULL DEFAULT 'unverified'  AFTER is_default,
    ADD COLUMN IF NOT EXISTS supported_for_transfer TINYINT(1) NOT NULL DEFAULT 0      AFTER verification_status,
    ADD COLUMN IF NOT EXISTS status                ENUM('active','inactive','suspended')
                                          NOT NULL DEFAULT 'active'      AFTER supported_for_transfer,
    ADD COLUMN IF NOT EXISTS provider_slug         VARCHAR(50) NULL                    AFTER status,
    ADD INDEX IF NOT EXISTS idx_accounts_origin (user_id, role, verification_status, status);

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

ALTER TABLE quotes
    ADD COLUMN IF NOT EXISTS origin_country CHAR(2) NULL AFTER source_currency;

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
