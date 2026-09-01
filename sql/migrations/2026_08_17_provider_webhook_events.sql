-- Migration: journal des webhooks providers entrants — idempotence (§13).
--
-- JUSTIFICATION : les webhooks sont le canal par lequel un provider annonce
-- l'état réel d'une opération (payé, échoué, en attente). Sans persistance de
-- l'identité des événements reçus, AUCUNE idempotence n'est possible : un
-- rejeu du provider (ou un retry de notre infrastructure) serait traité deux
-- fois et corromprait l'état comptable. Le schéma KYC suit déjà ce principe
-- (`kyc_webhook_events`) ; les providers de paiement exigent la même
-- garantie.
--
-- CE QUI N'EST PAS STOCKÉ : aucun payload brut (peut contenir des données
-- personnelles ou des secrets de l'opération). Seuls l'identité de
-- l'événement (event_id), son type et son statut — la source de vérité
-- documentaire reste le provider.
--
-- Idempotente : rejouable sans effet de bord.

USE nexus;

CREATE TABLE IF NOT EXISTS provider_webhook_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider        VARCHAR(50)     NOT NULL,
    environment     ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    event_id        VARCHAR(191)    NOT NULL,
    event_type      VARCHAR(100)    NULL,
    -- Statut de traitement côté Nexus : 'received' = persisté et acquitté,
    -- aucune transition d'état métier encore dérivée (intégration en cours).
    status          VARCHAR(50)     NOT NULL DEFAULT 'received',
    received_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- CLÉ D'IDEMPOTENCE (§13) : provider + environnement + event_id.
    -- Un rejeu du même événement est rejeté par la base, pas seulement par le code.
    UNIQUE KEY uq_provider_webhook_event (provider, environment, event_id),
    KEY idx_provider_webhook_received (provider, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
