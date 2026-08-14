-- NEXUS — Migration 0.7 : Wallet Core (fondation comptable)
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.3–0.6) :
--   mysql -u root nexus < database/migrations/2026_08_10_wallet_core.sql
-- Compatible MySQL 8+ et MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
--
-- Cette migration pose les fondations comptables du Wallet NEXUS :
--   1. hold_balance sur wallets          → distinction fonds gelés / disponibles
--   2. ledger_entries                    → source de vérité comptable (double-entrée)
--   3. wallet_operations                 → cycle de vie métier (deposit, send, etc.)
--   4. idempotency_keys                   → prévention des doubles soumissions
--   5. fx_rates_cache                    → cache des taux FX (remplace les taux hardcodés)
--
-- Principes :
--   - Le Ledger est la source de vérité (aucune écriture directe sur wallets.balance).
--   - available_balance = balance - hold_balance (calcul garanti par migration).
--   - Aucune devise ni aucun pays n'est codé comme défaut ; tout provient de la configuration.
--   - Les nouvelles structures utilisent DECIMAL(20,8) pour la précision crypto ;
--     les colonnes existantes de wallets conservent DECIMAL(20,2) (projection).

USE nexus;

-- ==========================================================================
-- 1. hold_balance sur wallets
-- ==========================================================================
-- Séparation explicite entre fonds disponibles et fonds gelés (holds).
-- available_balance est conservé comme colonne calculée à l'inscription ;
-- la cohérence hold + soldes par état est garantie par recalcul ci-dessous.
ALTER TABLE wallets
    ADD COLUMN IF NOT EXISTS hold_balance DECIMAL(20,2) NOT NULL DEFAULT 0.00 AFTER settlement_balance;

-- Recalcul rétro-actif : hold = pending + in_transit + settlement,
-- available = balance - hold.
-- Ne s'applique qu'aux wallets existants (idempotent : WHERE > 0).
UPDATE wallets
SET hold_balance = pending_balance + in_transit_balance + settlement_balance,
    available_balance = balance - (pending_balance + in_transit_balance + settlement_balance)
WHERE pending_balance > 0 OR in_transit_balance > 0 OR settlement_balance > 0;

-- ==========================================================================
-- 2. ledger_entries — Source de vérité comptable (double-entrée)
-- ==========================================================================
-- Chaque opération financière produit au minimum 2 écritures liées par
-- operation_id (UUID). balance_after est le solde du wallet APRÈS écriture,
-- calculé sous verrou SELECT ... FOR UPDATE.
--
-- entry_type :
--   'debit'  → sortie de fonds pour ce wallet
--   'credit' → entrée de fonds pour ce wallet
--
-- amount est toujours positif (le sens est dans entry_type).
CREATE TABLE IF NOT EXISTS ledger_entries (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id    VARCHAR(36)  NOT NULL,                       -- UUID de l'opération groupée
    sequence        INT UNSIGNED NOT NULL,                       -- Ordre dans l'opération (1, 2, 3...)
    entry_type      ENUM('debit','credit') NOT NULL,
    wallet_id       BIGINT UNSIGNED NOT NULL,
    wallet_currency VARCHAR(5)   NOT NULL,                       -- Redondant (évite JOIN)
    amount          DECIMAL(20,8) NOT NULL,                      -- Toujours positif
    balance_after   DECIMAL(20,8) NOT NULL,                      -- Solde wallet APRÈS écriture
    description     VARCHAR(255) NULL,
    reference_type  VARCHAR(50)  NULL,                           -- 'deposit','send','fee','welcome_bonus', etc.
    reference_id    VARCHAR(36)  NULL,                           -- ID wallet_operations ou autre
    metadata        JSON         NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_ledger_operation (operation_id),
    KEY idx_ledger_wallet_time (wallet_id, created_at),
    KEY idx_ledger_ref (reference_type, reference_id),
    CONSTRAINT fk_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 3. wallet_operations — Cycle de vie métier des opérations
-- ==========================================================================
-- Objet métier suivi par le code applicatif. Chaque opération est tracée
-- depuis sa création (initiated) jusqu'à son terme (completed/failed/cancelled).
-- Le idempotency_key empêche les doubles soumissions.
--
-- type :
--   'deposit'        → entrée de fonds
--   'withdrawal'     → sortie de fonds
--   'send'           → envoi vers un tiers
--   'receive'        → réception d'un tiers
--   'convert'        → conversion intra-wallet (EUR→USD)
--   'fee'            → frais prélevés
--   'refund'         → remboursement
--   'welcome_bonus'  → crédit initial (inscription / démo)
CREATE TABLE IF NOT EXISTS wallet_operations (
    id               VARCHAR(36)  NOT NULL PRIMARY KEY,           -- UUID
    user_id          BIGINT UNSIGNED NOT NULL,
    type             ENUM('deposit','withdrawal','send','receive','convert','fee','refund','welcome_bonus') NOT NULL,
    status           ENUM('initiated','pending','processing','completed','failed','cancelled','reversed') NOT NULL DEFAULT 'initiated',
    source_wallet_id BIGINT UNSIGNED NULL,
    source_currency  VARCHAR(5)   NULL,
    source_amount    DECIMAL(20,8) NULL,
    dest_wallet_id   BIGINT UNSIGNED NULL,
    dest_currency    VARCHAR(5)   NULL,
    dest_amount      DECIMAL(20,8) NULL,
    fee_amount       DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    fee_currency     VARCHAR(5)   NULL,
    fx_rate          DECIMAL(20,8) NULL,
    fx_source        VARCHAR(50)  NULL,                           -- 'manual','ecb','fixer','provider_slug'
    description      VARCHAR(255) NULL,
    metadata         JSON         NULL,
    idempotency_key  VARCHAR(64)  NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at     DATETIME NULL,

    UNIQUE KEY uq_op_idempotency (idempotency_key),
    KEY idx_op_user_status (user_id, status),
    KEY idx_op_user_created (user_id, created_at),
    CONSTRAINT fk_op_user          FOREIGN KEY (user_id)          REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_op_source_wallet FOREIGN KEY (source_wallet_id) REFERENCES wallets (id) ON DELETE SET NULL,
    CONSTRAINT fk_op_dest_wallet   FOREIGN KEY (dest_wallet_id)   REFERENCES wallets (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 4. idempotency_keys — Prévention des doubles soumissions
-- ==========================================================================
-- Toute opération financière porte une clé d'idempotence. La première
-- exécution crée l'entrée (status=processing), stocke la réponse, puis
-- passe en completed. Les requêtes suivantes retournent la réponse cachée
-- sans ré-exécuter l'opération.
--
-- TTL : géré via expires_at (24 h par défaut — appliqué par le code applicatif).
CREATE TABLE IF NOT EXISTS idempotency_keys (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(64)  NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    operation_id    VARCHAR(36)  NULL,                            -- Lien vers wallet_operations.id
    response_json   MEDIUMTEXT   NULL,                            -- Réponse cachée (première exécution)
    status          ENUM('processing','completed','error') NOT NULL DEFAULT 'processing',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,

    UNIQUE KEY uq_idem_key (idempotency_key, user_id),
    KEY idx_idem_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 5. fx_rates_cache — Cache des taux de change FX
-- ==========================================================================
-- Remplace les taux hardcodés dans Currency.php, QuoteEngine, IntentEngine.
-- Source initiale : 'manual' (taux de démo insérés ci-dessous).
-- En Phase 2, un ApiRateProvider peuplera cette table avec TTL configurable.
--
-- Lecture typique :
--   SELECT rate FROM fx_rates_cache
--   WHERE base_currency = ? AND quote_currency = ?
--   ORDER BY fetched_at DESC LIMIT 1;
CREATE TABLE IF NOT EXISTS fx_rates_cache (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_currency   VARCHAR(5)   NOT NULL,
    quote_currency  VARCHAR(5)   NOT NULL,
    rate            DECIMAL(20,8) NOT NULL,
    spread_pct      DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    source          VARCHAR(50)  NOT NULL DEFAULT 'manual',
    fetched_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,

    KEY idx_fx_pair (base_currency, quote_currency, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- Seed FX de démonstration — DÉPLACÉ
--
-- Cette migration préchargeait 12 taux de change dans `fx_rates_cache` avec
-- un TTL de 24 h. Ce sont des données d'exploitation, pas de la structure :
-- en production, le cache doit être alimenté par une source de taux réelle,
-- pas par des valeurs figées dans une migration (§8).
--
-- Aucune régression fonctionnelle : `ManualRateProvider` fournit déjà ces
-- mêmes taux en PHP lorsque le cache ne contient pas la paire demandée.
--
-- Jeu de démonstration : database/seeds/demo_fx_rates.sql  (SANDBOX ONLY)
-- ==========================================================================
