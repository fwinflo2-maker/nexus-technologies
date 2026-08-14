-- =============================================================================
-- NEXUS — SEED : compte source de démonstration (multi-origine)
--
--                    ####  SANDBOX / DEVELOPMENT ONLY  ####
--
-- NE JAMAIS EXÉCUTER EN PRODUCTION.
--
-- Ce fichier ajoute une source de financement « Mobile Money Ghana — MTN »
-- marquée `verified`, afin d'illustrer le cas multi-origine (résidence CG,
-- sources CG + GH) sur un environnement de démonstration.
--
-- Pourquoi ce n'est pas une migration :
--   une source `verified` + `supported_for_transfer = 1` est traitée par le
--   FundingSourceEngine comme une origine de fonds réellement autorisée. La
--   créer depuis une migration de structure reviendrait à ouvrir un droit de
--   transfert sur des comptes de production à partir d'une donnée fictive.
--
-- Extrait de : database/migrations/2026_08_10_kyc_origins.sql
--
-- Usage (développement uniquement) :
--     mysql -u nexus -p nexus < database/seeds/demo_payment_accounts.sql
-- =============================================================================

-- Garde-fou : la variable doit être positionnée explicitement par l'opérateur.
-- Sans elle, le script s'interrompt avant toute écriture.
SET @NEXUS_ALLOW_DEMO_SEED = IFNULL(@NEXUS_ALLOW_DEMO_SEED, 0);

-- Interrompt volontairement l'exécution si le garde-fou n'est pas levé.
SELECT
    CASE
        WHEN @NEXUS_ALLOW_DEMO_SEED = 1 THEN 'Seed de démonstration autorisé'
        ELSE (SELECT 'REFUS : positionner SET @NEXUS_ALLOW_DEMO_SEED = 1 avant exécution'
              FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = '__nexus_demo_seed_refuse__')
    END AS garde_fou;

INSERT INTO payment_accounts
    (user_id, role, kind, label, holder_name, country, currency,
     operator, phone_enc, is_default, verification_status,
     supported_for_transfer, status, created_at)
SELECT
    u.id,
    'source',
    'mobile_money',
    'Mobile Money Ghana — MTN',
    u.full_name,
    'GH',
    'GHS',
    'MTN Mobile Money',
    -- phone_enc : NULL en SQL pur (Crypto::encrypt n'est pas reproductible
    -- ici). Le seed PHP le renseigne correctement lorsqu'il est utilisé.
    NULL,
    0,
    'verified',
    1,
    'active',
    NOW()
FROM users u
WHERE @NEXUS_ALLOW_DEMO_SEED = 1
  AND NOT EXISTS (
    SELECT 1 FROM payment_accounts pa
    WHERE pa.user_id = u.id AND pa.role = 'source' AND pa.country = 'GH'
)
LIMIT 10;
