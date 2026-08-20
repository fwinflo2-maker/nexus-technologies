-- NEXUS — Migration 0.41 : attribution sûre des dépôts provider
--
-- Un webhook de funding ne peut jamais choisir son propriétaire. La
-- référence provider doit avoir été liée auparavant à un intent Nexus
-- authentifié, qui fixe user, wallet, devise, montant et environnement.

CREATE TABLE IF NOT EXISTS funding_intents (
    id                  VARCHAR(36) NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    wallet_id           BIGINT UNSIGNED NOT NULL,
    provider            VARCHAR(50) NOT NULL,
    provider_reference  VARCHAR(190) NOT NULL,
    environment         ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    currency            VARCHAR(5) NOT NULL,
    expected_amount     DECIMAL(20,8) NOT NULL,
    status              ENUM('created','processing','completed','expired','cancelled') NOT NULL DEFAULT 'created',
    funding_operation_id VARCHAR(36) NULL,
    expires_at          DATETIME NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_funding_provider_reference (provider, environment, provider_reference),
    KEY idx_funding_owner (user_id, status),
    CONSTRAINT fk_funding_intent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_funding_intent_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
