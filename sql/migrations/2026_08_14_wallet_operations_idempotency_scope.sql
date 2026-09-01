-- Migration: scope de l'idempotence des opérations de wallet par environnement.
--
-- PROBLÈME RÉSOLU (sévérité HIGH)
-- ──────────────────────────────
-- La migration 0.14 a scopé `idempotency_keys` par environnement. Un SECOND
-- espace de noms d'idempotence subsistait, non corrigé :
--
--     wallet_operations : UNIQUE (idempotency_key)
--
-- Cet index est global. Conséquence concrète, reproduite par test :
--
--     1. opération SANDBOX     avec la clé K → wallet_operations.id créé
--     2. opération PRODUCTION  avec la clé K → SQLSTATE[23000] Duplicate entry
--
-- Autrement dit, une opération de test rendait définitivement impossible
-- l'exécution de la même clé en argent réel. La frontière d'environnement
-- était franchie par la contrainte elle-même : un objet sandbox produisait un
-- effet observable — et bloquant — sur la production.
--
-- Le correctif aligne cet index sur celui d'`idempotency_keys` : la clé reste
-- unique DANS son environnement, et les deux environnements ne partagent plus
-- d'espace de noms.
--
-- PORTÉE DÉLIBÉRÉMENT INCHANGÉE PAR AILLEURS
-- ──────────────────────────────────────────
-- L'unicité reste globale (et non par utilisateur) : c'est le comportement
-- historique, les clés étant générées avec un préfixe d'opération. Le
-- restreindre davantage sortirait du périmètre de ce correctif et modifierait
-- une garantie sur laquelle le code existant s'appuie.
--
-- Idempotente : rejouable sans effet de bord (contrôles information_schema).

USE nexus;

-- 1) Nouvel index scopé.
SET @new := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'wallet_operations'
      AND INDEX_NAME   = 'uq_op_idempotency_env'
);
SET @sql := IF(@new = 0,
    'CREATE UNIQUE INDEX uq_op_idempotency_env ON wallet_operations (idempotency_key, environment)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Retrait de l'ancien index global, une fois le nouveau en place.
SET @old := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'wallet_operations'
      AND INDEX_NAME   = 'uq_op_idempotency'
);
SET @sql := IF(@old > 0, 'DROP INDEX uq_op_idempotency ON wallet_operations', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
