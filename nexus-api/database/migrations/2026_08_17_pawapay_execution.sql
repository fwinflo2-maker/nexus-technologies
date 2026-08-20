-- NEXUS — Migration 0.34 : Exécution provider réelle (destinataire + opération provider)
--
-- Phase 2 — Premier rail réel (pawaPay). Deux besoins d'écriture pour que
-- l'exécution d'un envoi devienne UNE OPÉRATION EXTERNE traçable :
--
--  1. `quotes.destination` — le destinataire (MSISDN pour mobile money) est
--     désormais LIÉ À LA QUOTE au moment de sa création. Sans cette liaison,
--     l'exécution ne saurait pas à qui payer : le numéro saisi à l'étape 1
--     du formulaire disparaissait entre la quote et l'exécution (le champ
--     `destination` de `transactions` n'était qu'un libellé « CM · Mobile
--     Money »). Lier le destinataire à la quote empêche aussi de changer de
--     bénéficiaire entre la cotation et l'exécution.
--
--  2. `transactions.provider_operation_id` + `provider_status` — l'API
--     pawaPay est asynchrone : un payout est d'abord ACCEPTED/ENQUEUED puis
--     évolue vers COMPLETED/FAILED via callback ou polling. La transaction
--     Nexus doit conserver l'identifiant d'opération du provider (payoutId)
--     pour retrouver la ligne depuis un webhook, et son dernier statut connu.
--
-- Idempotente (garde information_schema) : réappliquer est sans effet.

USE nexus;

SET @nx_34a := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes'
      AND COLUMN_NAME = 'destination');
SET @nx_sql_34a := IF(@nx_34a = 0,
    'ALTER TABLE quotes ADD COLUMN destination VARCHAR(190) NULL AFTER receiving_method',
    'DO 0');
PREPARE nx_stmt_34a FROM @nx_sql_34a;
EXECUTE nx_stmt_34a;
DEALLOCATE PREPARE nx_stmt_34a;

SET @nx_34e := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes'
      AND COLUMN_NAME = 'operator');
SET @nx_sql_34e := IF(@nx_34e = 0,
    'ALTER TABLE quotes ADD COLUMN operator VARCHAR(50) NULL AFTER destination',
    'DO 0');
PREPARE nx_stmt_34e FROM @nx_sql_34e;
EXECUTE nx_stmt_34e;
DEALLOCATE PREPARE nx_stmt_34e;

SET @nx_34b := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'provider_operation_id');
SET @nx_sql_34b := IF(@nx_34b = 0,
    'ALTER TABLE transactions ADD COLUMN provider_operation_id VARCHAR(64) NULL AFTER provider',
    'DO 0');
PREPARE nx_stmt_34b FROM @nx_sql_34b;
EXECUTE nx_stmt_34b;
DEALLOCATE PREPARE nx_stmt_34b;

SET @nx_34c := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'provider_status');
SET @nx_sql_34c := IF(@nx_34c = 0,
    'ALTER TABLE transactions ADD COLUMN provider_status VARCHAR(30) NULL AFTER provider_operation_id',
    'DO 0');
PREPARE nx_stmt_34c FROM @nx_sql_34c;
EXECUTE nx_stmt_34c;
DEALLOCATE PREPARE nx_stmt_34c;

-- Index de résolution webhook → transaction (provider + opération provider).
SET @nx_34d := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_tx_provider_op');
SET @nx_sql_34d := IF(@nx_34d = 0,
    'ALTER TABLE transactions ADD KEY idx_tx_provider_op (provider, provider_operation_id)',
    'DO 0');
PREPARE nx_stmt_34d FROM @nx_sql_34d;
EXECUTE nx_stmt_34d;
DEALLOCATE PREPARE nx_stmt_34d;
