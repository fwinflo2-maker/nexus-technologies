-- Milestone 3 — Cashramp mappings (provider accounts + platform config + wallets projection)

CREATE TABLE IF NOT EXISTS provider_user_accounts (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED NOT NULL,
    provider_slug        VARCHAR(50) NOT NULL,
    environment          ENUM('sandbox','production') NOT NULL,
    external_account_id  VARCHAR(191) NOT NULL,
    currency             CHAR(3) NOT NULL,
    account_type         VARCHAR(50) NOT NULL DEFAULT 'virtual_bank',
    status               VARCHAR(50) NOT NULL DEFAULT 'requested',
    metadata_json        JSON NULL,
    last_synced_at       DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_user_account (user_id, provider_slug, environment, external_account_id),
    KEY idx_provider_user_accounts_lookup (provider_slug, environment, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_user_wallets (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED NOT NULL,
    provider_slug        VARCHAR(50) NOT NULL,
    environment          ENUM('sandbox','production') NOT NULL,
    provider_wallet_id   VARCHAR(191) NOT NULL,
    asset                VARCHAR(20) NOT NULL,
    network              VARCHAR(20) NULL,
    address              VARCHAR(191) NULL,
    status               VARCHAR(50) NOT NULL DEFAULT 'unknown',
    balance_available    DECIMAL(24,8) NULL,
    balance_pending      DECIMAL(24,8) NULL,
    metadata_json        JSON NULL,
    last_synced_at       DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_user_wallet (user_id, provider_slug, environment, provider_wallet_id),
    KEY idx_provider_user_wallets_lookup (provider_slug, environment, user_id, asset)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_platform_config (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_slug VARCHAR(50) NOT NULL,
    environment   ENUM('sandbox','production') NOT NULL,
    config_key    VARCHAR(100) NOT NULL,
    config_json   JSON NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_platform_config (provider_slug, environment, config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
