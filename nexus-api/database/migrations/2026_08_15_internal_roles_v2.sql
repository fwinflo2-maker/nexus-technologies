-- =====================================================================
-- Migration 0.24 — RÔLES INTERNES NEXUS v2 (complément 10 dashboards)
-- =====================================================================
--
-- Ajoute les rôles internes restants pour couvrir les 10 dashboards internes :
--
--   9. business_manager   → Business Management Dashboard
--  10. technical_admin    → Technical / Engineering Dashboard
--
-- Et des ALIAS de lisibilité pour les rôles existants (conservés pour
-- compatibilité, mappés au même dashboard) :
--   treasury_manager  → alias de finance_treasury (Finance / Treasury)
--   risk_analyst      → alias de risk_fraud (Risk / Fraud)
--   security_admin    → alias de security_technical (Security)
--
-- Aucun rôle existant n'est supprimé ; aucune donnée modifiée.
-- NON DESTRUCTIF.
-- =====================================================================

ALTER TABLE `users`
  MODIFY COLUMN `platform_role` ENUM(
      'user',
      -- Rôles internes Nexus (10 dashboards)
      'superadmin',
      'operations_manager',
      'finance_treasury',
      'treasury_manager',
      'compliance_officer',
      'risk_fraud',
      'risk_analyst',
      'provider_manager',
      'customer_support',
      'security_technical',
      'security_admin',
      'technical_admin',
      'business_manager',
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
