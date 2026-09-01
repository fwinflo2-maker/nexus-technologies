-- =====================================================================
-- Migration 0.23 — RÔLES INTERNES NEXUS (8 dashboards spécialisés)
-- =====================================================================
--
-- Ajoute les rôles internes métier à `users.platform_role`, alignés sur
-- l'architecture RBAC des dashboards internes Nexus :
--
--   1. superadmin          → Executive Dashboard (vue globale)
--   2. operations_manager  → Operations Dashboard
--   3. finance_treasury    → Finance / Treasury Dashboard
--   4. compliance_officer  → Compliance Dashboard (KYC / AML / sanctions)
--   5. risk_fraud          → Risk / Fraud Dashboard
--   6. provider_manager    → Providers Dashboard
--   7. customer_support    → Support / Customer Operations Dashboard
--   8. security_technical  → Security / Technical Dashboard
--
-- Les rôles EXISTANTS sont conservés (rétrocompatibilité) : aucune donnée
-- existante n'est modifiée. Le nouveau rôle par défaut reste `user`.
--
-- NON DESTRUCTIF : MODIFY COLUMN préserve les lignes existantes.
-- =====================================================================

ALTER TABLE `users`
  MODIFY COLUMN `platform_role` ENUM(
      'user',
      -- Rôles internes Nexus (dashboards spécialisés)
      'superadmin',
      'operations_manager',
      'finance_treasury',
      'compliance_officer',
      'risk_fraud',
      'provider_manager',
      'customer_support',
      'security_technical',
      -- Rôles historiques (conservés pour compatibilité)
      'support_operator',
      'compliance_operator',
      'finance_operator',
      'security_engineer',
      'provider_engineer',
      'backend_engineer',
      'qa_engineer',
      'sre_operator',
      'ai_agent'
  ) NOT NULL DEFAULT 'user'
  COMMENT 'Rôle d''exploitation de la plateforme. Distinct de account_type (type de client).';
