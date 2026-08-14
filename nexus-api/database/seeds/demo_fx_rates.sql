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

INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, source, fetched_at, expires_at) VALUES
    ('EUR', 'USD',  1.08700000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'GBP',  0.85500000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'XAF',  655.95700000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'XOF',  655.95700000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'NGN',  1650.00000000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'GHS',  14.80000000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'KES',  141.00000000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'USDT', 1.08700000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('EUR', 'USDC', 1.08700000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('USD', 'EUR',  0.92000000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('GBP', 'EUR',  1.17000000, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    ('XAF', 'EUR',  0.00152400, 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR));
