import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import {
  apiWalletsList,
  apiWalletRates,
  apiWalletTransactions,
  type WalletRatesData,
  type WalletState,
  type WalletTotals,
  type WalletTx,
} from '../../api/client';
import AccountsPanel from './AccountsPanel';
import PendingHolds from '../../components/dashboard/PendingHolds';
import { useI18n } from '../../context/I18nContext';

/**
 * WalletPage — Vue unifiée multi-devises (données réelles).
 *
 * - Soldes chargés depuis MySQL via GET /api/wallets (ventilés par état :
 *   available / pending / in_transit / settlement, visuellement distincts).
 * - Taux EUR de référence via GET /api/wallets/rates (MVP : 1 EUR = 655,957 XAF).
 * - Historique rapide de la devise sélectionnée via
 *   GET /api/wallets/{currency}/transactions (10 dernières).
 * - Onglets « Mes devises » / « Sources de financement » / « Destinations ».
 * - Sélectionner une devise met à jour l'aperçu et l'historique.
 *
 * Le style réutilise exclusivement les classes de dashboard-system.css
 * (cards, pills, dots, mode/account-tabs, mono) — aucun nouveau composant.
 */

type Tab = 'devises' | 'sources' | 'destinations';

// ─── Données statiques de présentation ───────────────────────────────────────

const CURRENCY_META: Record<string, { flag: string; symbol: string; crypto?: boolean; label: string }> = {
  EUR:  { flag: '🇪🇺', symbol: '€', label: 'Euro' },
  USD:  { flag: '🇺🇸', symbol: '$', label: 'US Dollar' },
  GBP:  { flag: '🇬🇧', symbol: '£', label: 'Livre Sterling' },
  XAF:  { flag: '🌍', symbol: 'XAF', label: 'Franc CFA (CEMAC)' },
  USDT: { flag: '🔵', symbol: 'USDT', crypto: true, label: 'Tether USD' },
  USDC: { flag: '🔵', symbol: 'USDC', crypto: true, label: 'USD Coin' },
};

/** Devises à venir : présentes dans le sélecteur mais grisées « Bientôt ». */
const COMING_SOON = [
  { cur: 'XOF', flag: '🌍', label: 'Franc CFA (UEMOA)' },
  { cur: 'NGN', flag: '🇳🇬', label: 'Naira nigérian' },
  { cur: 'GHS', flag: '🇬🇭', label: 'Cedi ghanéen' },
  { cur: 'KES', flag: '🇰🇪', label: 'Shilling kényan' },
  { cur: 'ETH', flag: '🔷', label: 'Ethereum' },
  { cur: 'BTC', flag: '🟠', label: 'Bitcoin' },
];

/** États d'un wallet : libellé + couleur du design system. */
const STATE_ROWS: Array<{ key: 'available' | 'pending' | 'in_transit' | 'settlement'; label: string; color: string }> = [
  { key: 'available',  label: 'Disponible', color: 'var(--green)' },
  { key: 'pending',    label: 'En attente', color: 'var(--gold)' },
  { key: 'in_transit', label: 'En transit', color: 'var(--cyan)' },
  { key: 'settlement', label: 'Règlement',  color: 'var(--violet)' },
];

/** Destinations disponibles (onglet « Destinations » — CRUD réel via AccountsPanel). */

// ─── Formatage (fr-FR, JetBrains Mono, aligné à droite) ──────────────────────

function formatCurrency(val: number, cur: string): string {
  const meta = CURRENCY_META[cur];
  const formatted = val.toLocaleString('fr-FR', {
    minimumFractionDigits: meta?.crypto || cur === 'EUR' || cur === 'USD' || cur === 'GBP' ? 2 : 0,
    maximumFractionDigits: 2,
  });
  if (cur === 'EUR') return `€ ${formatted}`;
  if (cur === 'USD') return `$ ${formatted}`;
  if (cur === 'GBP') return `£ ${formatted}`;
  if (cur === 'XAF') return `${formatted} XAF`;
  return `${formatted} ${cur}`;
}

/** Montant signé d'une transaction (+ entrée / − sortie). */
function txAmount(tx: WalletTx): string {
  const formatted = formatCurrency(tx.amount, tx.currency);
  if (tx.direction === 'in') return `+${formatted}`;
  if (tx.direction === 'out') return `−${formatted}`;
  return formatted;
}

function formatRelativeTime(isoString: string): string {
  const date = new Date(isoString);
  const now = new Date();
  const diffMinutes = Math.floor((now.getTime() - date.getTime()) / 60000);
  const diffHours = Math.floor(diffMinutes / 60);
  const diffDays = Math.floor(diffHours / 24);
  if (diffMinutes < 1) return "À l'instant";
  if (diffMinutes < 60) return `Il y a ${diffMinutes} min`;
  if (diffHours < 24) return `Il y a ${diffHours}h`;
  if (diffDays === 1) return 'Hier';
  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
}

/** Classe pill selon le statut d'une transaction. */
function statusPill(status: string): string {
  switch (status) {
    case 'completed': return 'p-gr';
    case 'processing': return 'p-c';
    case 'pending': return 'p-g';
    case 'failed': return 'p-r';
    case 'cancelled': return 'p-v';
    default: return 'p-c';
  }
}

// ─── Composant ───────────────────────────────────────────────────────────────

export default function WalletPage() {
  const [tab, setTab] = useState<Tab>('devises');

  // Données serveur
  const [wallets, setWallets] = useState<WalletState[] | null>(null);
  const [totals, setTotals] = useState<WalletTotals | null>(null);
  const [rates, setRates] = useState<WalletRatesData | null>(null);
  const [selected, setSelected] = useState<string>('EUR');
  const [txs, setTxs] = useState<WalletTx[] | null>(null);
  const [txsLoading, setTxsLoading] = useState(false);
  const [txsError, setTxsError] = useState<string | null>(null);

  // États de page
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [pickerOpen, setPickerOpen] = useState(false);
  const { t } = useI18n();

  const fetchAll = useCallback(async () => {
    setLoading(true);
    setError(null);
    const [walletRes, ratesRes] = await Promise.all([apiWalletsList(), apiWalletRates()]);
    if (!walletRes.success || !walletRes.data) {
      setError(walletRes.error || 'Erreur lors du chargement du portefeuille.');
      setLoading(false);
      return;
    }
    setWallets(walletRes.data.wallets);
    setTotals(walletRes.data.totals);
    // Le taux est secondaire : la page reste utilisable sans lui.
    if (ratesRes.success && ratesRes.data) setRates(ratesRes.data);
    setLoading(false);
  }, []);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  /** Historique rapide de la devise sélectionnée. */
  const fetchTxs = useCallback(async (currency: string) => {
    setTxsLoading(true);
    setTxsError(null);
    const resp = await apiWalletTransactions(currency);
    if (!resp.success || !resp.data) {
      setTxsError(resp.error || 'Erreur lors du chargement de l\'historique.');
      setTxs(null);
      setTxsLoading(false);
      return;
    }
    setTxs(resp.data.items);
    setTxsLoading(false);
  }, []);

  useEffect(() => {
    if (!wallets) return;
    fetchTxs(selected);
  }, [selected, wallets, fetchTxs]);

  /** Sélection d'une devise : met à jour l'aperçu + l'historique. */
  const handleSelect = (currency: string) => {
    setSelected(currency);
    setPickerOpen(false);
  };

  // --- États : chargement / erreur ------------------------------------------
  if (loading || !wallets || !totals) {
    return (
      <div className="page">
        <div className="shimmer-bg" style={{ height: 150, borderRadius: 20, marginBottom: 20 }} />
        <div className="shimmer-bg" style={{ height: 120, borderRadius: 16, marginBottom: 16 }} />
        <div className="shimmer-bg" style={{ height: 320, borderRadius: 16 }} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' }}>
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center', maxWidth: 460 }}>
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10 }}>Impossible de charger le portefeuille</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20 }}>{error}</p>
          <button className="btn btn-cyan" onClick={fetchAll}>↻ Réessayer</button>
        </div>
      </div>
    );
  }

  const selectedWallet = wallets.find((w) => w.currency === selected) ?? wallets[0];

  return (
    <div className="page">
      {/* En-tête : solde total (EUR de référence) + actions */}
      <div className="page-header animate-up">
        <div className="page-label">Nexus {t('side_wallet')} — {t('wallet_title')}</div>
        <div className="page-title">
          {t('wallet_title')}
        </div>
        <div style={{ marginTop: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 16 }}>
          <div>
            <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.15em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)' }}>
              {t('wallet_total_ref')}
            </div>
            <div style={{ fontSize: 34, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)', letterSpacing: '-1px', marginTop: 6 }}>
              {formatCurrency(totals.total_ref, 'EUR')}
            </div>
            <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 4 }}>
              {totals.currencies} {t('wallet_currencies')} · {totals.with_funds} {t('wallet_with_funds')}
            </div>
          </div>
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => setPickerOpen(true)}>+ {t('wallet_topup')}</button>
            <Link to="/send" className="btn btn-cyan" style={{ fontSize: 12, textDecoration: 'none' }}>↗ {t('wallet_send')}</Link>
          </div>
        </div>
      </div>

      {/* KPIs : totaux par état */}
      <div className="g4 animate-up delay-1" style={{ marginBottom: 24 }}>
        {[
          { label: t('wallet_total_portfolio'), val: formatCurrency(totals.total_ref, 'EUR'), color: 'var(--cyan)', sub: 'Équivalent EUR' },
          { label: t('dash_available'), val: formatCurrency(totals.available_ref, 'EUR'), color: 'var(--green)', sub: t('wallet_avail_desc') },
          { label: t('dash_pending'), val: formatCurrency(totals.pending_ref, 'EUR'), color: 'var(--gold)', sub: t('wallet_pend_desc') },
          { label: t('dash_transit'), val: formatCurrency(totals.in_transit_ref, 'EUR'), color: 'var(--violet)', sub: t('wallet_transit_desc') },
        ].map((s) => (
          <div key={s.label} className="card stat-card">
            <div className="stat-label">{s.label}</div>
            <div className="stat-value" style={{ fontSize: 24, color: s.color }}>{s.val}</div>
            <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{s.sub}</div>
          </div>
        ))}
      </div>

      {/* Onglets : Mes devises / Sources / Destinations */}
      <div className="account-tabs animate-up delay-1" style={{ marginBottom: 20, width: 'fit-content' }}>
        {([
          ['devises', '💱 ' + t('wallet_tab_devises')],
          ['sources', '🏦 ' + t('wallet_tab_sources')],
          ['destinations', '📍 ' + t('wallet_tab_destinations')],
        ] as const).map(([id, label]) => (
          <button
            key={id}
            className={`account-tab ${tab === id ? 'active-personal' : ''}`}
            onClick={() => setTab(id)}
            style={{ fontSize: 11, fontWeight: 600 }}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'devises' && (
        <div className="animate-up delay-2">
          {/* ─── Réservations en cours (holds pending) ─── */}
          <PendingHolds />
          {/* Section : soldes par devise + ajout de devise */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10, marginBottom: 14 }}>
            <div>
              <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet_balances_title')}</div>
              <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
                {totals.currencies} devises · {totals.with_funds} {t('wallet_actives')}
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              {rates && (
                <span className="pill p-g" style={{ fontSize: 8 }}>
                  Taux FX · 1 EUR = {rates.fx_rate_xaf.toLocaleString('fr-FR', { maximumFractionDigits: 3 })} XAF
                </span>
              )}
              <button
                className="btn btn-ghost"
                style={{ fontSize: 11 }}
                onClick={() => setPickerOpen((v) => !v)}
              >
                {pickerOpen ? '✕ ' + t('wallet_close') : '+ ' + t('wallet_add_currency')}
              </button>
            </div>
          </div>

          {/* Sélecteur d'ajout de devise */}
          {pickerOpen && (
            <div className="card animate-fade" style={{ padding: 20, marginBottom: 20 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
                <div>
                  <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet_add_currency')}</div>
                  <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>
                    Choisissez une devise pour mettre à jour l'aperçu et l'historique.
                  </div>
                </div>
                <button className="btn btn-ghost" style={{ fontSize: 10 }} onClick={() => setPickerOpen(false)}>✕ {t('wallet_close')}</button>
              </div>

              <div className="g2" style={{ gap: 16 }}>
                <div>
                  <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.15em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 8 }}>
                    {t('dash_available')}
                  </div>
                  <div className="g2" style={{ gap: 8 }}>
                    {wallets.map((w) => {
                      const meta = CURRENCY_META[w.currency] ?? { flag: '🌐', symbol: w.currency, label: w.currency };
                      return (
                        <button
                          key={w.currency}
                          onClick={() => handleSelect(w.currency)}
                          style={{
                            display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer',
                            padding: '10px 12px', borderRadius: 10, textAlign: 'left',
                            background: selected === w.currency ? 'rgba(0,200,255,0.06)' : 'var(--panel2)',
                            border: `1px solid ${selected === w.currency ? 'rgba(0,200,255,0.35)' : 'var(--border)'}`,
                            transition: 'border-color 0.15s',
                          }}
                        >
                          <span style={{ fontSize: 20 }}>{meta.flag}</span>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>{w.currency}</div>
                            <div style={{ fontSize: 9, color: 'var(--text-dim)' }}>{meta.label}</div>
                          </div>
                          {w.has_funds && <span className="pill p-gr" style={{ fontSize: 7, flexShrink: 0 }}>Actif</span>}
                          {selected === w.currency && <span className="pill p-c" style={{ fontSize: 7, flexShrink: 0 }}>{t('wallet_selected')}</span>}
                        </button>
                      );
                    })}
                  </div>
                </div>

                <div>
                  <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.15em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 8 }}>
                    {t('wallet_soon_avail')}
                  </div>
                  <div className="g2" style={{ gap: 8 }}>
                    {COMING_SOON.map((c) => (
                      <div
                        key={c.cur}
                        style={{
                          display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px',
                          borderRadius: 10, background: 'var(--panel2)', border: '1px solid var(--border-soft)',
                          opacity: 0.45, cursor: 'not-allowed',
                        }}
                      >
                        <span style={{ fontSize: 20 }}>{c.flag}</span>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-dim)' }}>{c.cur}</div>
                          <div style={{ fontSize: 9, color: 'var(--text-dim)' }}>{c.label}</div>
                        </div>
                        <span className="pill" style={{ fontSize: 7, flexShrink: 0 }}>{t('wallet_soon')}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Grille des wallets par devise (selectionnables) */}
          <div className="g3" style={{ gap: 14 }}>
            {wallets.map((w) => {
              const meta = CURRENCY_META[w.currency] ?? { flag: '🌐', symbol: w.currency, label: w.currency };
              const pct = w.balance > 0 ? Math.min(100, Math.round((w.available / w.balance) * 100)) : 0;
              const isSelected = selected === w.currency;
              return (
                <div
                  key={w.currency}
                  className="card"
                  onClick={() => handleSelect(w.currency)}
                  style={{
                    padding: 18,
                    cursor: 'pointer',
                    borderColor: isSelected ? 'rgba(0,200,255,0.45)' : undefined,
                    transition: 'border-color 0.2s, transform 0.2s',
                  }}
                  onMouseEnter={(e) => {
                    if (!isSelected) {
                      e.currentTarget.style.borderColor = 'rgba(0,200,255,0.2)';
                      e.currentTarget.style.transform = 'translateY(-2px)';
                    }
                  }}
                  onMouseLeave={(e) => {
                    if (!isSelected) {
                      e.currentTarget.style.borderColor = '';
                      e.currentTarget.style.transform = '';
                    }
                  }}
                >
                  {/* Ligne supérieure : flag + badges */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <span style={{ fontSize: 26 }}>{meta.flag}</span>
                      <div>
                        <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-bright)' }}>{w.currency}</div>
                        <div style={{ fontSize: 9, color: 'var(--text-dim)' }}>{meta.label}</div>
                      </div>
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 6 }}>
                      {isSelected && <span className="pill p-c" style={{ fontSize: 7 }}>✓ {t('wallet_selected')}</span>}
                      <div className={`pill ${meta.crypto ? 'p-v' : 'p-c'}`} style={{ fontSize: 7, height: 22, flexShrink: 0 }}>
                        {meta.crypto ? 'STABLECOIN' : 'FIAT'}
                      </div>
                    </div>
                  </div>

                  {/* Solde principal */}
                  <div style={{
                    fontSize: 22, fontWeight: 800, color: 'var(--white)',
                    fontFamily: 'var(--font-mono)', letterSpacing: '-0.5px', marginBottom: 12,
                  }}>
                    {formatCurrency(w.balance, w.currency)}
                  </div>

                  {/* Barre de progression (part disponible) */}
                  <div style={{ height: 5, background: 'var(--panel2)', borderRadius: 3, overflow: 'hidden', marginBottom: 12 }}>
                    <div style={{
                      height: '100%', width: `${pct}%`, borderRadius: 3,
                      background: meta.crypto
                        ? 'linear-gradient(90deg, var(--violet), #C060FF)'
                        : 'linear-gradient(90deg, var(--cyan2), var(--cyan))',
                      transition: 'width 0.8s ease',
                    }} />
                  </div>

                  {/* Ventilation par état (visuellement distincte) */}
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {STATE_ROWS.map((row) => (
                      <div key={row.key} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 9, color: 'var(--text-dim)' }}>
                          <span className="dot" style={{ background: row.color }} />
                          {row.key === 'available' ? t('dash_available') : row.key === 'pending' ? t('dash_pending') : row.key === 'in_transit' ? t('dash_transit') : t('dash_settlement')}
                        </span>
                        <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>
                          {formatCurrency(w[row.key], w.currency)}
                        </span>
                      </div>
                    ))}
                  </div>

                  {/* Pied : % disponible + équivalent EUR */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 10, paddingTop: 10, borderTop: '1px solid var(--border-soft)' }}>
                    <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {pct}% {t('dash_available').toLowerCase()}
                    </span>
                    <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {w.has_funds ? `≈ ${formatCurrency(w.ref_equivalent, 'EUR')}` : t('wallet_no_funds')}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>

          {/* Aperçu du wallet sélectionné + historique rapide */}
          <div className="g2 animate-up delay-3" style={{ alignItems: 'start', gap: 20, marginTop: 20 }}>
            {/* ─── Aperçu ─── */}
            <div className="card card-hi-c" style={{ padding: 22 }}>
              <div className="page-label" style={{ marginBottom: 12 }}>{t('wallet_preview')}</div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <span style={{ fontSize: 26 }}>{(CURRENCY_META[selectedWallet.currency] ?? {}).flag ?? '🌐'}</span>
                  <div>
                    <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text-bright)' }}>{selectedWallet.currency}</div>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>
                      {CURRENCY_META[selectedWallet.currency]?.label ?? selectedWallet.currency}
                    </div>
                  </div>
                </div>
                <div className={`pill ${CURRENCY_META[selectedWallet.currency]?.crypto ? 'p-v' : 'p-c'}`} style={{ fontSize: 8, flexShrink: 0 }}>
                  {CURRENCY_META[selectedWallet.currency]?.crypto ? 'STABLECOIN' : 'FIAT'}
                </div>
              </div>

              <div style={{
                fontSize: 30, fontWeight: 800, color: 'var(--white)',
                fontFamily: 'var(--font-mono)', letterSpacing: '-1px', marginBottom: 4,
              }}>
                {formatCurrency(selectedWallet.balance, selectedWallet.currency)}
              </div>
              <div style={{ fontSize: 10, color: 'var(--text-dim)', marginBottom: 18 }}>
                {selectedWallet.currency !== 'EUR' && selectedWallet.has_funds
                  ? `≈ ${formatCurrency(selectedWallet.ref_equivalent, 'EUR')}`
                  : t('wallet_book_bal')}
              </div>

              {/* Ventilation par état */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                {STATE_ROWS.map((row) => (
                  <div className="trow" key={row.key} style={{ padding: '7px 0' }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: 'var(--text-mid)' }}>
                      <span className="dot" style={{ background: row.color }} />
                      {row.key === 'available' ? t('dash_available') : row.key === 'pending' ? t('dash_pending') : row.key === 'in_transit' ? t('dash_transit') : t('dash_settlement')}
                    </span>
                    <span style={{ fontSize: 12, fontWeight: 700, color: row.color, fontFamily: 'var(--font-mono)' }}>
                      {formatCurrency(selectedWallet[row.key], selectedWallet.currency)}
                    </span>
                  </div>
                ))}
                <div className="trow" style={{ padding: '8px 0 0' }}>
                  <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: 'var(--text-mid)' }}>💼 {t('wallet_total_portfolio')}</span>
                  <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>
                    {formatCurrency(selectedWallet.balance, selectedWallet.currency)}
                  </span>
                </div>
              </div>

              {rates && (
                <div style={{ marginTop: 16, paddingTop: 12, borderTop: '1px solid var(--border-soft)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span style={{ fontSize: 10, color: 'var(--text-dim)' }}>Taux EUR de référence</span>
                  <span className="mono" style={{ fontSize: 11, color: 'var(--gold)' }}>
                    1 EUR = {rates.fx_rate_xaf.toLocaleString('fr-FR', { maximumFractionDigits: 3 })} XAF
                  </span>
                </div>
              )}
            </div>

            {/* ─── Historique rapide ─── */}
            <div className="card" style={{ padding: 22 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
                <div>
                  <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet_recent_hist')}</div>
                  <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
                    {t('wallet_last_txs')} · {selectedWallet.currency}
                  </div>
                </div>
                <Link to="/history" className="btn btn-ghost" style={{ fontSize: 10, textDecoration: 'none' }}>{t('wallet_see_all')} →</Link>
              </div>

              {txsLoading ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {[0, 1, 2, 3, 4].map((i) => (
                    <div key={i} className="shimmer-bg" style={{ height: 48, borderRadius: 10 }} />
                  ))}
                </div>
              ) : txsError ? (
                <div className="card" style={{ padding: 20, textAlign: 'center' }}>
                  <div style={{ fontSize: 12, color: 'var(--text-mid)', marginBottom: 12 }}>{txsError}</div>
                  <button className="btn btn-cyan" style={{ fontSize: 11 }} onClick={() => fetchTxs(selected)}>↻ {t('dash_retry')}</button>
                </div>
              ) : !txs || txs.length === 0 ? (
                <div style={{ padding: 32, textAlign: 'center' }}>
                  <div style={{ fontSize: 28, marginBottom: 10 }}>🕘</div>
                  <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>{t('wallet_no_tx')}</div>
                  <p style={{ fontSize: 11, color: 'var(--text-mid)', marginTop: 6 }}>
                    {t('wallet_no_op')} {selectedWallet.currency} {t('wallet_no_op_suffix')}
                  </p>
                </div>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                  {txs.map((tx) => {
                    const isIn = tx.direction === 'in';
                    const isFx = tx.direction === 'fx';
                    return (
                      <div key={tx.id} className="tx-row" style={{ padding: '10px 0' }}>
                        <div className={`ib ${isIn ? 'ib-gr' : isFx ? 'ib-v' : 'ib-c'}`} style={{ width: 36, height: 36, borderRadius: 9, fontSize: 15 }}>
                          {isIn ? '↙' : isFx ? '⇌' : '↗'}
                        </div>
                        <div className="tx-info">
                          <div className="tx-name">{tx.label}</div>
                          <div className="tx-meta">
                            {formatRelativeTime(tx.created_at)}
                            {tx.provider ? ` · ${tx.provider}` : ''}
                          </div>
                        </div>
                        <div style={{ textAlign: 'right' }}>
                          <div
                            className="tx-amount"
                            style={{ color: isIn ? 'var(--green)' : isFx ? 'var(--violet)' : 'var(--cyan)' }}
                          >
                            {txAmount(tx)}
                          </div>
                          <div className={`pill ${statusPill(tx.status)}`} style={{ marginTop: 4, fontSize: 7 }}>
                            {tx.status}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {tab === 'sources' && (
        <div className="animate-up delay-2">
          <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet_tab_sources')}</div>
          <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 14 }}>
            Comptes desquels vous envoyez de l'argent
          </div>
          <AccountsPanel role="source" />
        </div>
      )}

      {tab === 'destinations' && (
        <div className="animate-up delay-2">
          <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet_tab_destinations')}</div>
          <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 14 }}>
            Comptes vers lesquels vous recevez de l'argent
          </div>
          <AccountsPanel role="destination" />
        </div>
      )}
    </div>
  );
}
