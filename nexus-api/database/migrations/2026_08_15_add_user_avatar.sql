-- =====================================================================
-- Migration 0.22 — IMAGE DE PROFIL (avatar) DES UTILISATEURS
-- =====================================================================
--
-- Ajoute une colonne `avatar` sur `users` pour stocker l'image de profil
-- de l'utilisateur ou de l'entreprise.
--
-- FORMAT :
--   NULL        → aucun avatar (fallback emoji 👤 / 🏢)
--   URL publique → http(s)://…  (hébergée, ex. Cloudinary / S3)
--   Data URI    → data:image/png;base64,… (petites images, encodées en base64)
--
-- Le champ est délibérément TEXT (nullable), pas de fichier stocké côté
-- serveur : l'image est soit une URL distante, soit une data URI encodée
-- par le navigateur avant envoi. Aucun upload de fichier binaire sur le
-- serveur PHP (pas d'endpoint de stockage ajouté).
--
-- NON DESTRUCTIF : ne touche à aucune donnée existante.
-- =====================================================================

ALTER TABLE `users`
  ADD COLUMN `avatar` TEXT NULL DEFAULT NULL COMMENT 'Image de profil (URL ou data URI). NULL = fallback emoji.' AFTER `country_of_residence_verified_at`;
