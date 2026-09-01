-- =====================================================================
-- Migration 0.26 — PROFIL CLIENT RICHE
-- =====================================================================
--
-- Enrichit `users` pour stocker les informations collectées à
-- l'inscription (actuellement saisies dans le formulaire mais NON
-- enregistrées en base). L'admin a ainsi suffisamment d'informations
-- sur chaque client.
--
-- Personnes physiques :
--   birth_date            date de naissance
--   gender                genre (optionnel)
--   city                  ville
--   postal_code           code postal
--   address               adresse postale
--
-- Entreprises :
--   company_name          nom de l'entreprise
--   legal_form            forme juridique (SARL, SAS…)
--   company_registration_number   numéro d'immatriculation (RCCM)
--   industry              secteur d'activité
--   company_size          taille (1-10, 11-50…)
--   website               site web
--
-- Toutes les colonnes sont NULLables et NON destructives.
-- =====================================================================

ALTER TABLE `users`
  ADD COLUMN `birth_date` DATE NULL AFTER `country_of_residence`,
  ADD COLUMN `gender` VARCHAR(20) NULL AFTER `birth_date`,
  ADD COLUMN `city` VARCHAR(120) NULL AFTER `gender`,
  ADD COLUMN `postal_code` VARCHAR(20) NULL AFTER `city`,
  ADD COLUMN `address` VARCHAR(255) NULL AFTER `postal_code`,
  ADD COLUMN `company_name` VARCHAR(190) NULL AFTER `address`,
  ADD COLUMN `legal_form` VARCHAR(80) NULL AFTER `company_name`,
  ADD COLUMN `company_registration_number` VARCHAR(80) NULL AFTER `legal_form`,
  ADD COLUMN `industry` VARCHAR(120) NULL AFTER `company_registration_number`,
  ADD COLUMN `company_size` VARCHAR(30) NULL AFTER `industry`,
  ADD COLUMN `website` VARCHAR(190) NULL AFTER `company_size`;
