SET FOREIGN_KEY_CHECKS=0;

-- NEXUS DATABASE SCHEMA

-- Users
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120),
  account_type ENUM('personal','business') NOT NULL,
  status ENUM('VERIFIED','PENDING','LIMITED','BLOCKED') DEFAULT 'PENDING',
  kyc_level ENUM('none','personal_pending','personal_verified','business_pending','business_verified') DEFAULT 'none',
  ref_currency CHAR(3) DEFAULT 'EUR',
  lang VARCHAR(5) DEFAULT 'fr',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);

-- Wallets
CREATE TABLE wallets (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  available DECIMAL(18,2) DEFAULT 0,
  pending DECIMAL(18,2) DEFAULT 0,
  in_transit DECIMAL(18,2) DEFAULT 0,
  settlement DECIMAL(18,2) DEFAULT 0,
  is_primary BOOL DEFAULT 0,
  UNIQUE(user_id, currency),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Providers
CREATE TABLE providers (
  id INT UNSIGNED PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  capabilities JSON NOT NULL,
  countries JSON NOT NULL,
  currencies JSON NOT NULL,
  rails JSON NOT NULL,
  fees JSON NOT NULL,
  sla JSON NOT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  performance_score TINYINT DEFAULT 0,
  api_version VARCHAR(20),
  webhooks_supported BOOL DEFAULT 0
);

-- Seed Providers (19 providers total)
INSERT INTO providers (id, name, capabilities, countries, currencies, rails, fees, sla, api_version) VALUES
(1, 'Swan', '{"type": "bank"}', '["UK"]', '["GBP"]', '["https://api.swan.co"]', '[0.5]%', '[2h]', '1.0.0'),
(2, 'Modulr', '{"type": "api"}', '["US","UK"]', '["USD","EUR"]', '["https://api.modulr.com"]', '[1.2]%', '[15m]', '2.1'),
(3, 'Stripe', '{"type": "api,card"}', '["US","EU"]', '["USD","EUR","GBP"]', '["https://api.stripe.com"]', '[1.5]%', '[10m]', '20'),
(4, 'pawaPay', '{"type": "money_transfer"}', '["NG"]', '["USD","NGN"]', '["https://api.pawapay.com"]', '[1.0]%', '[30m]', '1.0'),
(5, 'Onfriq', '{"type": "mobile_money"}', '["NG","CD"]', '["NGN","CDF"]', '["https://api.onfriq.com"]', '[0.8]%', '[20m]', '1.1'),
(6, 'Thunes', '{"type": "payout"}', '["US","GB","NG","GH"]', '["USD","GBP","USD","GHS"]', '["https://api.thunes.com"]', '[1.2]%', '[15m]', '2.0'),
(7, 'NOAH', '{"type": "wallet"}', '["NG","US"]', '["NGN","USD"]', '["https://api.noah.com"]', '[0.5]%', '[1h]', '0.9'),
(8, 'Currencycloud', '{"type": "fx"}', '["EU"]', '["EUR","USD"]', '["https://api.currencycloud.com"]', '[0.4]%', '[5m]', '3.0'),
(9, 'Wise Platform', '{"type": "fx"}', '["EU","US"]', '["EUR","USD","GBP"]', '["https://api.wise.com"]', '[0.35]%', '[5m]', '3.1'),
(10, 'Bridge', '{"type": "crypto"}', '["US","EU"]', '["USD","EUR","BTC"]', '["https://api.bridge.com"]', '[0.2]%', '[2m]', '1.0'),
(11, 'BVNK', '{"type": "bank"}', '["US","EU"]', '["USD","EUR"]', '["https://api.bvnk.com"]', '[0.7]%', '[10m]', '1.5'),
(12, 'Yellow Card', '{"type": "mobile_money"}', '["NG","KE"]', '["NGN","KES"]', '["https://api.yellowcard.co"]', '[1.5]%', '[20m]', '0.8'),
(13, 'CashRamp', '{"type": "onramp"}', '["NG"]', '["NGN"]', '["https://api.cashramp.com"]', '[1.5]%', '[20m]', '0.7'),
(14, 'Stripe Issuing', '{"type": "card_issuing"}', '["US","EU"]', '["USD","EUR"]', '["https://api.stripe.com/issuing"]', '[2.0]%', '[5m]', '2.0'),
(15, 'Nium', '{"type": "payout"}', '["US","GB","AU"]', '["USD","GBP","AUD"]', '["https://api.nium.com"]', '[1.1]%', '[12m]', '1.2'),
(16, 'Marqeta', '{"type": "card"}', '["US","AU"]', '["USD","AUD"]', '["https://api.marqeta.com"]', '[0.9]%', '[10m]', '2.5'),
(17, 'dLocal', '{"type": "mobile_money"}', '["BR","MX","CO"]', '["BRL","MXN","COP"]', '["https://api.dlocal.com"]', '[1.3]%', '[15m]', '0.9'),
(18, 'Ebanx', '{"type": "payout"}', '["BR","MX","CL"]', '["BRL","MXN","CLP"]', '["https://api.ebanx.com"]', '[1.0]%', '[20m]', '1.0'),
(19, 'Xendit', '{"type": "payout"}', '["ID","PH","SG"]', '["IDR","PHP","SGD"]', '["https://api.xendit.co"]', '[0.8]%', '[10m]', '1.8');

-- Payment Accounts
CREATE TABLE payment_accounts (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('bank_iban','mobile_money','crypto_wallet','card','virtual_iban') NOT NULL,
  direction ENUM('source','destination','both') NOT NULL,
  label VARCHAR(100),
  country CHAR(2) NOT NULL,
  currency CHAR(3) NOT NULL,
  details JSON NOT NULL,
  is_default BOOL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Transactions
CREATE TABLE transactions (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  wallet_id INT UNSIGNED NOT NULL,
  public_id VARCHAR(32) UNIQUE NOT NULL,
  idempotency_key VARCHAR(64) UNIQUE NOT NULL,
  type ENUM('send','receive','topup') NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  fees JSON,
  fx_rate DECIMAL(12,6),
  provider_id INT UNSIGNED,
  route JSON,
  status ENUM('CREATED','QUOTED','AUTHORIZED','PROCESSING','PENDING','COMPLETED','SETTLED','RECONCILED','FAILED','TIMEOUT','UNKNOWN','CANCELLED','EXPIRED','REVERSED','REFUNDED') DEFAULT 'CREATED',
  reconciliation_state VARCHAR(40),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
  expires_at DATETIME,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
  FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
);

-- Transaction Events
CREATE TABLE transaction_events (
  id INT UNSIGNED PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(40) NOT NULL,
  to_status VARCHAR(40) NOT NULL,
  note VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);

-- Notifications
CREATE TABLE notifications (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('transfert','quote','kyc','securite','business','systeme') NOT NULL,
  title VARCHAR(200) NOT NULL,
  body VARCHAR(1000) NOT NULL,
  read BOOL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Teams
CREATE TABLE teams (
  id INT UNSIGNED PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Team Members
CREATE TABLE team_members (
  id INT UNSIGNED PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('owner','administrator','finance_manager','accountant','operator','viewer') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(team_id, user_id),
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Approval Requests
CREATE TABLE approval_requests (
  id INT UNSIGNED PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  transaction_id INT UNSIGNED NOT NULL,
  initiator_id INT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  approver_id INT UNSIGNED,
  approved_at DATETIME,
  note VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  FOREIGN KEY (initiator_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE SET NULL
);

-- KYC Applications
CREATE TABLE kyc_applications (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('kyc','kyb') NOT NULL,
  status VARCHAR(40) NOT NULL,
  provider VARCHAR(40) DEFAULT 'sumsub-simule',
  payload JSON NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Alerts
CREATE TABLE alerts (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('spread','price','liquidity','route','market') NOT NULL,
  config JSON NOT NULL,
  triggered BOOL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit Logs
CREATE TABLE audit_logs (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  metadata JSON,
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sessions
CREATE TABLE sessions (
  id INT UNSIGNED PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  device VARCHAR(100),
  ip VARCHAR(45),
  last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
  revoked BOOL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Indexes for performance
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_wallets_user_currency ON wallets(user_id, currency);
CREATE INDEX idx_transactions_user_id ON transactions(user_id);
CREATE INDEX idx_transactions_status ON transactions(status);
CREATE INDEX idx_transactions_idempotency ON transactions(idempotency_key);
CREATE INDEX idx_transaction_events_transaction ON transaction_events(transaction_id);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, read);
CREATE INDEX idx_approval_requests_team_status ON approval_requests(team_id, status);
CREATE INDEX idx_kyc_applications_user ON kyc_applications(user_id);
CREATE INDEX idx_alerts_user ON alerts(user_id);
CREATE INDEX idx_audit_logs_user ON audit_logs(user_id);
CREATE INDEX idx_sessions_user ON sessions(user_id);
CREATE INDEX idx_sessions_token ON sessions(token_hash);

SET FOREIGN_KEY_CHECKS=1;