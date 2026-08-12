/**
 * Client API centralisé pour NEXUS.
 *
 * - Gère le token JWT dans sessionStorage (démo sécurisé).
 * - Préfixe toutes les requêtes avec /api.
 * - Intercepte les réponses 401 pour nettoyer la session expirée.
 * - Retourne une réponse typée { success, data?, error? }.
 */

// Prefixe standard pour toutes les routes backend
const API_PREFIX = '/api';

/**
 * Récupère le token JWT depuis sessionStorage.
 * Retourne null si absent ou périmé.
 */
function getToken(): string | null {
  const token = sessionStorage.getItem('nexus_token');
  if (!token) return null;
  // Optionnel : vérifier expiration JWT côté client
  try {
    const payload = JSON.parse(atob(token.split('.')[1]));
    if (payload.exp && Date.now() >= payload.exp * 1000) {
      sessionStorage.removeItem('nexus_token');
      return null;
    }
  } catch {
    sessionStorage.removeItem('nexus_token');
    return null;
  }
  return token;
}

/**
 * Stocke ou supprime le token JWT.
 * @param token - Token JWT valide ou null pour suppression.
 */
function setToken(token: string | null): void {
  if (token) {
    sessionStorage.setItem('nexus_token', token);
  } else {
    sessionStorage.removeItem('nexus_token');
  }
}

// --- Types -----------------------------------------------------------------

export interface ApiUser {
  id: number;
  full_name: string;
  email: string;
  phone?: string;
  account_type: 'personal' | 'business';
  auth_provider: 'local' | 'google';
  status: string;
  kyc_level: string;
  country_of_residence?: string | null;
  created_at: string;
}

export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: string;
  code?: string;
}

export interface DashboardWallet {
  currency: string;
  balance: number;
  available: number;
  pending: number;
  in_transit: number;
  settlement: number;
  ref_equivalent: number;
  has_funds: boolean;
}

export interface DashboardTotals {
  ref_currency: string;
  total_ref: number;
  available_ref: number;
  currencies: number;
  with_funds: number;
}

export interface DashboardKpis {
  transactions_month: number;
  volume_xaf: number;
  success_rate: number;
  avg_exec_time_sec: number | null;
  fees_total_ref: number;
}

export interface DashboardRecentTx {
  id: number;
  type: 'send' | 'receive' | 'fx' | 'convert';
  direction: 'in' | 'out' | 'fx';
  label: string;
  description: string | null;
  amount: number;
  currency: string;
  amount_ref: number;
  amount_xaf: number;
  status: 'completed' | 'processing' | 'pending' | 'failed' | 'cancelled';
  provider: string | null;
  destination: string | null;
  created_at: string;
}

export interface DashboardBanner {
  type: 'kyc' | 'limits' | 'corridor' | null;
  title: string;
  message: string;
  action: string | null;
}

export interface DashboardSummaryData {
  ref_currency: string;
  user: {
    id: number;
    full_name: string;
    account_type: 'personal' | 'business';
    status: string;
    kyc_level: string;
  };
  wallets: DashboardWallet[];
  totals: DashboardTotals;
  kpis: DashboardKpis;
  recent: DashboardRecentTx[];
  banner: DashboardBanner;
}

export interface ActivitySeriesPoint {
  label: string;
  volume: number;
  count: number;
}

export interface DashboardActivityData {
  period: '7d' | '30d' | '12m';
  ref_currency: string;
  series: ActivitySeriesPoint[];
}

// --- Wallet -------------------------------------------------------------------

/** Soldes d'une devise, ventilés par état. */
export interface WalletState {
  currency: string;
  balance: number;
  available: number;
  pending: number;
  in_transit: number;
  settlement: number;
  ref_equivalent: number;
  has_funds: boolean;
}

/** Totaux du portefeuille, exprimés en devise de référence (EUR). */
export interface WalletTotals {
  ref_currency: string;
  total_ref: number;
  available_ref: number;
  pending_ref: number;
  in_transit_ref: number;
  settlement_ref: number;
  currencies: number;
  with_funds: number;
}

export interface WalletListData {
  ref_currency: string;
  totals: WalletTotals;
  wallets: WalletState[];
}

/** Taux de conversion EUR de référence (MVP : taux fixe). */
export interface WalletRatesData {
  base: string;
  fx_rate_xaf: number;
  updated_at: string;
}

export interface WalletTx {
  id: number;
  type: 'send' | 'receive' | 'fx' | 'convert';
  direction: 'in' | 'out' | 'fx';
  label: string;
  description: string | null;
  amount: number;
  currency: string;
  status: 'completed' | 'processing' | 'pending' | 'failed' | 'cancelled';
  provider: string | null;
  destination: string | null;
  created_at: string;
}

export interface WalletTxListData {
  currency: string;
  limit: number;
  items: WalletTx[];
}

export type NotificationType = 'transfert' | 'quote' | 'kyc' | 'securite' | 'business' | 'systeme';

export interface ApiNotification {
  id: number;
  type: NotificationType;
  title: string;
  message: string | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}

export interface NotificationsListData {
  items: ApiNotification[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  unread_count: number;
}

// --- Comptes (Sources & Destinations) -----------------------------------------

export type AccountRole = 'source' | 'destination';
export type AccountKind = 'bank_iban' | 'mobile_money' | 'crypto_wallet' | 'card' | 'virtual_iban' | 'cash_pickup';

export interface PaymentAccount {
  id: number;
  role: AccountRole;
  kind: AccountKind;
  label: string;
  holder_name: string | null;
  country: string | null;
  currency: string | null;
  operator: string | null;
  network: string | null;
  city: string | null;
  iban_masked: string | null;
  bic: string | null;
  phone_masked: string | null;
  pan_masked: string | null;
  expiry: string | null;
  address_masked: string | null;
  is_default: boolean;
  verification_status: 'unverified' | 'pending' | 'verified' | 'rejected';
  supported_for_transfer: boolean;
  status: 'active' | 'inactive' | 'suspended';
  provider_slug: string | null;
  created_at: string;
  updated_at: string;
}

export interface AccountsListData {
  items: PaymentAccount[];
  total: number;
}

export interface AccountPayload {
  role: AccountRole;
  kind: AccountKind;
  label: string;
  holder_name?: string;
  country?: string;
  currency?: string;
  operator?: string;
  network?: string;
  city?: string;
  iban?: string;
  bic?: string;
  phone?: string;
  pan?: string;
  expiry?: string;
  address?: string;
  is_default?: boolean;
}

// --- Providers ----------------------------------------------------------------

export interface ProviderField {
  key: string;
  label: string;
  required: boolean;
  type: string;
  placeholder?: string;
  has_value?: boolean;
}

export interface ProviderCatalogItem {
  slug: string;
  name: string;
  category: string;
  icon: string;
  auth_type: string;
  doc_url: string;
  countries: string[];
  base_url: string;
  sandbox_url: string | null;
  fields: ProviderField[];
}

export interface ProviderCatalogData {
  categories: Record<string, { label: string; icon: string; description: string }>;
  providers: ProviderCatalogItem[];
  total: number;
}

export interface ProviderCredentialState {
  id?: number;
  provider_slug: string;
  environment: 'sandbox' | 'production';
  status: 'not_configured' | 'sandbox_only' | 'active' | 'error';
  has_credentials: boolean;
  last_tested_at: string | null;
  last_error: string | null;
  created_at: string;
  updated_at: string;
}

export interface ProviderCredentialsListData {
  items: ProviderCredentialState[];
  total: number;
}

export interface MobileMoneyOperatorsData {
  country: string;
  operators: string[];
}

// --- Origines autorisées (calculées côté backend) -------------------------

/** Source de financement vérifiée associée à une origine. */
export interface AuthorizedOriginSource {
  id: number;
  kind: AccountKind;
  kindLabel: string;
  label: string;
  country: string | null;
  currency: string | null;
  operator: string | null;
  isDefault: boolean;
}

/** Origine autorisée pour l'utilisateur. */
export interface AuthorizedOrigin {
  country: string;
  countryName: string;
  flag: string;
  currency: string;
  sources: AuthorizedOriginSource[];
}

/** Données retournées par GET /api/intent/authorized-origins. */
export interface AuthorizedOriginsData {
  origins: AuthorizedOrigin[];
  total: number;
  country_of_residence: string | null;
  kyc_level: string;
}

// --- Intent Engine (couverture pays / modes / taux) -------------------------

/** Mode de réception disponible pour un pays + devise. */
export interface IntentMethod {
  type: string;           // mobile_money | bank | crypto | cash_pickup
  label: string;
  icon: string;
  providers: string[];
  operators: string[] | null;
}

/** Devise disponible dans un pays donné. */
export interface IntentCurrency {
  code: string;
  name: string;
  symbol: string;
  methods: IntentMethod[];
}

/** Pays supporté par au moins un provider. */
export interface IntentCountry {
  code: string;
  name: string;
  flag: string;
  dial: string;
  currencies: IntentCurrency[];
}

/** Données complètes retournées par GET /api/intent/countries. */
export interface IntentCoverageData {
  countries: IntentCountry[];
  source_currencies: string[];
  crypto_networks: string[];
  rates: Record<string, number>;
  fee_estimate_pct: number;
}

// --- Quote & Routing Engine ------------------------------------------------

/** Route individuelle retournée par le Routing Engine. */
export interface QuoteRoute {
  id: string;              // A, B, C
  badge: string;           // ⭐ RECOMMANDÉE, ⚡ PLUS RAPIDE, etc.
  badgeCls: string;        // p-gr, p-c, p-g, p-v
  provider: string;        // Nom du provider
  providerSlug: string;
  method: string;          // 📱 Mobile Money, 🏦 Virement, etc.
  methodIcon: string;
  received: string;        // "327 000 XAF"
  receivedNum: number;
  fees: string;            // "2,90 EUR"
  feesNum: number;
  delay: string;           // "~3 min"
  delayMinutes: number;
  reliability: string;     // "Élevée", "Bonne", etc.
  reliabilityNum: number;
  reliabilityColor: string;
  recommended: boolean;
  spread: string;          // "0.35%"
  rate: number;
}

/** Intention normalisée retournée par la quote. */
export interface QuoteIntent {
  amount: number;
  sourceCurrency: string;
  originCountry: string;
  destCountry: string;
  destCurrency: string;
  receivingMethod: string;
  objective: string;
}

/** Données complètes d'une quote. */
export interface QuoteData {
  id: string;
  routes: QuoteRoute[];
  expires_at: string;       // ISO 8601
  ttl_seconds: number;
  intent: QuoteIntent;
  created_at: string;
}

/** Réponse de GET /api/quotes/:id (inclut le statut et remaining). */
export interface QuoteDetailData {
  id: string;
  status: 'QUOTED' | 'SELECTED' | 'EXECUTED' | 'EXPIRED' | 'CANCELLED';
  routes: QuoteRoute[];
  remaining: number;        // secondes restantes
  expired: boolean;
  expires_at: string;
  created_at: string;
  intent: QuoteIntent;
  message?: string;         // message si expiré
}

// --- Quote & Routing Engine ------------------------------------------------

/**
 * Crée une quote en soumettant l'intention au pipeline complet.
 * POST /api/quotes
 */
export async function apiCreateQuote(payload: {
  amount: number;
  sourceCurrency: string;
  originCountry: string;
  destCountry: string;
  destCurrency: string;
  receivingMethod: string;
  objective?: string;
}): Promise<ApiResponse<QuoteData>> {
  return request<QuoteData>('POST', '/quotes', payload as unknown as Record<string, unknown>);
}

/**
 * Récupère une quote existante par son ID.
 * GET /api/quotes/:id
 */
export async function apiGetQuote(id: string): Promise<ApiResponse<QuoteDetailData>> {
  return request<QuoteDetailData>('GET', `/quotes/${encodeURIComponent(id)}`);
}

// --- Helpers -----------------------------------------------------------------

async function request<T = unknown>(
  method: string,
  path: string,
  body?: Record<string, unknown>,
): Promise<ApiResponse<T>> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  try {
    const resp = await fetch(`${API_PREFIX}${path}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    const json: ApiResponse<T> = await resp.json();

    // Si 401 → session expirée/falsifiée → nettoyage
    if (resp.status === 401) {
      setToken(null);
      localStorage.removeItem('nexus_user');
    }

    return json;
  } catch {
    // Erreur réseau / serveur indisponible
    return { success: false, error: 'Service temporairement indisponible.' };
  }
}

// --- Fonctions publiques ---------------------------------------------------

/** Inscription classique (email/mot de passe). */
export async function apiRegister(payload: {
  full_name: string;
  email: string;
  password: string;
  account_type: 'personal' | 'business';
  phone_code?: string;
  phone?: string;
}): Promise<ApiResponse<{ token: string; user: ApiUser }>> {
  const data = await request<{ token: string; user: ApiUser }>('POST', '/register', payload as Record<string, unknown>);
  if (data.success && data.data) {
    setToken(data.data.token);
    localStorage.setItem('nexus_user', JSON.stringify(data.data.user));
  }
  return data;
}

/** Connexion classique (identifiant + mot de passe). */
export async function apiLogin(identifier: string, password: string): Promise<ApiResponse<{ token: string; user: ApiUser }>> {
  const data = await request<{ token: string; user: ApiUser }>('POST', '/login', { identifier, password });
  if (data.success && data.data) {
    setToken(data.data.token);
    localStorage.setItem('nexus_user', JSON.stringify(data.data.user));
  }
  return data;
}

/** Connexion / inscription via Google OAuth (ID token). */
export async function apiGoogleAuth(credential: string): Promise<ApiResponse<{ token: string; user: ApiUser }>> {
  const data = await request<{ token: string; user: ApiUser }>('POST', '/google', { credential });
  if (data.success && data.data) {
    setToken(data.data.token);
    localStorage.setItem('nexus_user', JSON.stringify(data.data.user));
  }
  return data;
}

/** Récupère le profil de l'utilisateur connecté (GET /api/me). */
export async function apiMe(): Promise<ApiResponse<{ user: ApiUser }>> {
  const data = await request<{ user: ApiUser }>('GET', '/me');
  if (data.success && data.data) {
    localStorage.setItem('nexus_user', JSON.stringify(data.data.user));
  }
  return data;
}

/** Déconnexion (révocation du token côté serveur). */
export async function apiLogout(): Promise<void> {
  await request('POST', '/logout');
  setToken(null);
  localStorage.removeItem('nexus_user');
}

/** Résumé complet du dashboard (GET /api/dashboard/summary). */
export async function apiDashboardSummary(): Promise<ApiResponse<DashboardSummaryData>> {
  return request<DashboardSummaryData>('GET', '/dashboard/summary');
}

/** Activité temporelle du dashboard (GET /api/dashboard/activity?period=30d). */
export async function apiDashboardActivity(period: '7d' | '30d' | '12m' = '30d'): Promise<ApiResponse<DashboardActivityData>> {
  return request<DashboardActivityData>('GET', `/dashboard/activity?period=${period}`);
}

/** Soldes du portefeuille de l'utilisateur (GET /api/wallets). */
export async function apiWalletsList(): Promise<ApiResponse<WalletListData>> {
  return request<WalletListData>('GET', '/wallets');
}

/** Taux de conversion EUR de référence (GET /api/wallets/rates). */
export async function apiWalletRates(): Promise<ApiResponse<WalletRatesData>> {
  return request<WalletRatesData>('GET', '/wallets/rates');
}

/** Historique rapide d'une devise (GET /api/wallets/{currency}/transactions). */
export async function apiWalletTransactions(currency: string): Promise<ApiResponse<WalletTxListData>> {
  return request<WalletTxListData>('GET', `/wallets/${encodeURIComponent(currency)}/transactions`);
}

/** Liste paginée des notifications (GET /api/notifications). */
export async function apiNotificationsList(params?: {
  type?: NotificationType;
  unread?: boolean;
  page?: number;
  per_page?: number;
}): Promise<ApiResponse<NotificationsListData>> {
  const qs = new URLSearchParams();
  if (params?.type) qs.set('type', params.type);
  if (params?.unread !== undefined) qs.set('unread', params.unread ? '1' : '0');
  if (params?.page) qs.set('page', String(params.page));
  if (params?.per_page) qs.set('per_page', String(params.per_page));
  const suffix = qs.toString() ? `?${qs.toString()}` : '';
  return request<NotificationsListData>('GET', `/notifications${suffix}`);
}

/** Nombre de notifications non lues (GET /api/notifications/unread-count). */
export async function apiNotificationsUnreadCount(): Promise<ApiResponse<{ unread_count: number }>> {
  return request<{ unread_count: number }>('GET', '/notifications/unread-count');
}

/** Marque une notification comme lue (POST /api/notifications/:id/read). */
export async function apiNotificationsRead(id: number): Promise<ApiResponse<{ id: number; read: boolean; unread_count: number }>> {
  return request<{ id: number; read: boolean; unread_count: number }>('POST', `/notifications/${id}/read`);
}

/** Marque toutes les notifications comme lues (POST /api/notifications/read-all). */
export async function apiNotificationsReadAll(): Promise<ApiResponse<{ updated: number; unread_count: number }>> {
  return request<{ updated: number; unread_count: number }>('POST', '/notifications/read-all');
}

// --- Accounts (Sources & Destinations) --------------------------------------

/** Liste les comptes de l'utilisateur (filtrés par rôle). */
export async function apiAccountsList(role?: 'source' | 'destination'): Promise<ApiResponse<AccountsListData>> {
  const qs = role ? `?role=${role}` : '';
  return request<AccountsListData>('GET', `/accounts${qs}`);
}

/** Crée un nouveau compte. */
export async function apiAccountsCreate(payload: AccountPayload): Promise<ApiResponse<PaymentAccount>> {
  return request<PaymentAccount>('POST', '/accounts', payload as unknown as Record<string, unknown>);
}

/** Met à jour un compte. */
export async function apiAccountsUpdate(id: number, payload: Partial<AccountPayload>): Promise<ApiResponse<PaymentAccount>> {
  return request<PaymentAccount>('PUT', `/accounts/${id}`, payload as unknown as Record<string, unknown>);
}

/** Supprime un compte. */
export async function apiAccountsDelete(id: number): Promise<ApiResponse<{ id: number; deleted: boolean }>> {
  return request<{ id: number; deleted: boolean }>('DELETE', `/accounts/${id}`);
}

/** Définit un compte comme étant le défaut du même rôle. */
export async function apiAccountsSetDefault(id: number): Promise<ApiResponse<{ id: number; role: string; is_default: boolean }>> {
  return request<{ id: number; role: string; is_default: boolean }>('POST', `/accounts/${id}/default`);
}

/** Liste les opérateurs Mobile Money disponibles pour un pays ISO-2. */
export async function apiMobileMoneyOperators(country: string): Promise<ApiResponse<MobileMoneyOperatorsData>> {
  return request<MobileMoneyOperatorsData>('GET', `/accounts/operators?country=${encodeURIComponent(country)}`);
}

/** Réseaux blockchain supportés. */
export async function apiCryptoNetworks(): Promise<ApiResponse<{ networks: string[] }>> {
  return request<{ networks: string[] }>('GET', '/accounts/networks');
}

// --- Providers ---------------------------------------------------------------

/** Catalogue complet des providers + catégories. */
export async function apiProvidersCatalog(): Promise<ApiResponse<ProviderCatalogData>> {
  return request<ProviderCatalogData>('GET', '/providers');
}

/** État des credentials configurés par l'utilisateur. */
export async function apiProviderCredentialsList(): Promise<ApiResponse<ProviderCredentialsListData>> {
  return request<ProviderCredentialsListData>('GET', '/providers/credentials');
}

/** Enregistre (upsert) les credentials d'un provider. */
export async function apiProviderCredentialsUpsert(
  slug: string,
  environment: 'sandbox' | 'production',
  credentials: Record<string, string>,
): Promise<ApiResponse<{ provider_slug: string; environment: string; status: string; updated: boolean }>> {
  return request<{ provider_slug: string; environment: string; status: string; updated: boolean }>(
    'PUT',
    `/providers/${slug}/credentials`,
    { environment, credentials } as unknown as Record<string, unknown>,
  );
}

/** Supprime les credentials d'un provider. */
export async function apiProviderCredentialsDelete(slug: string): Promise<ApiResponse<{ provider_slug: string; deleted: boolean }>> {
  return request<{ provider_slug: string; deleted: boolean }>('DELETE', `/providers/${slug}/credentials`);
}

/** Test de connectivité TCP simple vers la base URL. */
export async function apiProviderTest(slug: string): Promise<ApiResponse<{
  provider_slug: string;
  reachable: boolean;
  latency_ms: number;
  error: string | null;
  environment: string;
}>> {
  return request<{
    provider_slug: string;
    reachable: boolean;
    latency_ms: number;
    error: string | null;
    environment: string;
  }>('POST', `/providers/${slug}/test`);
}

// --- Intent Engine ------------------------------------------------------------

/**
 * Récupère la couverture mondiale des providers (pays, devises,
 * modes de réception, opérateurs, taux, frais estimés).
 * Couverture DESTINATION — les origines sont sur authorizedOrigins().
 */
export async function apiIntentCoverage(): Promise<ApiResponse<IntentCoverageData>> {
  return request<IntentCoverageData>('GET', '/intent/countries');
}

/**
 * Récupère les origines autorisées pour l'utilisateur connecté.
 * GET /api/intent/authorized-origins
 *
 * Ces origines sont calculées côté backend à partir des sources
 * de financement vérifiées de l'utilisateur.
 */
export async function apiAuthorizedOrigins(): Promise<ApiResponse<AuthorizedOriginsData>> {
  return request<AuthorizedOriginsData>('GET', '/intent/authorized-origins');
}

/**
 * Calcule une estimation de conversion entre deux devises à partir
 * des taux retournés par /intent/countries (1 EUR = X).
 */
export function estimateConvert(
  amount: number,
  fromCurrency: string,
  toCurrency: string,
  rates: Record<string, number>,
): number {
  if (!amount || amount <= 0) return 0;
  const fromEUR = rates[fromCurrency] ?? 1;
  const toEUR = rates[toCurrency] ?? 1;
  // Convert from → EUR → to
  const inEur = amount / fromEUR;
  return inEur * toEUR;
}

// --- Hold Lifecycle (GET/POST /api/wallets/holds*) ---------------------------

/** Hold renvoyé par GET /api/wallets/holds (montant = string décimale, jamais de float). */
export interface PendingHold {
  operation_id: string;
  wallet_id: number;
  amount: string;
  currency: string;
  status: 'pending' | 'completed' | 'cancelled';
  created_at: string;
  expires_at: string | null;
  remaining_seconds: number | null;
}

export interface PendingHoldsData {
  holds: PendingHold[];
}

/** Réponse type des actions hold (capture / release). */
export interface HoldActionResult {
  operation_id: string;
  status: 'completed' | 'cancelled';
}

/** Liste des holds de l'utilisateur (GET /api/wallets/holds?status=pending). */
export async function apiPendingHolds(
  status: 'pending' | 'completed' | 'cancelled' = 'pending',
): Promise<ApiResponse<PendingHoldsData>> {
  return request<PendingHoldsData>('GET', `/wallets/holds?status=${status}`);
}

/** Capture un hold (POST /api/wallets/hold/capture). */
export async function apiCaptureHold(
  operationId: string,
  idempotencyKey?: string,
): Promise<ApiResponse<HoldActionResult>> {
  return request<HoldActionResult>('POST', '/wallets/hold/capture', {
    operation_id: operationId,
    idempotency_key: idempotencyKey ?? undefined,
  });
}

/** Libère un hold (POST /api/wallets/hold/release). */
export async function apiReleaseHold(
  operationId: string,
  idempotencyKey?: string,
): Promise<ApiResponse<HoldActionResult>> {
  return request<HoldActionResult>('POST', '/wallets/hold/release', {
    operation_id: operationId,
    idempotency_key: idempotencyKey ?? undefined,
  });
}

export { getToken, setToken };

// ============================================================================
// USER PROFILE API
// ============================================================================

/** Interface pour les données utilisateur complètes. */
export interface UserProfile {
  id: string;
  full_name: string | null;
  email: string;
  phone: string | null;
  account_type: 'personal' | 'business';
  auth_provider: 'local' | 'google';
  status: 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED';
  kyc_level: 'none' | 'basic' | 'standard' | 'advanced';
  created_at: string;
  updated_at: string;
}

/** Interface pour une session active. */
export interface UserSession {
  jti: string;
  device?: string;
  browser?: string;
  ip_address?: string;
  created_at: string;
  last_activity?: string;
  is_current: boolean;
}

/** Réponse de GET /api/users/me/sessions. */
export interface SessionsListData {
  sessions: UserSession[];
  revoked_count: number;
}

/** Payload pour PUT /api/users/me. */
export interface UpdateProfilePayload {
  full_name?: string;
  phone?: string;
  country_of_residence?: string;
}

/** Payload pour PUT /api/users/me/password. */
export interface UpdatePasswordPayload {
  current_password: string;
  new_password: string;
  confirm_password: string;
}

/** Récupère le profil complet de l'utilisateur (GET /api/users/me). */
export async function apiGetUserProfile(): Promise<ApiResponse<{ user: UserProfile }>> {
  return request<{ user: UserProfile }>('GET', '/users/me');
}

/** Met à jour le profil utilisateur (PUT /api/users/me). */
export async function apiUpdateProfile(
  payload: UpdateProfilePayload,
): Promise<ApiResponse<{ updated: boolean }>> {
  return request<{ updated: boolean }>('PUT', '/users/me', payload);
}

/** Change le mot de passe (PUT /api/users/me/password). */
export async function apiUpdatePassword(
  payload: UpdatePasswordPayload,
): Promise<ApiResponse<{ updated: boolean }>> {
  return request<{ updated: boolean }>('PUT', '/users/me/password', payload);
}

/** Liste les sessions actives (GET /api/users/me/sessions). */
export async function apiGetSessions(): Promise<ApiResponse<SessionsListData>> {
  return request<SessionsListData>('GET', '/users/me/sessions');
}

/** Révoque une session (DELETE /api/users/me/sessions/{id}). */
export async function apiRevokeSession(jti: string): Promise<ApiResponse<{ revoked: boolean }>> {
  return request<{ revoked: boolean }>('DELETE', `/users/me/sessions/${jti}`);
}
