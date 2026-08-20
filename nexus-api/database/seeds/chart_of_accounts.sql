-- NEXUS — Plan de comptes (configuration de base, pas des données de démo).
--
-- Application :  mysql ... < database/seeds/chart_of_accounts.sql
-- Idempotent : INSERT IGNORE (unicité sur code).
-- C'est la même liste que celle portée par la migration 0.35 ; ce fichier
-- permet de recharger la configuration sur une base construite par
-- `full_schema.sql` (structure seule).

INSERT IGNORE INTO chart_of_accounts (code, name, currency, account_type, environment) VALUES
    ('USER_POSITION.EUR',            'Position utilisateur EUR',               'EUR', 'liability', NULL),
    ('USER_POSITION.USD',            'Position utilisateur USD',               'USD', 'liability', NULL),
    ('USER_POSITION.XAF',            'Position utilisateur XAF',               'XAF', 'liability', NULL),
    ('SUSPENSE.EUR',                 'Fonds sans contrepartie identifiée EUR', 'EUR', 'asset',     NULL),
    ('SUSPENSE.USD',                 'Fonds sans contrepartie identifiée USD', 'USD', 'asset',     NULL),
    ('SUSPENSE.XAF',                 'Fonds sans contrepartie identifiée XAF', 'XAF', 'asset',     NULL),
    ('PROVIDER_ASSET.pawapay.EUR',   'Fonds détenus chez pawaPay EUR',         'EUR', 'asset',     NULL),
    ('PROVIDER_ASSET.pawapay.XAF',   'Fonds détenus chez pawaPay XAF',         'XAF', 'asset',     NULL),
    ('PROVIDER_SETTLEMENT.pawapay.EUR', 'Transit settlement pawaPay EUR',      'EUR', 'asset',     NULL),
    ('PROVIDER_SETTLEMENT.pawapay.XAF', 'Transit settlement pawaPay XAF',      'XAF', 'asset',     NULL),
    ('PROVIDER_FEES.pawapay',        'Frais prélevés par pawaPay',             'EUR', 'expense',   NULL),
    ('NEXUS_REVENUE.fee',            'Revenus de frais Nexus',                 'EUR', 'revenue',   NULL),
    ('FX_TRANSIT.EURXAF',            'Transit de conversion EUR/XAF',          NULL,  'asset',     NULL),
    ('FX_GAIN_LOSS.EURXAF',          'Gain/perte de change EUR/XAF',           NULL,  'gain_loss', NULL),
    ('REFUND',                       'Réserve de remboursements',              NULL,  'liability', NULL),
    ('CHARGEBACK',                   'Réserve de contre-passations',           NULL,  'liability', NULL);
