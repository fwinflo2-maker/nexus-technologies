-- =============================================================================
-- NEXUS — DONNÉES DE RÉFÉRENCE (DÉMONSTRATION UNIQUEMENT)
--
-- Fichier GÉNÉRÉ : concaténation de database/seeds/.
--   Régénérer : bash scripts/export_sql_reference.sh
--
-- AVERTISSEMENT (§15) : ces jeux sont des données de DÉMONSTRATION. Ils ne
-- doivent JAMAIS être chargés dans un environnement de production. Toute
-- donnée issue de ce fichier appartient à l'environnement « sandbox ».
-- =============================================================================

SET NAMES utf8mb4;

-- ─── demo_fx_rates.sql ───
-- =============================================================================
-- NEXUS — SEED : taux de change de démonstration
--
--                    ####  SANDBOX / DEVELOPMENT ONLY  ####
--
-- NE JAMAIS EXÉCUTER EN PRODUCTION.
--
-- Préremplit `fx_rates_cache` avec un jeu de taux déterministe (TTL 24 h),
-- afin que les écrans Convert / Send / Treasury affichent des valeurs stables
-- en développement.
--
-- Pourquoi ce n'est pas une migration :
--   `fx_rates_cache` est un cache d'exploitation. En production il doit être
--   alimenté par une source de taux réelle. Y figer des valeurs depuis une
--   migration ferait passer des taux inventés pour des taux de marché (§8, §27).
--
-- Aucune régression sans ce seed : `ManualRateProvider` fournit déjà les mêmes
-- taux en PHP lorsqu'une paire est absente du cache.
--
-- Extrait de : database/migrations/2026_08_10_wallet_core.sql
--
-- Usage (développement uniquement) :
--     mysql -u nexus -p nexus < database/seeds/demo_fx_rates.sql
-- =============================================================================

-- `environment` est fourni EXPLICITEMENT : ces taux sont un jeu de
-- DÉMONSTRATION et ne doivent jamais coter de l'argent réel. Le défaut de la
-- colonne vaut déjà « sandbox », mais un défaut est une protection passive :
-- le seeder reste correct par lui-même si ce défaut change un jour.
INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, source, environment, fetched_at, expires_at) VALUES
    ('EUR', 'USD',  1.08700000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'GBP',  0.85500000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'XAF',  655.95700000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'XOF',  655.95700000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'NGN',  1650.00000000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'GHS',  14.80000000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'KES',  141.00000000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'USDT', 1.08700000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'USDC', 1.08700000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('USD', 'EUR',  0.92000000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('GBP', 'EUR',  1.17000000, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('XAF', 'EUR',  0.00152400, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR));

-- ─── demo_payment_accounts.sql ───
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

