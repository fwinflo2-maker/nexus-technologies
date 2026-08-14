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
ALTER TABLE ledger_entries
    ADD UNIQUE INDEX IF NOT EXISTS uq_ledger_operation_sequence (operation_id, sequence);

-- 2) Séparation stricte sandbox / production -----------------------------
ALTER TABLE provider_credentials
    ADD UNIQUE INDEX IF NOT EXISTS uq_provider_creds_env (user_id, provider_slug, environment);

ALTER TABLE provider_credentials
    DROP INDEX IF EXISTS uq_provider_creds;
