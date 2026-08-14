-- NEXUS — Migration 0.9 : Transfer execution (Execution Engine)
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.3–0.8) :
--   mysql -u root nexus < database/migrations/2026_08_14_transfer_execution.sql
--
-- Enrichit la table `transactions` pour rendre chaque transfert exécuté
-- auto-porteur (détail complet sans re-calcul) :
--   - quote_id / route_id : traçabilité vers la quote source (audit)
--   - dest_amount / dest_currency : montant réellement reçu par le bénéficiaire
--   - fx_rate : taux appliqué lors de l'exécution
--
-- Idempotente (ADD COLUMN IF NOT EXISTS) : réappliquer est sans effet.

USE nexus;

ALTER TABLE transactions
    ADD COLUMN IF NOT EXISTS quote_id      VARCHAR(22)   NULL AFTER id,
    ADD COLUMN IF NOT EXISTS route_id      VARCHAR(10)   NULL AFTER quote_id,
    ADD COLUMN IF NOT EXISTS dest_amount   DECIMAL(20,2) NULL AFTER amount_xaf,
    ADD COLUMN IF NOT EXISTS dest_currency VARCHAR(5)    NULL AFTER dest_amount,
    ADD COLUMN IF NOT EXISTS fx_rate       DECIMAL(20,8) NULL AFTER dest_currency;
