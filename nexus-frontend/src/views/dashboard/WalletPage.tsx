import { useState, useEffect, useCallback } from 'react';
import { Link, useSearchParams, useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { ScrollReveal, TiltCard, EASE } from '../../components/anim/Premium';
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
import AnimatedCounter from '../../components/AnimatedCounter';
import EmptyState from '../../components/EmptyState';
import { useI18n } from '../../context/I18nContext';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import { CurrencyLogo } from '../../components/dashboard/CurrencyLogo';
import AddFundsModal from '../../components/dashboard/AddFundsModal';

/**
 * WalletPage — Vue unifiée multi-devises (données réelles).
 *
 * - Soldes chargés depuis MySQL via GET /api/wallets (ventilés par état :
 *   available / pending / in_transit / settlement, visuellement distincts).
 * - Taux EUR de référence via GET /api/wallets/rates (source FX réelle,
 *   jamais de taux codé en dur ; « indisponible » si non configurée).
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

const CURRENCY_META: Record<string, { flag: string; symbol: string; crypto?: boolean; stablecoin?: boolean; label: string }> = {
  EUR:  { flag: '🇪🇺', symbol: '€', label: 'Euro' },
  USD:  { flag: '🇺🇸', symbol: '$', label: 'US Dollar' },
  GBP:  { flag: '🇬🇧', symbol: '£', label: 'Livre Sterling' },
  XAF:  { flag: '🌍', symbol: 'XAF', label: 'Franc CFA (CEMAC)' },
  USDT: { flag: '🔵', symbol: 'USDT', crypto: true, stablecoin: true, label: 'Tether USD' },
  USDC: { flag: '🔵', symbol: 'USDC', crypto: true, stablecoin: true, label: 'USD Coin' },
  ETH:  { flag: '🔷', symbol: 'ETH', crypto: true, label: 'Ethereum' },
  BTC:  { flag: '🟠', symbol: 'BTC', crypto: true, label: 'Bitcoin' },
};

/** Devises à venir : présentes dans le sélecteur mais grisées « Bientôt ». */
const COMING_SOON = [
  { cur: 'XOF', flag: '🌍', label: 'Franc CFA (UEMOA)' },
  { cur: 'NGN', flag: '🇳🇬', label: 'Naira nigérian' },
  { cur: 'GHS', flag: '🇬🇭', label: 'Cedi ghanéen' },
  { cur: 'KES', flag: '🇰🇪', label: 'Shilling kényan' },
];

/** États d'un wallet : clé i18n + couleur du design system. */
const STATE_ROWS: Array<{ key: 'available' | 'pending' | 'in_transit' | 'settlement'; i18nKey: string; color: string }> = [
  { key: 'available',  i18nKey: 'wallet.state.available',  color: 'var(--green)' },
  { key: 'pending',    i18nKey: 'wallet.state.pending',    color: 'var(--gold)' },
  { key: 'in_transit', i18nKey: 'wallet.state.in_transit', color: 'var(--cyan)' },
  { key: 'settlement', i18nKey: 'wallet.state.settlement', color: 'var(--violet)' },
];

/** Destinations disponibles (onglet « Destinations » — CRUD réel via AccountsPanel). */

// ─── Formatage (locale i18n, JetBrains Mono, aligné à droite) ─────────────────

type TFunc = (key: string, params?: Record<string, string | number>) => string;

function formatCurrency(val: number, cur: string, locale: string): string {
  const meta = CURRENCY_META[cur];
  const isCrypto = Boolean(meta?.crypto);
  const isBtcEth = cur === 'BTC' || cur === 'ETH';
  const formatted = val.toLocaleString(locale, {
    minimumFractionDigits: isBtcEth ? 4 : (isCrypto || cur === 'EUR' || cur === 'USD' || cur === 'GBP' ? 2 : 0),
    maximumFractionDigits: isBtcEth ? 8 : 2,
  });
  if (cur === 'EUR') return `€ ${formatted}`;
  if (cur === 'USD') return `$ ${formatted}`;
  if (cur === 'GBP') return `£ ${formatted}`;
  if (cur === 'XAF') return `${formatted} XAF`;
  return `${formatted} ${cur}`;
}

/** Montant signé d'une transaction (+ entrée / − sortie). */
function txAmount(tx: WalletTx, locale: string): string {
  const formatted = formatCurrency(tx.amount, tx.currency, locale);
  if (tx.direction === 'in') return `+${formatted}`;
  if (tx.direction === 'out') return `−${formatted}`;
  return formatted;
}

function formatRelativeTime(isoString: string, t: TFunc, locale: string): string {
  const date = new Date(isoString);
  const now = new Date();
  const diffMinutes = Math.floor((now.getTime() - date.getTime()) / 60000);
  const diffHours = Math.floor(diffMinutes / 60);
  const diffDays = Math.floor(diffHours / 24);
  if (diffMinutes < 1) return t('time.justNow');
  if (diffMinutes < 60) return t('time.minAgo', { n: diffMinutes });
  if (diffHours < 24) return t('time.hAgo', { n: diffHours });
  if (diffDays === 1) return t('time.yesterday');
  return date.toLocaleDateString(locale, { day: '2-digit', month: '2-digit' });
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
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [tab, setTab] = useState<Tab>(() => {
    const q = searchParams.get('tab');
    if (q === 'sources' || q === 'destinations' || q === 'devises') return q;
    return 'devises';
  });

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
  const [fundOpen, setFundOpen] = useState(() => searchParams.get('fund') === '1');
  const fundCurrencyPrefill = searchParams.get('currency') || selected;
  const fundAmountPrefill = searchParams.get('amount') || '100';

  const fetchAll = useCallback(async () => {
    setLoading(true);
    setError(null);
    const [walletRes, ratesRes] = await Promise.all([apiWalletsList(), apiWalletRates()]);
    if (!walletRes.success || !walletRes.data) {
      setError(walletRes.error || t('dash.loadError'));
      setLoading(false);
      return;
    }
    setWallets(walletRes.data.wallets);
    setTotals(walletRes.data.totals);
    // Le taux est secondaire : la page reste utilisable sans lui.
    if (ratesRes.success && ratesRes.data) setRates(ratesRes.data);
    setLoading(false);
  }, [t]);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  // Deep-link : ?tab=destinations|sources|devises & ?fund=1
  useEffect(() => {
    const q = searchParams.get('tab');
    if (q === 'sources' || q === 'destinations' || q === 'devises') setTab(q);
    if (searchParams.get('fund') === '1') setFundOpen(true);
  }, [searchParams]);

  const openFund = () => {
    setFundOpen(true);
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.set('fund', '1');
      if (!next.get('currency')) next.set('currency', selected);
      return next;
    }, { replace: true });
  };

  const closeFund = () => {
    setFundOpen(false);
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.delete('fund');
      next.delete('amount');
      next.delete('currency');
      next.delete('returnTo');
      return next;
    }, { replace: true });
  };

  const handleTab = (id: Tab) => {
    setTab(id);
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (id === 'devises') next.delete('tab');
      else next.set('tab', id);
      return next;
    }, { replace: true });
  };

  /** Historique rapide de la devise sélectionnée. */
  const fetchTxs = useCallback(async (currency: string) => {
    setTxsLoading(true);
    setTxsError(null);
    const resp = await apiWalletTransactions(currency);
    if (!resp.success || !resp.data) {
      setTxsError(resp.error || t('history.empty'));
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
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10 }}>{t('wallet.title')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20 }}>{error}</p>
          <button className="btn btn-cyan" onClick={fetchAll}>↻ {t('common.retry')}</button>
        </div>
      </div>
    );
  }

  const selectedWallet = wallets.find((w) => w.currency === selected) ?? wallets[0];

  return (
    <div className="page">
      {/* En-tête : solde total (EUR de référence) + actions */}
      <div className="page-header animate-up">
        <div className="page-label">{t('wallet.title')}</div>
        <div className="page-title">
          {t('wallet.title')} <span className="gc">.</span>
        </div>
        <div style={{ marginTop: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 16 }}>
          <div>
            <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.15em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)' }}>
              {t('wallet.total')} · {t('wallet.equivalent')}
            </div>
            <div style={{ fontSize: 34, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)', letterSpacing: '-1px', marginTop: 6 }}>
              <AnimatedCounter value={totals.total_ref} format={(n) => formatCurrency(n, 'EUR', locale)} />
            </div>
            <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 4 }}>
              {t('wallet.currencies', { n: totals.currencies, a: totals.with_funds })}
            </div>
          </div>
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-ghost" style={{ fontSize: 12 }} type="button" onClick={openFund}>+ {t('wallet.addFunds')}</button>
            <Link to="/convert" className="btn btn-cyan" style={{ fontSize: 12, textDecoration: 'none' }}>⇄ {t('wallet.convert')}</Link>
          </div>
        </div>
      </div>

      {/* KPIs : totaux par état (staggered reveal + hover) */}
      <motion.div
        className="g4" style={{ marginBottom: 24 }}
        initial="hidden" animate="visible"
        variants={{ hidden: {}, visible: { transition: { staggerChildren: 0.08 } } }}
      >
        {[
          { label: t('wallet.total'), raw: totals.total_ref, color: 'var(--cyan)', sub: t('wallet.equivalent') },
          { label: t('wallet.available'), raw: totals.available_ref, color: 'var(--green)', sub: t('wallet.equivalent') },
          { label: t('wallet.pending'), raw: totals.pending_ref, color: 'var(--gold)', sub: t('wallet.equivalent') },
          { label: t('wallet.in_transit'), raw: totals.in_transit_ref, color: 'var(--violet)', sub: t('wallet.equivalent') },
        ].map((s) => (
          <motion.div
            key={s.label}
            className="card stat-card shine-sweep"
            style={{ position: 'relative', overflow: 'hidden' }}
            variants={{ hidden: { opacity: 0, y: 22, scale: 0.98 }, visible: { opacity: 1, y: 0, scale: 1, transition: { duration: 0.5, ease: EASE } } }}
            whileHover={{ y: -5, scale: 1.02 }}
            transition={{ type: 'spring', stiffness: 260, damping: 18 }}
          >
            <div className="stat-label">{s.label}</div>
            <div className="stat-value" style={{ fontSize: 24, color: s.color }}>
              <AnimatedCounter value={s.raw} format={(n) => formatCurrency(n, 'EUR', locale)} />
            </div>
            <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{s.sub}</div>
          </motion.div>
        ))}
      </motion.div>

      {/* Onglets : Mes devises / Sources / Destinations (layout animé) */}
      <motion.div
        className="account-tabs" style={{ marginBottom: 20, width: 'fit-content' }}
        initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15, duration: 0.45, ease: EASE }}
      >
        {([
          ['devises', '💱 ' + t('wallet.tab.currencies')],
          ['sources', '🏦 ' + t('wallet.tab.sources')],
          ['destinations', '📍 ' + t('wallet.tab.destinations')],
        ] as const).map(([id, label]) => (
          <motion.button
            key={id}
            className={`account-tab ${tab === id ? 'active-personal' : ''}`}
            onClick={() => handleTab(id)}
            style={{ fontSize: 11, fontWeight: 600 }}
            layout
            whileTap={{ scale: 0.96 }}
            transition={{ type: 'spring', stiffness: 300, damping: 18 }}
          >
            {label}
          </motion.button>
        ))}
      </motion.div>

      {tab === 'devises' && (
        <div className="animate-up delay-2">
          {/* ─── Réservations en cours (holds pending) ─── */}
          <PendingHolds />
          {/* Section : soldes par devise + ajout de devise */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10, marginBottom: 14 }}>
            <div>
              <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet.currencies.label')}</div>
              <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
                {t('wallet.currencies', { n: totals.currencies, a: totals.with_funds })}
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              {rates && (
                <span className="pill p-g" style={{ fontSize: 8 }}>
                  {rates.fx_rate_xaf !== null && rates.fx_rate_xaf > 0
                    ? `${t('wallet.rates')} · ${t('dash.rates.one', { base: rates.base, rate: rates.fx_rate_xaf.toLocaleString(locale, { maximumFractionDigits: 3 }) })}`
                    : t('dash.rates.unavailable')}
                </span>
              )}
              <button
                className="btn btn-ghost"
                style={{ fontSize: 11 }}
                onClick={() => setPickerOpen((v) => !v)}
              >
                {pickerOpen ? t('wallet.tab.close') : `+ ${t('wallet.tab.addCurrency')}`}
              </button>
            </div>
          </div>

          {/* Sélecteur d'ajout de devise */}
          {pickerOpen && (
            <div className="card animate-fade" style={{ padding: 20, marginBottom: 20 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
                <div>
                  <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet.tab.addCurrency')}</div>
                  <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>
                    {t('wallet.tab.pickerHint')}
                  </div>
                </div>
                <button className="btn btn-ghost" style={{ fontSize: 10 }} onClick={() => setPickerOpen(false)}>{t('wallet.tab.close')}</button>
              </div>

              <div className="g2" style={{ gap: 16 }}>
                <div>
                  <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.15em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 8 }}>
                    {t('wallet.tab.available')}
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
                          {w.has_funds && <span className="pill p-gr" style={{ fontSize: 7, flexShrink: 0 }}>{t('wallet.tab.active')}</span>}
                          {selected === w.currency && <span className="pill p-c" style={{ fontSize: 7, flexShrink: 0 }}>{t('wallet.tab.selected')}</span>}
                        </button>
                      );
                    })}
                  </div>
                </div>

                <div>
                  <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.15em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 8 }}>
                    {t('wallet.tab.availableSoon')}
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
                        <span className="pill" style={{ fontSize: 7, flexShrink: 0 }}>{t('wallet.tab.comingSoon')}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Grille des wallets par devise (selectionnables) — reveal + tilt */}
          <motion.div
            className="g3" style={{ gap: 14 }}
            initial="hidden" animate="visible"
            variants={{ hidden: {}, visible: { transition: { staggerChildren: 0.06 } } }}
          >
            {wallets.map((w) => {
              const meta = CURRENCY_META[w.currency] ?? { flag: '🌐', symbol: w.currency, label: w.currency };
              const pct = w.balance > 0 ? Math.min(100, Math.round((w.available / w.balance) * 100)) : 0;
              const isSelected = selected === w.currency;
              return (
                <motion.div
                  key={w.currency}
                  className="card"
                  onClick={() => handleSelect(w.currency)}
                  style={{
                    padding: 18,
                    cursor: 'pointer',
                    borderColor: isSelected ? 'rgba(0,200,255,0.45)' : undefined,
                    position: 'relative',
                    overflow: 'hidden',
                  }}
                  variants={{ hidden: { opacity: 0, y: 20, scale: 0.98 }, visible: { opacity: 1, y: 0, scale: 1, transition: { duration: 0.45, ease: EASE } } }}
                  whileHover={isSelected ? {} : { y: -5, scale: 1.02, borderColor: 'rgba(0,200,255,0.25)' }}
                  whileTap={{ scale: 0.98 }}
                  transition={{ type: 'spring', stiffness: 260, damping: 18 }}
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
                      {isSelected && <span className="pill p-c" style={{ fontSize: 7 }}>✓ {t('wallet.tab.selected')}</span>}
                      <div className={`pill ${meta.stablecoin ? 'p-v' : meta.crypto ? 'p-g' : 'p-c'}`} style={{ fontSize: 7, height: 22, flexShrink: 0 }}>
                        {meta.stablecoin ? t('dash.stablecoin') : meta.crypto ? t('method.crypto').toUpperCase() : t('dash.fiat')}
                      </div>
                    </div>
                  </div>

                  {/* Solde principal */}
                  <div style={{
                    fontSize: 22, fontWeight: 800, color: 'var(--white)',
                    fontFamily: 'var(--font-mono)', letterSpacing: '-0.5px', marginBottom: 12,
                  }}>
                    {formatCurrency(w.balance, w.currency, locale)}
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
                          {t(row.i18nKey)}
                        </span>
                        <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>
                          {formatCurrency(w[row.key], w.currency, locale)}
                        </span>
                      </div>
                    ))}
                  </div>

                  {/* Pied : % disponible + équivalent EUR */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 10, paddingTop: 10, borderTop: '1px solid var(--border-soft)' }}>
                    <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {t('wallet.pctAvailable', { pct })}
                    </span>
                    <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {w.has_funds
                        ? (w.ref_equivalent != null ? `≈ ${formatCurrency(w.ref_equivalent, 'EUR', locale)}` : t('wallet.equivalent.unavailable'))
                        : t('wallet.noFunds')}
                    </span>
                  </div>
                </motion.div>
              );
            })}
          </motion.div>

          {/* Aperçu du wallet sélectionné + historique rapide */}
          <ScrollReveal className="g2" style={{ alignItems: 'start', gap: 20, marginTop: 20 }}>
            {/* ─── Aperçu (tilt 3D) ─── */}
            <TiltCard className="card card-hi-c" style={{ padding: 22, position: 'relative' }} max={6}>
              <div className="page-label" style={{ marginBottom: 12 }}>{t('wallet.preview')}</div>
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
                <div className={`pill ${CURRENCY_META[selectedWallet.currency]?.stablecoin ? 'p-v' : CURRENCY_META[selectedWallet.currency]?.crypto ? 'p-g' : 'p-c'}`} style={{ fontSize: 8, flexShrink: 0 }}>
                  {CURRENCY_META[selectedWallet.currency]?.stablecoin
                    ? t('dash.stablecoin')
                    : CURRENCY_META[selectedWallet.currency]?.crypto
                      ? t('method.crypto').toUpperCase()
                      : t('dash.fiat')}
                </div>
              </div>

              <div style={{
                fontSize: 30, fontWeight: 800, color: 'var(--white)',
                fontFamily: 'var(--font-mono)', letterSpacing: '-1px', marginBottom: 4,
              }}>
                {formatCurrency(selectedWallet.balance, selectedWallet.currency, locale)}
              </div>
              <div style={{ fontSize: 10, color: 'var(--text-dim)', marginBottom: 18 }}>
                {selectedWallet.currency !== 'EUR' && selectedWallet.has_funds
                  ? (selectedWallet.ref_equivalent != null
                    ? `≈ ${formatCurrency(selectedWallet.ref_equivalent, 'EUR', locale)}`
                    : t('wallet.equivalent.unavailable'))
                  : t('wallet.bookValue')}
              </div>

              {/* Ventilation par état */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                {STATE_ROWS.map((row) => (
                  <div className="trow" key={row.key} style={{ padding: '7px 0' }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: 'var(--text-mid)' }}>
                      <span className="dot" style={{ background: row.color }} />
                      {t(row.i18nKey)}
                    </span>
                    <span style={{ fontSize: 12, fontWeight: 700, color: row.color, fontFamily: 'var(--font-mono)' }}>
                      {formatCurrency(selectedWallet[row.key], selectedWallet.currency, locale)}
                    </span>
                  </div>
                ))}
                <div className="trow" style={{ padding: '8px 0 0' }}>
                  <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: 'var(--text-mid)' }}>💼 {t('wallet.totalBalance')}</span>
                  <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>
                    {formatCurrency(selectedWallet.balance, selectedWallet.currency, locale)}
                  </span>
                </div>
              </div>

              {rates && (
                <div style={{ marginTop: 16, paddingTop: 12, borderTop: '1px solid var(--border-soft)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span style={{ fontSize: 10, color: 'var(--text-dim)' }}>{t('wallet.refRate')}</span>
                  <span className="mono" style={{ fontSize: 11, color: 'var(--gold)' }}>
                    {rates.fx_rate_xaf !== null && rates.fx_rate_xaf > 0
                      ? t('dash.rates.one', { base: rates.base, rate: rates.fx_rate_xaf.toLocaleString(locale, { maximumFractionDigits: 3 }) })
                      : t('wallet.rateUnavailable')}
                  </span>
                </div>
              )}
            </TiltCard>

            {/* ─── Historique rapide ─── */}
            <div className="card" style={{ padding: 22 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
                <div>
                  <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet.history')}</div>
                  <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
                    {t('wallet.history.sub', { currency: selectedWallet.currency })}
                  </div>
                </div>
                <Link to="/history" className="btn btn-ghost" style={{ fontSize: 10, textDecoration: 'none' }}>{t('wallet.history.viewAll')}</Link>
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
                  <button className="btn btn-cyan" style={{ fontSize: 11 }} onClick={() => fetchTxs(selected)}>↻ {t('common.retry')}</button>
                </div>
              ) : !txs || txs.length === 0 ? (
                <EmptyState
                  icon="🕘"
                  title={t('wallet.empty.title')}
                  subtitle={t('wallet.empty.sub', { currency: selectedWallet.currency })}
                  action={<Link to="/send" className="btn btn-ghost" style={{ fontSize: 11, textDecoration: 'none' }}>{t('wallet.empty.send')}</Link>}
                />
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
                            {formatRelativeTime(tx.created_at, t, locale)}
                            
                          </div>
                        </div>
                        <div style={{ textAlign: 'right' }}>
                          <div
                            className="tx-amount"
                            style={{ color: isIn ? 'var(--green)' : isFx ? 'var(--violet)' : 'var(--cyan)' }}
                          >
                            {txAmount(tx, locale)}
                          </div>
                          <div className={`pill ${statusPill(tx.status)}`} style={{ marginTop: 4, fontSize: 7 }}>
                            {t(`status.${tx.status}`)}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          </ScrollReveal>
        </div>
      )}

      {tab === 'sources' && (
        <div className="animate-up delay-2">
          <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet.sources.title')}</div>
          <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 14 }}>
            {t('wallet.sources.sub')}
          </div>
          <AccountsPanel role="source" />
        </div>
      )}

      {tab === 'destinations' && (
        <div className="animate-up delay-2">
          <div className="page-label" style={{ marginBottom: 4 }}>{t('wallet.destinations.title')}</div>
          <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 14 }}>
            {t('wallet.destinations.sub')}
          </div>
          <AccountsPanel role="destination" />
        </div>
      )}

      <AddFundsModal
        open={fundOpen}
        onClose={() => {
          const returnTo = searchParams.get('returnTo');
          closeFund();
          if (returnTo && returnTo.startsWith('/')) {
            navigate(returnTo);
          }
        }}
        onSuccess={(cur) => {
          setSelected(cur);
          fetchAll();
        }}
        initialCurrency={fundCurrencyPrefill}
        initialAmount={fundAmountPrefill}
      />
    </div>
  );
}
