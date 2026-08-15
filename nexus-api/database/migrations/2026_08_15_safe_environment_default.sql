-- =====================================================================
-- Migration 0.19 — LE DÉFAUT D'ENVIRONNEMENT DEVIENT « SANDBOX »
-- =====================================================================
--
-- LE DÉFAUT
-- ─────────
-- Six tables financières portaient `environment ... DEFAULT 'production'` :
--
--     idempotency_keys · ledger_entries · payments
--     quotes · transactions · wallet_operations
--
-- Conséquence : tout INSERT omettant la colonne créait de l'ARGENT RÉEL.
-- L'oubli le plus banal — une colonne manquante dans une requête — produit
-- silencieusement la conséquence la plus grave.
--
-- Ce n'était pas théorique. `AuthController::seedDemoTransactions()` omettait
-- la colonne : après une simple inscription, 5 transactions de démonstration
-- (« Réception SEPA », « Envoi Mobile Money »…) étaient marquées `production`
-- et apparaissaient dans les vues et totaux d'argent réel. Vérifié en base
-- avant correctif, corrigé dans le même lot.
--
-- LE PRINCIPE
-- ───────────
-- Un défaut doit toujours pencher du côté le moins dangereux. En cas
-- d'oubli, mieux vaut une opération classée « test » — visible, corrigeable,
-- sans conséquence financière — qu'une opération classée « argent réel ».
--
-- La production doit être DEMANDÉE, jamais héritée.
--
-- Cela ne remplace aucune garantie applicative : tous les chemins financiers
-- passent déjà `environment` explicitement depuis l'ExecutionContext. C'est
-- une défense de dernier recours, pour le jour où un chemin l'oubliera.
--
-- CE QUE CETTE MIGRATION NE FAIT PAS
-- ──────────────────────────────────
-- Elle ne TOUCHE AUCUNE LIGNE EXISTANTE. Changer l'environnement d'une
-- opération déjà persistée violerait l'invariant « une valeur persistée fait
-- autorité » : une transaction réelle doit le rester, même si elle a été
-- créée sous l'ancien défaut. Seul le comportement des FUTURS inserts change.
--
-- `audit_logs.environment` reste NULL par défaut : un événement
-- d'authentification n'appartient légitimement à aucun environnement, et lui
-- en inventer un serait falsifier le journal.
--
-- IDEMPOTENCE
-- ───────────
-- `ALTER TABLE ... ALTER COLUMN ... SET DEFAULT` est naturellement
-- réentrant : réappliquer le même défaut n'a aucun effet.
-- =====================================================================

USE nexus;

ALTER TABLE transactions      ALTER COLUMN environment SET DEFAULT 'sandbox';
ALTER TABLE payments          ALTER COLUMN environment SET DEFAULT 'sandbox';
ALTER TABLE quotes            ALTER COLUMN environment SET DEFAULT 'sandbox';
ALTER TABLE wallet_operations ALTER COLUMN environment SET DEFAULT 'sandbox';
ALTER TABLE ledger_entries    ALTER COLUMN environment SET DEFAULT 'sandbox';
ALTER TABLE idempotency_keys  ALTER COLUMN environment SET DEFAULT 'sandbox';
