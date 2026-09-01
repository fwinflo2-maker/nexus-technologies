-- Migration: propagation de l'environnement sur tout le cycle financier.
--
-- PROBLÈME RÉSOLU
-- ───────────────
-- La migration précédente (0.13) a tracé l'environnement sur `transactions` et
-- `payments`. Le reste du cycle financier l'ignorait encore :
--
--     quotes             → environment ABSENT
--     wallet_operations  → environment ABSENT
--     ledger_entries     → environment ABSENT
--     transactions       → environment présent
--     payments           → environment présent
--
-- Conséquence : le ledger — la source de vérité comptable — ne savait pas
-- distinguer une écriture de test d'une écriture réelle. Une réconciliation
-- ou un total agrégé additionnait donc les deux. La frontière d'environnement
-- était étanche à l'exécution, mais poreuse à la comptabilité.
--
-- TABLES RETENUES — ET POURQUOI
-- ─────────────────────────────
-- `quotes`            : une quote est une DÉCISION financière persistante
--                       (taux, frais, route, expiration). Elle engage
--                       l'exécution qui la consomme : elle doit donc porter
--                       l'environnement dans lequel elle a été calculée.
-- `wallet_operations` : état financier persistant, cycle de vie long
--                       (hold → capture/release). L'environnement doit être
--                       LU depuis la ligne, jamais recalculé à la capture.
-- `ledger_entries`    : source de vérité comptable. Sans environnement, aucun
--                       total n'est fiable.
-- `idempotency_keys`  : voir section 4 — collision inter-environnement réelle.
-- `audit_logs`        : permet de filtrer et de prouver les décisions par
--                       environnement (colonne NULLABLE, voir section 5).
--
-- TABLES ÉCARTÉES — ET POURQUOI
-- ─────────────────────────────
-- `wallets`      : un wallet est un CONTENANT de solde, pas une opération.
--                  Lui donner un environnement impliquerait des soldes
--                  séparés sandbox/production — un changement de modèle
--                  majeur, non demandé, et qui serait faux à moitié fait.
-- `users`, `beneficiaries`, `team_members` : identité et configuration, pas
--                  d'état financier.
-- `provider_credentials`, `kyc_verifications`, `kyc_webhook_events` :
--                  portent DÉJÀ `environment`.
--
-- STRATÉGIE POUR LES DONNÉES HISTORIQUES
-- ──────────────────────────────────────
-- Deux cas distincts, traités différemment — ne pas confondre reconstruire et
-- supposer :
--
--   1. RECONSTRUCTIBLE : `ledger_entries.operation_id` référence
--      `wallet_operations.id`. L'environnement d'une écriture ancienne est
--      donc DÉDUCTIBLE de son opération source, sans supposition. La
--      migration effectue ce backfill par jointure.
--
--   2. NON RECONSTRUCTIBLE : pour le reste, `DEFAULT 'production'`, comme en
--      0.13. Les lignes existantes ont été créées par un système sans notion
--      d'environnement, dont l'usage nominal est réel. Les marquer `sandbox`
--      reviendrait à déclarer rétroactivement « ceci n'était pas de l'argent
--      réel » : invérifiable, et dangereux dans le mauvais sens. `production`
--      est l'hypothèse prudente — elle ne minimise jamais un mouvement réel.
--
-- Toutes les colonnes sont NOT NULL (sauf `audit_logs`, justifié section 5) :
-- une opération d'argent sans environnement connu ne doit pas exister.
--
-- Idempotente : rejouable sans effet de bord (contrôles information_schema).

USE nexus;

-- ═══════════════════════════════════════════════════════════════════════════
-- 1) quotes.environment
-- ═══════════════════════════════════════════════════════════════════════════
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'quotes'
      AND COLUMN_NAME  = 'environment'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE quotes
        ADD COLUMN environment ENUM(''sandbox'',''production'') NOT NULL DEFAULT ''production''
        COMMENT ''Environnement dans lequel la quote a été calculée. Comparé au contexte lors de l''''exécution.''
        AFTER status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'quotes'
      AND INDEX_NAME   = 'idx_quotes_environment'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_quotes_environment ON quotes (environment, created_at)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════════
-- 2) wallet_operations.environment
-- ═══════════════════════════════════════════════════════════════════════════
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'wallet_operations'
      AND COLUMN_NAME  = 'environment'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE wallet_operations
        ADD COLUMN environment ENUM(''sandbox'',''production'') NOT NULL DEFAULT ''production''
        COMMENT ''Environnement de l''''opération. Lu depuis la ligne lors de la capture/annulation, jamais recalculé.''
        AFTER status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'wallet_operations'
      AND INDEX_NAME   = 'idx_wallet_operations_environment'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_wallet_operations_environment ON wallet_operations (environment, created_at)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════════
-- 3) ledger_entries.environment (+ backfill reconstructible)
-- ═══════════════════════════════════════════════════════════════════════════
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ledger_entries'
      AND COLUMN_NAME  = 'environment'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE ledger_entries
        ADD COLUMN environment ENUM(''sandbox'',''production'') NOT NULL DEFAULT ''production''
        COMMENT ''Environnement de l''''écriture comptable. Hérité de l''''opération source.''
        AFTER wallet_currency',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill NON SUPPOSÉ : l'environnement est déduit de l'opération source.
-- Les écritures sans opération correspondante conservent le défaut prudent.
-- Exécuté uniquement lors de la création de la colonne, pour ne pas écraser
-- des valeurs déjà écrites par l'application lors d'un rejeu.
SET @sql := IF(@col = 0,
    'UPDATE ledger_entries l
        JOIN wallet_operations o ON o.id = l.operation_id
        SET l.environment = o.environment',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ledger_entries'
      AND INDEX_NAME   = 'idx_ledger_environment'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_ledger_environment ON ledger_entries (environment, created_at)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════════
-- 4) idempotency_keys.environment — CORRECTION D'UNE COLLISION RÉELLE
-- ═══════════════════════════════════════════════════════════════════════════
-- La contrainte était UNIQUE(idempotency_key, user_id), sans environnement.
-- Scénario cassé :
--
--     1. appel SANDBOX     avec la clé K  → exécuté, réponse mise en cache
--     2. appel PRODUCTION  avec la clé K  → collision : la réponse SANDBOX
--                                            est retournée, et l'opération
--                                            réelle n'est JAMAIS exécutée
--
-- Le client reçoit un succès pour une opération de production qui n'a pas eu
-- lieu. La clé d'idempotence doit donc être scopée à l'environnement : deux
-- environnements ne peuvent pas partager un espace de noms.
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'idempotency_keys'
      AND COLUMN_NAME  = 'environment'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE idempotency_keys
        ADD COLUMN environment ENUM(''sandbox'',''production'') NOT NULL DEFAULT ''production''
        COMMENT ''Scope de la clé. Deux environnements ne partagent jamais un espace de noms d''''idempotence.''
        AFTER user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Remplacement de la contrainte d'unicité par sa version scopée.
SET @old := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'idempotency_keys'
      AND INDEX_NAME   = 'uq_idem_key'
);
SET @new := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'idempotency_keys'
      AND INDEX_NAME   = 'uq_idem_key_env'
);
SET @sql := IF(@new = 0,
    'CREATE UNIQUE INDEX uq_idem_key_env ON idempotency_keys (idempotency_key, user_id, environment)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@old > 0, 'DROP INDEX uq_idem_key ON idempotency_keys', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════════
-- 5) audit_logs.environment — NULLABLE, et c'est délibéré
-- ═══════════════════════════════════════════════════════════════════════════
-- Contrairement aux tables financières, cette colonne est NULLABLE : un refus
-- `ENVIRONMENT_INVALID` journalise précisément une demande dont
-- l'environnement N'EST PAS une valeur valide de l'ENUM. Forcer une valeur
-- reviendrait à inventer l'information que le refus constate absente.
-- La valeur brute rejetée est conservée dans `metadata`.
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'audit_logs'
      AND COLUMN_NAME  = 'environment'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE audit_logs
        ADD COLUMN environment ENUM(''sandbox'',''production'') NULL DEFAULT NULL
        COMMENT ''Environnement de la décision. NULL si la demande était invalide (aucune valeur valide à consigner).''
        AFTER entity_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'audit_logs'
      AND INDEX_NAME   = 'idx_audit_environment'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_audit_environment ON audit_logs (environment, created_at)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
