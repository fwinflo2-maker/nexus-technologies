-- Migration: traçabilité de l'environnement d'exécution sur les mouvements d'argent.
--
-- PROBLÈME RÉSOLU
-- ───────────────
-- `kyc_verifications`, `kyc_webhook_events` et `provider_credentials` portent
-- déjà une colonne `environment`. Les tables qui enregistrent des MOUVEMENTS
-- D'ARGENT — `transactions` et `payments` — n'en portaient AUCUNE.
--
-- Conséquence : une transaction exécutée en sandbox était indistinguable, en
-- base, d'une transaction réelle. Impossible de :
--   - auditer a posteriori ce qui a réellement bougé de l'argent ;
--   - réconcilier sans mélanger des opérations de test et de production ;
--   - prouver qu'une opération « de test » n'a pas touché la production.
--
-- Un total agrégé sans filtre d'environnement additionnait donc des montants
-- fictifs et des montants réels.
--
-- CHOIX DE CONCEPTION
-- ───────────────────
-- Défaut `production` — et NON `sandbox` :
--   les lignes existantes ont été créées par un système sans notion
--   d'environnement, dont l'usage nominal est réel. Les marquer `sandbox`
--   reviendrait à déclarer rétroactivement « ceci n'était pas de l'argent
--   réel », affirmation invérifiable et dangereuse. `production` est
--   l'hypothèse prudente : elle ne minimise jamais un mouvement réel.
--
-- La colonne est NOT NULL : une opération d'argent sans environnement connu
-- ne doit pas pouvoir exister.
--
-- Idempotente : rejouable sans effet de bord (contrôle information_schema).

USE nexus;

-- 1) transactions.environment
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'transactions'
      AND COLUMN_NAME  = 'environment'
);
SET @sql := IF(@col = 0,
    "ALTER TABLE transactions
       ADD COLUMN environment ENUM('sandbox','production') NOT NULL DEFAULT 'production'
       COMMENT 'Environnement d''exécution réel de l''opération (jamais déduit d''une credential disponible).'
       AFTER provider",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index : les vues d'audit et de réconciliation filtrent systématiquement
-- par environnement, et ne doivent jamais agréger les deux ensemble.
SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'transactions'
      AND INDEX_NAME   = 'idx_transactions_environment'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_transactions_environment ON transactions (environment, created_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) payments.environment
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND COLUMN_NAME  = 'environment'
);
SET @sql := IF(@col = 0,
    "ALTER TABLE payments
       ADD COLUMN environment ENUM('sandbox','production') NOT NULL DEFAULT 'production'
       COMMENT 'Environnement d''exécution réel du paiement (jamais déduit d''une credential disponible).'
       AFTER provider",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND INDEX_NAME   = 'idx_payments_environment'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_payments_environment ON payments (environment, created_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
