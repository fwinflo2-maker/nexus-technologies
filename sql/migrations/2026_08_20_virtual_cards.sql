-- NEXUS — Migration 0.42 : demandes de cartes virtuelles
--
-- Pas d'émission réelle tant qu'aucun provider card_issuing n'est
-- opérationnel. On stocke la DEMANDE (statut pending_issuer) — jamais un
-- PAN / CVV inventé.

CREATE TABLE IF NOT EXISTS virtual_cards (
    id              VARCHAR(36) NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    label           VARCHAR(120) NOT NULL,
    currency        VARCHAR(5) NOT NULL,
    spend_limit     DECIMAL(20,8) NULL,
    status          ENUM(
                        'pending_issuer',
                        'issuer_unavailable',
                        'active',
                        'frozen',
                        'cancelled'
                    ) NOT NULL DEFAULT 'pending_issuer',
    last4           CHAR(4) NULL,
    brand           VARCHAR(32) NULL,
    issuer_provider VARCHAR(50) NULL,
    issuer_ref      VARCHAR(190) NULL,
    environment     ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vcards_user (user_id, status),
    CONSTRAINT fk_vcards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
