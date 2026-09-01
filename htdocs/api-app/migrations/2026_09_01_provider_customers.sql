-- Copie déployée avec api-app (Hostinger). Source canonique : ../../sql/migrations/
CREATE TABLE IF NOT EXISTS provider_customers (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               BIGINT UNSIGNED NOT NULL,
    provider_slug         VARCHAR(50) NOT NULL,
    provider_customer_id  VARCHAR(191) NOT NULL,
    environment           ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    status                ENUM('PENDING','ACTIVE','SUSPENDED','FAILED') NOT NULL DEFAULT 'PENDING',
    metadata              JSON NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_customers_user_provider_env (user_id, provider_slug, environment),
    KEY idx_provider_customers_user (user_id),
    KEY idx_provider_customers_slug (provider_slug),
    KEY idx_provider_customers_provider_id (provider_customer_id),
    KEY idx_provider_customers_env (environment),
    KEY idx_provider_customers_status (status),
    CONSTRAINT fk_provider_customers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
