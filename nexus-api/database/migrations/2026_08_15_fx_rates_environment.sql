-- =====================================================================
-- Migration 0.20 — LE CACHE FX EST ISOLÉ PAR ENVIRONNEMENT
-- =====================================================================
--
-- LE DÉFAUT
-- ─────────
-- `fx_rates_cache` ne portait aucune notion d'environnement :
--
--     KEY idx_fx_pair (base_currency, quote_currency, fetched_at)
--
-- `FXRateCache::lookup()` filtrait sur la seule paire de devises et prenait
-- l'entrée la plus récente non expirée. Toutes les couches financières du
-- projet sont pourtant scopées (ledger_entries, quotes, payments,
-- transactions, wallet_operations, fiabilité, latence) — le cache FX était
-- le dernier maillon partagé.
--
-- CE N'ÉTAIT PAS THÉORIQUE. Vérifié en HTTP réel avant correctif :
--
--   1. un taux `EUR→XAF = 100` de source « audit_sandbox » a été servi tel
--      quel à une quote demandée en PRODUCTION — de l'argent réel coté sur
--      une donnée de test ;
--   2. dans l'autre sens, un taux « audit_production » à 200 a été servi en
--      sandbox, sur Send COMME sur Convert.
--
-- La contamination était donc bidirectionnelle et touchait les deux chemins
-- financiers. Un seeder de démonstration (`database/seeds/demo_fx_rates.sql`)
-- alimente cette même table, ce qui rendait le risque concret.
--
-- LA CORRECTION
-- ─────────────
--   1. colonne `environment` ENUM('sandbox','production') NOT NULL ;
--   2. défaut « sandbox », jamais « production » — même principe que la
--      migration 0.19 : un oubli doit produire une donnée de test, pas une
--      donnée d'argent réel ;
--   3. index remplacé par un index scopé, pour que la recherche d'un taux ne
--      puisse plus traverser les environnements ;
--   4. unicité (base, quote, environment, fetched_at) : la table conservait
--      un historique sans contrainte, deux taux identiques au même instant
--      pouvaient coexister et le « dernier » gagnait arbitrairement.
--
-- LES LIGNES EXISTANTES
-- ─────────────────────
-- Elles reçoivent « sandbox ». C'est le choix prudent : un taux déjà présent
-- a été inséré sans intention d'environnement, le promouvoir en production
-- reviendrait à décider qu'il peut coter de l'argent réel. En production, un
-- taux absent provoque désormais un refus explicite — visible et corrigeable
-- — là où un taux hérité à tort serait silencieux.
--
-- PORTABILITÉ
-- ───────────
-- Motif information_schema + PREPARE (aucune syntaxe MariaDB-only : ni
-- `ADD COLUMN IF NOT EXISTS`, ni `PERSISTENT`). Idempotente : rejouable sans
-- effet de bord.
-- =====================================================================

USE nexus;

-- 1) Colonne `environment`, défaut sûr.
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'fx_rates_cache'
       AND COLUMN_NAME  = 'environment'
);

SET @sql := IF(@col = 0,
    "ALTER TABLE fx_rates_cache
       ADD COLUMN environment ENUM('sandbox','production')
       NOT NULL DEFAULT 'sandbox'
       COMMENT 'Environnement du taux. Un taux sandbox ne doit jamais coter de l''argent reel.'
       AFTER source",
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Index de recherche scopé par environnement.
--    L'ancien index (base, quote, fetched_at) laissait la recherche
--    traverser les environnements : il est remplacé, pas complété.
SET @idx_old := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'fx_rates_cache'
       AND INDEX_NAME   = 'idx_fx_pair'
);

SET @sql := IF(@idx_old > 0,
    'ALTER TABLE fx_rates_cache DROP INDEX idx_fx_pair',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_new := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'fx_rates_cache'
       AND INDEX_NAME   = 'idx_fx_pair_env'
);

SET @sql := IF(@idx_new = 0,
    'CREATE INDEX idx_fx_pair_env
        ON fx_rates_cache (base_currency, quote_currency, environment, fetched_at)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Unicité de la cotation à un instant donné, par environnement.
SET @uq := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'fx_rates_cache'
       AND INDEX_NAME   = 'uq_fx_pair_env_fetched'
);

SET @sql := IF(@uq = 0,
    'ALTER TABLE fx_rates_cache
        ADD UNIQUE KEY uq_fx_pair_env_fetched
        (base_currency, quote_currency, environment, fetched_at)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
