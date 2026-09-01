-- Migration : rôle plateforme (Superadmin / personnel interne Nexus).
--
-- PROBLÈME RÉSOLU (sévérité CRITICAL)
-- ──────────────────────────────────
-- L'administration des credentials providers était gardée par :
--
--     account_type === 'business'
--
-- Or `account_type` est un attribut CLIENT, choisi librement par l'utilisateur
-- au moment de l'inscription. L'exploitation a été reproduite en HTTP réel :
--
--     1. POST /register  { account_type: "business" }        → 200 + jeton
--     2. PUT  /providers/stripe/credentials
--          { environment: "production", secret_key: "sk_live_…" }  → 200
--
-- N'importe qui pouvait donc injecter une credential de PRODUCTION. Selon la
-- suite du système, cela permet de détourner le routage des paiements vers un
-- compte provider contrôlé par l'attaquant, ou d'invalider les paiements
-- réels de la plateforme.
--
-- CAUSE RACINE
-- ────────────
-- Deux notions distinctes étaient confondues :
--
--     account_type   → QUI EST LE CLIENT   (personal | business)
--     platform_role  → QUI EXPLOITE NEXUS  (user | … | superadmin)
--
-- Un client business est un client. Il ne doit pas hériter d'un privilège
-- d'exploitant du seul fait de son type de compte.
--
-- CHOIX DE CONCEPTION
-- ───────────────────
-- Nouvelle colonne, plutôt qu'un élargissement de l'ENUM `account_type` :
-- fusionner les deux aurait rendu impossible un business qui soit AUSSI
-- opérateur, et aurait laissé la porte ouverte à une escalade par le champ
-- d'inscription.
--
-- Le défaut est `user` : aucun compte existant n'est promu par cette
-- migration. Le premier superadmin doit être désigné explicitement en base
-- par un administrateur — il n'existe aucun chemin applicatif pour s'auto-
-- promouvoir, c'est délibéré.
--
-- Les rôles internes (support, security, finance…) sont déclarés dès
-- maintenant pour que le RBAC granulaire de la §8 puisse s'y adosser sans
-- nouvelle migration, mais AUCUN privilège ne leur est encore accordé : un
-- rôle non implémenté se comporte exactement comme `user`.
--
-- Idempotente : rejouable sans effet de bord.

USE nexus;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'platform_role'
);

SET @sql := IF(@col = 0,
    "ALTER TABLE users
       ADD COLUMN platform_role ENUM(
            'user',
            'support_operator',
            'compliance_operator',
            'finance_operator',
            'security_engineer',
            'provider_engineer',
            'backend_engineer',
            'qa_engineer',
            'sre_operator',
            'ai_agent',
            'superadmin'
       ) NOT NULL DEFAULT 'user'
       COMMENT 'Rôle d''exploitation de la plateforme. Distinct de account_type (type de client).'
       AFTER account_type",
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index : les vérifications de privilège filtrent sur ce champ.
SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND INDEX_NAME   = 'idx_users_platform_role'
);

SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_users_platform_role ON users (platform_role)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
