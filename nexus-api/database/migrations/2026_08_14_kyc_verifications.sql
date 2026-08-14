-- Migration: persistance KYC/KYB provider (Sumsub) — EXCEPTION AU SQL FREEZE.
--
-- JUSTIFICATION (§23, §36) : le freeze SQL est explicite — aucune table nouvelle
-- sans nécessité backend démontrée. Deux exigences la rendent indispensable :
--
--   1. §24 — idempotence des webhooks sur (provider, environment, event_id).
--      Sans persistance des événements reçus, il est IMPOSSIBLE de garantir
--      qu'un événement n'est pas traité deux fois. Un rejeu d'un événement
--      « verified » ne doit jamais être rejoué.
--
--   2. §21/§22 — le lien entre un utilisateur Nexus et son applicant chez le
--      provider doit être persistant : `users.kyc_level` ne peut ni stocker un
--      identifiant d'applicant externe, ni distinguer KYC (personne) de KYB
--      (entreprise), ni conserver l'environnement (sandbox/production).
--
-- CE QUI N'EST PAS STOCKÉ (§23) : aucun document, selfie, donnée biométrique,
-- secret Sumsub, ni réponse brute complète. La source de vérité documentaire
-- reste Sumsub. Seuls des identifiants, des statuts et des horodatages.
--
-- Idempotente : rejouable sans effet de bord.

USE nexus;

-- 1) Lien applicant : un dossier de vérification par (user, type, provider, env)
CREATE TABLE IF NOT EXISTS kyc_verifications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    provider            VARCHAR(50)     NOT NULL,
    environment         ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    subject_type        ENUM('individual','company') NOT NULL DEFAULT 'individual',
    applicant_id        VARCHAR(128)    NOT NULL,
    level_name          VARCHAR(100)    NULL,
    status              ENUM('not_started','in_progress','pending','verified',
                             'resubmission_requested','rejected','on_hold')
                        NOT NULL DEFAULT 'not_started',
    -- Motif affichable (moderationComment). Jamais de donnée sensible.
    reason              VARCHAR(500)    NULL,
    reviewed_at         DATETIME        NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Un seul dossier par utilisateur / type / provider / environnement.
    UNIQUE KEY uq_kyc_user_subject (user_id, provider, environment, subject_type),
    -- Retrouver le dossier depuis un webhook (qui ne connaît que l'applicant).
    UNIQUE KEY uq_kyc_applicant (provider, environment, applicant_id),
    KEY idx_kyc_status (status),
    CONSTRAINT fk_kyc_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Événements webhook : garantit l'idempotence (§24)
CREATE TABLE IF NOT EXISTS kyc_webhook_events (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider            VARCHAR(50)     NOT NULL,
    environment         ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    event_id            VARCHAR(191)    NOT NULL,
    applicant_id        VARCHAR(128)    NULL,
    verification_id     BIGINT UNSIGNED NULL,
    status              VARCHAR(50)     NULL,
    processed_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- CLÉ D'IDEMPOTENCE (§24) : provider + environment + event_id.
    -- Un rejeu du même événement est rejeté par la base, pas seulement par le code.
    UNIQUE KEY uq_kyc_event (provider, environment, event_id),
    KEY idx_kyc_event_applicant (applicant_id),
    CONSTRAINT fk_kyc_event_verification FOREIGN KEY (verification_id)
        REFERENCES kyc_verifications (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
