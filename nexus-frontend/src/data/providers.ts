/**
 * Catalogue des providers NEXUS.
 *
 * Métadonnées statiques pour l'affichage côté client.
 * Le catalogue source de vérité est côté backend (ProviderCatalog.php).
 * Ce fichier fournit les métadonnées d'affichage pour le frontend
 * (icônes, couleurs, catégories) sans dépendre de l'API.
 */

export type ProviderCategory =
  | 'mobile_money'
  | 'banking'
  | 'fx'
  | 'cards'
  | 'card_issuing'
  | 'crypto'
  | 'payout_network'
  | 'wallet'
  | 'onramp';

export interface ProviderMeta {
  slug: string;
  name: string;
  category: ProviderCategory;
  icon: string;
  authType: string;
  docUrl: string;
  countries: string[];
  baseUrl: string;
  sandboxUrl: string | null;
  fields: ProviderField[];
  color: string;
  colorRgba: string;
  pillCls: string;
}

export interface ProviderField {
  key: string;
  label: string;
  placeholder: string;
  required: boolean;
  type: 'text' | 'password' | 'textarea';
  hasValue?: boolean;
}

/** Catégories de providers et labels d'affichage. */
export const PROVIDER_CATEGORIES: Record<ProviderCategory, { label: string; icon: string; description: string }> = {
  mobile_money:  { label: 'Mobile Money',      icon: '📱', description: 'Paiements mobiles Africa' },
  banking:       { label: 'Banking / BaaS',     icon: '🏦', description: 'Comptes bancaires & IBAN' },
  fx:            { label: 'FX / Cross-border',  icon: '💱', description: 'Conversion de devises' },
  cards:         { label: 'Cards / Paiements',   icon: '💳', description: 'Paiement par carte' },
  card_issuing:  { label: 'Card Issuing',        icon: '💎', description: 'Émission de cartes virtuelles' },
  crypto:        { label: 'Crypto / Stablecoins',icon: '🔗', description: 'Blockchain & USDT/USDC' },
  payout_network:{ label: 'Payout Network',      icon: '🌍', description: 'Réseaux de paiement émergents' },
  wallet:        { label: 'Portefeuille',       icon: '🟢', description: 'Portefeuilles numériques' },
  onramp:        { label: 'On/Off Ramp',         icon: '🔀', description: 'Pont fiat ↔ crypto' },
};

/** Mapping des catégories vers des couleurs du design system. */
const CATEGORY_STYLES: Record<ProviderCategory, { color: string; colorRgba: string; pillCls: string }> = {
  mobile_money:  { color: 'var(--green)',  colorRgba: 'rgba(0,207,160,0.25)',  pillCls: 'p-gr' },
  banking:       { color: 'var(--cyan)',   colorRgba: 'rgba(0,200,255,0.25)',  pillCls: 'p-c' },
  fx:            { color: 'var(--gold)',   colorRgba: 'rgba(234,184,48,0.25)', pillCls: 'p-g' },
  cards:         { color: 'var(--cyan)',   colorRgba: 'rgba(0,200,255,0.25)',  pillCls: 'p-c' },
  card_issuing:  { color: 'var(--violet)', colorRgba: 'rgba(139,92,246,0.25)', pillCls: 'p-v' },
  crypto:        { color: 'var(--violet)', colorRgba: 'rgba(139,92,246,0.25)', pillCls: 'p-v' },
  payout_network:{ color: 'var(--gold)',   colorRgba: 'rgba(234,184,48,0.25)', pillCls: 'p-g' },
  wallet:        { color: 'var(--green)',  colorRgba: 'rgba(0,207,160,0.25)',  pillCls: 'p-gr' },
  onramp:        { color: 'var(--violet)', colorRgba: 'rgba(139,92,246,0.25)', pillCls: 'p-v' },
};

/**
 * Construit un objet ProviderMeta à partir des données brutes de l'API.
 */
export function buildProviderMeta(apiProvider: Record<string, unknown>): ProviderMeta {
  const cat = (apiProvider.category ?? 'payout_network') as ProviderCategory;
  const style = CATEGORY_STYLES[cat] ?? CATEGORY_STYLES.payout_network;

  return {
    slug: (apiProvider.slug ?? '') as string,
    name: (apiProvider.name ?? '') as string,
    category: cat,
    icon: (apiProvider.icon ?? '🌐') as string,
    authType: (apiProvider.auth_type ?? '') as string,
    docUrl: (apiProvider.doc_url ?? '') as string,
    countries: (apiProvider.countries ?? []) as string[],
    baseUrl: (apiProvider.base_url ?? '') as string,
    sandboxUrl: (apiProvider.sandbox_url ?? null) as string | null,
    fields: (apiProvider.fields ?? []) as ProviderField[],
    color: style.color,
    colorRgba: style.colorRgba,
    pillCls: style.pillCls,
  };
}

/** Statuts d'un credential de provider. */
export type CredentialStatus = 'not_configured' | 'sandbox_only' | 'active' | 'error';

export interface CredentialState {
  id?: number;
  provider_slug: string;
  environment: 'sandbox' | 'production';
  status: CredentialStatus;
  has_credentials: boolean;
  last_tested_at: string | null;
  last_error: string | null;
  created_at: string;
  updated_at: string;
}

export const CREDENTIAL_STATUS_LABELS: Record<CredentialStatus, { label: string; color: string; pillCls: string }> = {
  not_configured: { label: 'Non configuré', color: 'var(--text-dim)',   pillCls: '' },
  sandbox_only:   { label: 'Sandbox',       color: 'var(--gold)',      pillCls: 'p-g' },
  active:         { label: 'Actif',          color: 'var(--green)',     pillCls: 'p-gr' },
  error:          { label: 'Erreur',         color: 'var(--red)',       pillCls: 'p-r' },
};
