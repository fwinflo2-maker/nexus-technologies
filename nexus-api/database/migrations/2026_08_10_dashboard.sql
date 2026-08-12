-- NEXUS — Migration 0.3 : Dashboard (wallets multi-états + table transactions)
-- À appliquer sur une base déjà initialisée (schema.sql) :
--   mysql -u root nexus < database/migrations/2026_08_10_dashboard.sql
-- Compatible MySQL 8+ et MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).

USE nexus;

-- --- Étape 1 : soldes par état sur les wallets ---------------------------------
-- Les colonnes restent distinctes du solde comptable `balance` :
--  - available_balance : disponible immédiatement ;
--  - pending_balance   : en attente (créancier) ;
--  - in_transit_balance : fonds en transit vers/depuis un provider ;
--  - settlement_balance : en cours de règlement (settlement).
ALTER TABLE wallets
    ADD COLUMN IF NOT EXISTS pending_balance     DECIMAL(20,2) NOT NULL DEFAULT 0.00 AFTER available_balance,
    ADD COLUMN IF NOT EXISTS in_transit_balance  DECIMAL(20,2) NOT NULL DEFAULT 0.00 AFTER pending_balance,
    ADD COLUMN IF NOT EXISTS settlement_balance  DECIMAL(20,2) NOT NULL DEFAULT 0.00 AFTER in_transit_balance;

-- --- Étape 1 bis : devises stables (USDT/USDC = 4 caractères) ------------------
ALTER TABLE wallets MODIFY currency VARCHAR(5) NOT NULL;

-- --- Étape 2 : table des transactions -------------------------------------------
-- Source de vérité des agrégats du dashboard (KPIs, graphique, activité récente).
-- `amount` : montant dans la devise de la transaction.
-- `amount_ref` : équivalent en devise de référence (EUR) au moment de l'exécution.
-- `amount_xaf` : équivalent en XAF (utilisé pour le KPI Volume total).
CREATE TABLE IF NOT EXISTS transactions (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT UNSIGNED NOT NULL,
    type                   ENUM('send','receive','fx','convert') NOT NULL DEFAULT 'send',
    direction              ENUM('in','out','fx') NOT NULL DEFAULT 'out',
    label                  VARCHAR(190) NOT NULL,
    description            VARCHAR(255) NULL,
    amount                 DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    currency               VARCHAR(5) NOT NULL,
    amount_ref             DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    ref_currency           VARCHAR(5) NOT NULL DEFAULT 'EUR',
    amount_xaf             DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    fee                    DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    fee_currency           VARCHAR(5) NOT NULL DEFAULT 'EUR',
    status                 ENUM('completed','processing','pending','failed','cancelled') NOT NULL DEFAULT 'pending',
    provider               VARCHAR(50) NULL,
    destination            VARCHAR(190) NULL,
    execution_time_seconds INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tx_user_created (user_id, created_at),
    KEY idx_tx_user_status  (user_id, status),
    CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
