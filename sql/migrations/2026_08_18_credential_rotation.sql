-- NEXUS — Migration 0.37 : rotation des credentials providers
--
-- La rotation (§29) exige que l'ANCIENNE credential reste disponible
-- jusqu'à validation de la NOUVELLE : staged → test → activate → old revoked.
--
-- La table `credential_rotations` porte :
--   - les credentials NOUVELLES en attente (status 'staged'), testables sans
--     toucher à la ligne active ;
--   - l'HISTORIQUE des rotations : chaque ancienne valeur est archivée
--     (status 'revoked') avant promotion, et chaque nouvelle valeur activée
--     reste tracée (status 'active').
--
-- Jamais de secret en clair : credentials_enc chiffré AES-256-GCM, même
-- chemin que provider_credentials.
--
-- Additif (nouvelle table uniquement), réversible (DROP de la table), sans
-- impact sur les lignes existantes.

USE nexus;

CREATE TABLE IF NOT EXISTS credential_rotations (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider_slug` varchar(50) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `credentials_enc` text DEFAULT NULL,
  `status` enum('staged','active','revoked') NOT NULL DEFAULT 'staged',
  `configured_by` bigint(20) unsigned DEFAULT NULL,
  `last_tested_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `activated_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rotation_provider` (`provider_slug`,`environment`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
