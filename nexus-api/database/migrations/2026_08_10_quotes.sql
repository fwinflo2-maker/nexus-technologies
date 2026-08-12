-- NEXUS — Migration 0.6 : Quotes (moteur de devis & routing)
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.3–0.5) :
--   mysql -u root nexus < database/migrations/2026_08_10_quotes.sql
--
-- Table `quotes` : persiste les devis éphémères (TTL 5 min) avec
-- les 3 routes (A/B/C) proposées par le Routing Engine.
-- Statut : QUOTED → SELECTED → EXECUTED | EXPIRED | CANCELLED.
--
-- Quand l'utilisateur sélectionne une route et confirme (étape 3.3),
-- la quote passe en SELECTED puis est convertie en transaction.

USE nexus;

CREATE TABLE IF NOT EXISTS quotes (
    id                VARCHAR(22)  NOT NULL PRIMARY KEY,  -- UUID court (NX + timestamp + rand)
    user_id           BIGINT UNSIGNED NOT NULL,
    -- Intention source
    source_currency   VARCHAR(5)   NOT NULL DEFAULT 'EUR',
    dest_country      CHAR(2)      NOT NULL,
    dest_currency     VARCHAR(5)   NOT NULL DEFAULT 'XAF',
    receiving_method  VARCHAR(30)  NOT NULL DEFAULT 'mobile_money',
    amount_sent       DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    objective         VARCHAR(30)  NOT NULL DEFAULT 'optimized',
    -- Routes sélectionnables (stockées en JSON)
    routes_json       JSON         NOT NULL,
    -- Route sélectionnée par l'utilisateur (null tant que non choisie)
    selected_route_id VARCHAR(10)  NULL,
    -- Statut lifecycle
    status            ENUM('QUOTED','SELECTED','EXECUTED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'QUOTED',
    -- Expiration
    expires_at        DATETIME     NOT NULL,
    -- Timestamps
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Index
    KEY idx_quotes_user_status (user_id, status),
    KEY idx_quotes_expires     (expires_at, status),
    CONSTRAINT fk_quotes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
