export const ROLE_CATALOG = [
  { value: 'operations_manager', label: 'Operations Manager', dept: 'Operations', desc: 'Pilotage des opérations de paiement' },
  { value: 'finance_treasury', label: 'Finance Treasury', dept: 'Finance & Trésorerie', desc: 'Finance, liquidités et règlements' },
  { value: 'treasury_manager', label: 'Treasury Manager', dept: 'Finance & Trésorerie', desc: 'Liquidités, trésorerie et règlements' },
  { value: 'compliance_officer', label: 'Compliance Officer', dept: 'Compliance', desc: 'KYC/KYB et conformité réglementaire' },
  { value: 'risk_fraud', label: 'Risk & Fraud', dept: 'Risque & Fraude', desc: 'Prévention et traitement de la fraude' },
  { value: 'risk_analyst', label: 'Risk Analyst', dept: 'Risque & Fraude', desc: 'Analyse de risque et prévention de la fraude' },
  { value: 'provider_manager', label: 'Provider Manager', dept: 'Providers', desc: 'Corridors de paiement et partenaires' },
  { value: 'customer_support', label: 'Customer Support', dept: 'Support Client', desc: 'Assistance client de premier niveau' },
  { value: 'security_technical', label: 'Security Technical', dept: 'Sécurité', desc: 'Sécurité technique et réponse aux incidents' },
  { value: 'security_admin', label: 'Security Admin', dept: 'Sécurité', desc: 'Administration de la sécurité de la plateforme' },
  { value: 'technical_admin', label: 'Technical Admin', dept: 'Technique & Ingénierie', desc: 'Administration technique et infrastructure' },
  { value: 'business_manager', label: 'Business Manager', dept: 'Business Development', desc: 'Partenariats et développement commercial' },
  { value: 'support_operator', label: 'Support Operator', dept: 'Support Client', desc: 'Rôle support historique' },
  { value: 'compliance_operator', label: 'Compliance Operator', dept: 'Compliance', desc: 'Rôle conformité historique' },
  { value: 'finance_operator', label: 'Finance Operator', dept: 'Finance & Trésorerie', desc: 'Rôle finance historique' },
  { value: 'security_engineer', label: 'Security Engineer', dept: 'Sécurité', desc: 'Ingénierie sécurité' },
  { value: 'provider_engineer', label: 'Provider Engineer', dept: 'Providers', desc: 'Intégration technique des providers' },
  { value: 'backend_engineer', label: 'Backend Engineer', dept: 'Technique & Ingénierie', desc: 'Ingénierie backend' },
  { value: 'qa_engineer', label: 'QA Engineer', dept: 'Technique & Ingénierie', desc: 'Assurance qualité' },
  { value: 'sre_operator', label: 'SRE Operator', dept: 'Technique & Ingénierie', desc: 'Fiabilité et exploitation' },
  { value: 'ai_agent', label: 'AI Agent', dept: 'Technique & Ingénierie', desc: 'Agent interne automatisé' },
  { value: 'superadmin', label: 'Super Admin', dept: 'Direction', desc: 'Privilège total sur la plateforme' },
] as const;

export type InternalPlatformRole = (typeof ROLE_CATALOG)[number]['value'];
export type PlatformRole = 'user' | InternalPlatformRole;

const INTERNAL_ROLES = new Set<string>(ROLE_CATALOG.map((role) => role.value));

export function isInternalPlatformRole(role: unknown): role is InternalPlatformRole {
  return typeof role === 'string' && INTERNAL_ROLES.has(role);
}

export function isKnownPlatformRole(role: unknown): role is PlatformRole {
  return role === 'user' || isInternalPlatformRole(role);
}

export function getHomePathForRole(role: unknown): '/admin' | '/staff' | '/dashboard' | null {
  if (role === 'user') return '/dashboard';
  if (role === 'superadmin') return '/admin';
  if (isInternalPlatformRole(role)) return '/staff';
  return null;
}
