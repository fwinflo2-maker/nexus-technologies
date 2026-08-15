-- Migration: contraintes d'intégrité (ledger + provider credentials)
-- Idempotente : peut être rejouée sans effet de bord.
--
-- 1) ledger_entries : garantir l'unicité (operation_id, sequence).
--    Sans cette contrainte, une double exécution peut insérer deux fois la même
--    écriture comptable. L'idempotence applicative (uq_op_idempotency) couvre le
--    chemin nominal ; cet index ajoute le garde-fou au niveau base (défense en
--    profondeur), seul niveau qui résiste à un bug applicatif ou à une écriture
--    manuelle. Le code n'utilise que les séquences 1 et 2 par opération
--    (LedgerService::insertLedgerEntry), l'index est donc compatible avec
--    l'existant.
--
-- 2) provider_credentials : l'unicité portait sur (user_id, provider_slug),
--    ce qui rendait IMPOSSIBLE la coexistence des identifiants SANDBOX et
--    PRODUCTION d'un même provider pour un même utilisateur : l'enregistrement
--    des identifiants de production écrasait silencieusement ceux de sandbox
--    (ON DUPLICATE KEY UPDATE). La clé d'unicité doit inclure `environment`.

USE nexus;

-- 1) Unicité des écritures comptables ------------------------------------
SET @nx_20 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries'
      AND INDEX_NAME = 'uq_ledger_operation_sequence');
SET @nx_sql_20 := IF(@nx_20 = 0, 'ALTER TABLE ledger_entries ADD UNIQUE INDEX uq_ledger_operation_sequence (operation_id, sequence)', 'DO 0');
PREPARE nx_stmt_20 FROM @nx_sql_20;
EXECUTE nx_stmt_20;
DEALLOCATE PREPARE nx_stmt_20;

-- 2) Séparation stricte sandbox / production -----------------------------
SET @nx_21 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_credentials'
      AND INDEX_NAME = 'uq_provider_creds_env');
SET @nx_sql_21 := IF(@nx_21 = 0, 'ALTER TABLE provider_credentials ADD UNIQUE INDEX uq_provider_creds_env (user_id, provider_slug, environment)', 'DO 0');
PREPARE nx_stmt_21 FROM @nx_sql_21;
EXECUTE nx_stmt_21;
DEALLOCATE PREPARE nx_stmt_21;

SET @nx_22 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_credentials'
      AND INDEX_NAME = 'uq_provider_creds');
SET @nx_sql_22 := IF(@nx_22 > 0, 'ALTER TABLE provider_credentials DROP INDEX uq_provider_creds', 'DO 0');
PREPARE nx_stmt_22 FROM @nx_sql_22;
EXECUTE nx_stmt_22;
DEALLOCATE PREPARE nx_stmt_22;
