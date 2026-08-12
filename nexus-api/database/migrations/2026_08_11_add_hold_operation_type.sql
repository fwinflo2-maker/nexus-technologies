-- NEXUS — Migration 0.8 : Hold Lifecycle (Phase F)
-- À appliquer sur une base déjà initialisée (schema.sql + migrations 0.3–0.7) :
--   mysql -u root nexus < database/migrations/2026_08_11_add_hold_operation_type.sql
--
-- Ajoute la valeur 'hold' à l'ENUM wallet_operations.type pour permettre
-- la représentation explicite des réservations de fonds (hold lifecycle :
-- create → capture / release).
--
-- Aucune autre colonne ni aucune autre table n'est modifiée.
-- Idempotente : réappliquer cette migration est sans effet.
USE nexus;

ALTER TABLE wallet_operations
    MODIFY COLUMN type ENUM(
        'deposit',
        'withdrawal',
        'send',
        'receive',
        'convert',
        'fee',
        'refund',
        'welcome_bonus',
        'hold'
    ) NOT NULL;
