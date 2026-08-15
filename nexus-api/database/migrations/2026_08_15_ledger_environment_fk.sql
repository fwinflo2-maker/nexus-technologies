-- =====================================================================
-- Migration 0.18 — L'ÉCRITURE COMPTABLE NE PEUT PLUS CHANGER D'ENVIRONNEMENT
-- =====================================================================
--
-- LE DÉFAUT
-- ─────────
-- `ledger_entries.environment` et `wallet_operations.environment` existaient
-- tous les deux, mais RIEN ne les liait. La base acceptait sans broncher :
--
--     wallet_operations: id='op-x'  environment='sandbox'
--     ledger_entries:    operation_id='op-x'  environment='production'
--
-- Vérifié par insertion réelle avant cette migration : l'incohérence était
-- persistée. Une écriture comptable d'argent réel pouvait ainsi se rattacher
-- à une opération de test — ou l'inverse. Les garanties applicatives
-- existaient, mais rien ne protégeait contre un script de maintenance, un
-- correctif manuel en base, ou une future méthode oublieuse.
--
-- POURQUOI UNE CONTRAINTE PLUTÔT QU'UN TEST
-- ─────────────────────────────────────────
-- L'invariant est ici EXPRIMABLE en SQL sans dénormalisation : la colonne
-- `environment` existe déjà des deux côtés, on ne duplique aucune donnée
-- nouvelle. On se contente de déclarer que les deux valeurs doivent être la
-- même. C'est le cas idéal pour une contrainte : la base devient incapable
-- de représenter l'état interdit.
--
-- Vérifié sur base de travail avant écriture de cette migration :
--   - insertion divergente  → ERROR 1452 (fk_ledger_operation_env)
--   - insertion cohérente   → acceptée
--
-- CE QUE CETTE MIGRATION NE FAIT PAS
-- ──────────────────────────────────
-- Elle ne contraint pas `transactions.quote_id` → `quotes.id` ni
-- `payments.transaction_id` → `transactions.id`. Ces colonnes sont
-- nullables et alimentées par des chemins qui tolèrent l'absence de parent
-- (une transaction peut naître sans quote). Une FK y casserait des cas
-- légitimes ; l'invariant y reste applicatif, couvert par des tests.
-- Ne pas ajouter une contrainte « pour dire qu'une protection existe ».
--
-- PORTABILITÉ ENTRE BASES
-- ───────────────────────
-- Les gardes interrogent `TABLE_SCHEMA = DATABASE()`, jamais `'nexus'` en
-- dur. `setup_test_db.php` neutralise le `USE nexus;` pour appliquer les
-- migrations à `nexus_test` : une garde codée sur 'nexus' inspecterait alors
-- la mauvaise base, se croirait déjà appliquée, et la contrainte
-- n'existerait QUE dans la base de dev — donc invisible des tests.
--
-- IDEMPOTENCE
-- ───────────
-- Rejouable : chaque étape vérifie information_schema avant d'agir.
--
-- Les branches « rien à faire » utilisent `DO 0` et non un SELECT : le
-- runner de la base de test exécute chaque instruction via PDO::exec(), et
-- un SELECT laisse un jeu de résultats ouvert qui fait échouer l'instruction
-- suivante (erreur 2014, requêtes non bufferisées).
-- =====================================================================

USE nexus;

-- ---------------------------------------------------------------------
-- 1. Index UNIQUE (id, environment) sur le parent.
--    Requis par InnoDB pour référencer ce couple. `id` étant déjà la clé
--    primaire, cet index n'ajoute aucune contrainte métier : il rend
--    simplement le couple référençable.
-- ---------------------------------------------------------------------
SET @has_uq := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'wallet_operations'
       AND INDEX_NAME   = 'uq_op_id_env'
);

SET @sql := IF(@has_uq = 0,
    'ALTER TABLE wallet_operations ADD UNIQUE KEY uq_op_id_env (id, environment)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. Purge défensive des lignes divergentes AVANT la contrainte.
--    Sans cela, l'ALTER échouerait sur une base déjà polluée. Une écriture
--    comptable rattachée au mauvais environnement est de toute façon
--    invalide : elle ne doit pas survivre à la migration.
--
--    NOTE : pas de SELECT de diagnostic ici. `setup_test_db.php` exécute ce
--    fichier via PDO non bufferisé : un jeu de résultats laissé ouvert fait
--    échouer l'instruction suivante (« Cannot execute queries while other
--    unbuffered queries are active »). Une migration ne doit rien retourner.
-- ---------------------------------------------------------------------
DELETE l FROM ledger_entries l
  JOIN wallet_operations o ON o.id = l.operation_id
 WHERE l.environment <> o.environment;

-- ---------------------------------------------------------------------
-- 3. Les orphelines empêcheraient aussi la création de la FK.
--    Une écriture dont l'opération n'existe plus n'est rattachable à rien.
-- ---------------------------------------------------------------------
DELETE l FROM ledger_entries l
  LEFT JOIN wallet_operations o ON o.id = l.operation_id
 WHERE o.id IS NULL;

-- ---------------------------------------------------------------------
-- 4. La contrainte elle-même.
-- ---------------------------------------------------------------------
SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA    = DATABASE()
       AND TABLE_NAME      = 'ledger_entries'
       AND CONSTRAINT_NAME = 'fk_ledger_operation_env'
);

SET @sql := IF(@has_fk = 0,
    'ALTER TABLE ledger_entries
       ADD CONSTRAINT fk_ledger_operation_env
       FOREIGN KEY (operation_id, environment)
       REFERENCES wallet_operations (id, environment)
       ON DELETE CASCADE',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
