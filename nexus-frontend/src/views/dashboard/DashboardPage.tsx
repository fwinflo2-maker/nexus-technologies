import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import {
  apiDashboardSummary,
  apiDashboardActivity,
  apiWalletRates,
  type DashboardSummaryData,
  type DashboardActivityData,
  type DashboardWallet,
  type DashboardRecentTx,
  type WalletRatesData,
} from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { useI18n } from '../../context/I18nContext';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import AnimatedCounter from '../../components/AnimatedCounter';
import Avatar from '../../components/Avatar';

type Mode = 'personal' | 'business';
type Period = '7d' | '30d' | '12m';

interface DashboardProps {
  mode: Mode;
}

const CURRENCY_META: Record<string, { flag: string; symbol: string; crypto?: boolean; label: string }> = {
  EUR:  { flag: '🇪🇺', symbol: '€', label: 'Euro' },
  USD:  { flag: '🇺🇸', symbol: '$', label: 'US Dollar' },
  GBP:  { flag: '🇬🇧', symbol: '£', label: 'Livre Sterling' },
  XAF:  { flag: '🌍', symbol: 'XAF', label: 'Franc CFA (CEMAC)' },
  USDT: { flag: '🔵', symbol: 'USDT', crypto: true, label: 'Tether USD' },
  USDC: { flag: '🔵', symbol: 'USDC', crypto: true, label: 'USD Coin' },
};

type TFunc = (key: string, params?: Record<string, string | number>) => string;

function formatCurrency(val: number, cur: string, locale: string): string {
  const meta = CURRENCY_META[cur];
  const formatted = val.toLocaleString(locale, {
    minimumFractionDigits: meta?.crypto || cur === 'EUR' || cur === 'USD' || cur === 'GBP' ? 2 : 0,
    maximumFractionDigits: 2,
  });
  if (cur === 'EUR') return `€ ${formatted}`;
  if (cur === 'USD') return `$ ${formatted}`;
  if (cur === 'GBP') return `£ ${formatted}`;
  if (cur === 'XAF') return `${formatted} XAF`;
  return `${formatted} ${cur}`;
}

function formatXAF(val: number, locale: string): string {
  return `${val.toLocaleString(locale, { maximumFractionDigits: 0 })} XAF`;
}

function formatRelativeTime(isoString: string, t: TFunc, locale: string): string {
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMinutes = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMinutes / 60);
  const diffDays = Math.floor(diffHours / 24);
  if (diffMinutes < 1) return t('time.justNow');
  if (diffMinutes < 60) return t('time.minAgo', { n: diffMinutes });
  if (diffHours < 24) return t('time.hAgo', { n: diffHours });
  if (diffDays === 1) return t('time.yesterday');
  return date.toLocaleDateString(locale, { day: '2-digit', month: '2-digit' });
}

const fadeInUp = {
  hidden: { opacity: 0, y: 30 },
  visible: (i: number) => ({
    opacity: 1,
    y: 0,
    transition: { delay: i * 0.1, duration: 0.6, ease: [0.22, 1, 0.36, 1] as [number, number, number, number] },
  }),
};

const staggerContainer = {
  hidden: { opacity: 1 },
  visible: {
    transition: {
      staggerChildren: 0.1,
    },
  },
};

export default function DashboardPage({ mode }: DashboardProps) {
  const { user: authUser } = useAuth();
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);
  const isBiz = mode === 'business';
  const [rates, setRates] = useState<WalletRatesData | null>(null);
  const [activity, setActivity] = useState<DashboardActivityData | null>(null);
  const [summary, setSummary] = useState<DashboardSummaryData | null>(null);
  const [period, setPeriod] = useState<Period>('30d');
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    const [sumRes, actRes, ratesRes] = await Promise.all([
      apiDashboardSummary(),
      apiDashboardActivity(period),
      apiWalletRates(),
    ]);
    if (!sumRes.success || !sumRes.data) {
      setError(sumRes.error || t('dash.loadError'));
      setLoading(false);
      return;
    }
    setSummary(sumRes.data);
    if (actRes.success && actRes.data) setActivity(actRes.data);
    if (ratesRes.success && ratesRes.data) setRates(ratesRes.data);
    setLoading(false);
  }, [period]);

  useEffect(() => { fetchData(); }, [fetchData]);

  if (loading && !summary) {
    return (
      <div className="page">
        <div className="shimmer-bg" style={{ height: 400, borderRadius: 20 }} />
      </div>
    );
  }

  if (error || !summary) {
    return (
      <div className="page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' }}>
        <motion.div initial={{ scale: 0.9, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10 }}>{t('dash.loadError')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20 }}>{error || t('dash.serviceUnavailable')}</p>
          <button className="btn btn-cyan" onClick={fetchData}>↻ {t('common.retry')}</button>
        </motion.div>
      </div>
    );
  }

  const { user, wallets, totals, kpis, recent, banner } = summary;
  const displayName = authUser?.full_name || user.full_name || '';
  const series = activity?.series || [];
  const maxVol = Math.max(...series.map((s) => s.volume), 1);

  const quickActions = [
    { icon: '↗', label: t('dash.quick.send'), sub: t('dash.quick.send.sub'), cls: 'ib-c', href: '/send' },
    { icon: '↙', label: t('dash.quick.receive'), sub: t('dash.quick.receive.sub'), cls: 'ib-gr', href: '/receive' },
    { icon: '⇌', label: t('dash.quick.fund'), sub: t('dash.quick.fund.sub'), cls: 'ib-g', href: '/wallet' },
    { icon: '⇄', label: t('dash.quick.convert'), sub: t('dash.quick.convert.sub'), cls: 'ib-v', href: '/convert' },
    { icon: '👥', label: t('dash.quick.beneficiaries'), sub: t('dash.quick.beneficiaries.sub'), cls: 'ib-p', href: '/payments' },
  ];

  if (user.status === 'PENDING') quickActions.push({ icon: '🛡️', label: t('dash.quick.kyc'), sub: t('dash.quick.kyc.sub'), cls: 'ib-v', href: '/kyc' });
  else if (user.account_type === 'business') quickActions.push({ icon: '🏢', label: t('dash.quick.pay'), sub: t('dash.quick.pay.sub'), cls: 'ib-v', href: '/payments' });
  else quickActions.push({ icon: '≡', label: t('dash.quick.history'), sub: t('dash.quick.history.sub'), cls: 'ib-v', href: '/history' });

  return (
    <div className="page" style={{ overflowX: 'hidden' }}>
      <AnimatePresence>
        {banner && banner.type && (
          <motion.div initial={{ y: -20, opacity: 0 }} animate={{ y: 0, opacity: 1 }} exit={{ opacity: 0 }}
            className={`card ${banner.type === 'kyc' ? 'card-hi-g' : banner.type === 'limits' ? 'card-hi-v' : 'card-hi-c'}`}
            style={{ marginBottom: 20, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <div className={`ib ${banner.type === 'kyc' ? 'ib-g' : 'ib-v'}`} style={{ width: 40, height: 40, borderRadius: 10, fontSize: 20 }}>
                {banner.type === 'kyc' ? '🛡️' : banner.type === 'limits' ? '⚡' : '💡'}
              </div>
              <div>
                <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>{banner.title}</div>
                <div style={{ fontSize: 11, color: 'var(--text-mid)', marginTop: 2 }}>{banner.message}</div>
              </div>
            </div>
            {banner.action && (
              <Link to={banner.type === 'kyc' ? '/kyc' : '/wallet'} className={`btn ${banner.type === 'kyc' ? 'btn-gold' : 'btn-cyan'}`} style={{ fontSize: 11 }}>
                {banner.action} →
              </Link>
            )}
          </motion.div>
        )}
      </AnimatePresence>

      <motion.div variants={fadeInUp} initial="hidden" animate="visible" custom={0} className="page-header" style={{ marginBottom: 30 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
          <Avatar avatar={authUser?.avatar} accountType={isBiz ? 'business' : 'personal'} size={58} />
          <div>
            <div className="page-label">{t(isBiz ? 'dash.business.title' : 'dash.personal.title')}</div>
            <div className="page-title" style={{ fontSize: 'clamp(26px, 3vw, 38px)' }}>
              {t('dash.hello')} <span className={isBiz ? 'gg' : 'gc'}>{displayName}</span>
            </div>
          </div>
        </div>
      </motion.div>

      {/* Quick actions — barre d'actions premium */}
      <motion.div variants={staggerContainer} initial="hidden" animate="visible"
        style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 26 }}>
        {quickActions.map((qa, i) => (
          <motion.div key={qa.href + qa.label} variants={fadeInUp} custom={i + 1}>
            <Link to={qa.href} className="btn btn-ghost" style={{
              display: 'inline-flex', alignItems: 'center', gap: 10, padding: '10px 16px', textDecoration: 'none',
              fontSize: 12, borderRadius: 12, border: '1px solid var(--border)',
            }}>
              <span className={`ib ${qa.cls}`} style={{ width: 30, height: 30, borderRadius: 8, fontSize: 14 }}>{qa.icon}</span>
              <span style={{ textAlign: 'left' }}>
                <span style={{ display: 'block', fontWeight: 700, color: 'var(--text-bright)' }}>{qa.label}</span>
                <span style={{ display: 'block', fontSize: 9, color: 'var(--text-dim)' }}>{qa.sub}</span>
              </span>
            </Link>
          </motion.div>
        ))}
      </motion.div>

      <motion.div variants={staggerContainer} initial="hidden" animate="visible" className="g4" style={{ marginBottom: 30, perspective: '1000px' }}>
        {[{ l: t('dash.total'), v: totals.total_ref, fmt: (n: number) => formatCurrency(n, totals.ref_currency, locale), c: 'var(--cyan)', icon: '💰' },
          { l: t('dash.transactions'), v: kpis.transactions_month, fmt: (n: number) => `${n}`, c: 'var(--green)', icon: '🔄' },
          { l: t('dash.volume30d'), v: kpis.volume_xaf, fmt: (n: number) => formatXAF(n, locale), c: 'var(--gold)', icon: '📊' },
          { l: t('dash.avgTime'), v: kpis.avg_exec_time_sec ?? 0, fmt: (n: number) => `${Math.round(n)}s`, c: 'var(--violet)', icon: '⏱️' }
        ].map((kpi, i) => (
          <motion.div key={i} variants={fadeInUp} custom={i + 1} whileHover={{ y: -5, scale: 1.02 }}
            className="card glass-card stat-card" style={{ padding: 24, transformStyle: 'preserve-3d' }}>
            <div className="stat-label">{kpi.l}</div>
            <div className="stat-value" style={{ color: kpi.c, fontSize: 28 }}>
              <AnimatedCounter value={kpi.v} format={kpi.fmt} />
            </div>
            <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 4 }}>{kpi.icon} {t('dash.lastHour')}</div>
          </motion.div>
        ))}
      </motion.div>

      <div className="g3 animate-up delay-2" style={{ marginBottom: 30, gap: 20 }}>
        {wallets.map((w: DashboardWallet, i: number) => {
          const meta = CURRENCY_META[w.currency] || { flag: '🌐', symbol: w.currency, label: w.currency };
          return (
            <motion.div key={w.currency} variants={fadeInUp} custom={i + 5} whileHover={{ rotateY: 5, rotateX: 5, scale: 1.03, boxShadow: '0 25px 50px rgba(0,0,0,0.5)' }}
              style={{ borderRadius: 'var(--r-lg)', background: 'var(--panel)', border: '1px solid var(--border)', padding: 20, transformStyle: 'preserve-3d', perspective: 800 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 15 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <span style={{ fontSize: 32 }}>{meta.flag}</span>
                  <div>
                    <div className="wi-cur" style={{ fontSize: 16 }}>{w.currency}</div>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{meta.label}</div>
                  </div>
                </div>
                <div className={`pill ${meta.crypto ? 'p-v' : 'p-c'}`} style={{ fontSize: 8, height: 24 }}>{meta.crypto ? t('dash.stablecoin') : t('dash.fiat')}</div>
              </div>
              <div style={{ fontSize: 28, fontWeight: 800, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)', marginBottom: 10 }}>
                {formatCurrency(w.balance, w.currency, locale)}
              </div>
              <div style={{ height: 6, background: 'var(--panel2)', borderRadius: 3, overflow: 'hidden', marginBottom: 10 }}>
                <motion.div initial={{ width: 0 }} animate={{ width: `${(w.available / w.balance || 0) * 100}%` }}
                  transition={{ duration: 1.5, ease: 'easeOut' }} style={{ height: '100%', background: 'linear-gradient(90deg, var(--cyan), var(--green))', borderRadius: 3 }} />
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 9, color: 'var(--text-dim)' }}>
                <span>{t('dash.available')}</span><span>{formatCurrency(w.available, w.currency, locale)}</span>
              </div>
            </motion.div>
          );
        })}
      </div>

        {/* Taux de change */}
        {rates && (
          <div className="card glass-card" style={{ padding: 24, marginBottom: 30 }}>
            <div className="page-label">{t('dash.rates')}</div>
            <div style={{ fontSize: 16, fontWeight: 600, marginTop: 8 }}>{rates.base} → XAF</div>
            <div style={{ fontSize: 14, color: 'var(--text-dim)', marginTop: 4 }}>
              {rates.fx_rate_xaf !== null && rates.fx_rate_xaf > 0
                ? t('dash.rates.one', { base: rates.base, rate: rates.fx_rate_xaf.toLocaleString(locale, { maximumFractionDigits: 3 }) })
                : t('dash.rates.unavailable')}
            </div>
            <Link to="/wallet" className="btn btn-ghost" style={{ marginTop: 12, fontSize: 10 }}>{t('wallet.convert')}</Link>
          </div>
        )}

        {/* Vérification du compte */}
        <div className="card glass-card" style={{ padding: 24, marginBottom: 30 }}>
          <div className="page-label">{t('dash.verification')}</div>
          {summary.user.status === 'PENDING' ? (
            <div className="pill p-r" style={{ fontSize: 10, marginTop: 8 }}>{t('dash.verification.required')}</div>
          ) : (
            <div className="pill p-gr" style={{ fontSize: 10, marginTop: 8 }}>{t('dash.verification.ok')}</div>
          )}
          <Link to="/kyc" className="btn btn-ghost" style={{ marginTop: 12, fontSize: 10 }}>{t('dash.verification.view')}</Link>
        </div>

        {/* Nexus AI */}
        <div className="card glass-card" style={{ padding: 24, marginBottom: 30 }}>
          <div className="page-label">{t('dash.ai.title')}</div>
          <p style={{ marginTop: 8, fontSize: 14, color: 'var(--text-mid)' }}>{t('dash.ai.text')}</p>
          <Link to="/agents" className="btn btn-ghost" style={{ marginTop: 12, fontSize: 10 }}>{t('dash.ai.cta')}</Link>
        </div>

        {/* Nexus Intelligence — le routing est une capacité du Core */}
        <div className="card glass-card" style={{ padding: 24, marginBottom: 30 }}>
          <div className="page-label">{t('dash.routing.title')}</div>
          <p style={{ marginTop: 8, fontSize: 14, color: 'var(--text-mid)' }}>
            {t('dash.routing.text')}
          </p>
          <Link to="/send" className="btn btn-ghost" style={{ marginTop: 12, fontSize: 10 }}>{t('dash.routing.cta')}</Link>
        </div>

        {/* Activité et activité récente */}
        <div className="g2 animate-up delay-3" style={{ alignItems: 'start', gap: 20 }}>

        <div className="card glass-card" style={{ padding: 24 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 20 }}>
            <div>
              <div className="page-label">{t('dash.activity')}</div>
              <div style={{ fontSize: 18, fontWeight: 700, color: 'var(--text-bright)' }}>{t('dash.activity.sub')}</div>
            </div>
            <div className="mode-toggle">
              {(['7d', '30d', '12m'] as Period[]).map((p) => (
                <button key={p} className={`mode-btn ${period === p ? 'active' : ''}`} onClick={() => setPeriod(p)}>{p}</button>
              ))}
            </div>
          </div>
          <div className="bar-chart" style={{ height: 120, gap: series.length > 15 ? 2 : 5 }}>
            {series.map((s, i) => (
              <motion.div key={i} initial={{ height: 0 }} animate={{ height: `${(s.volume / maxVol) * 100}%` }}
                transition={{ delay: i * 0.02, duration: 0.8, ease: 'easeOut' }}
                style={{ flex: 1, background: 'linear-gradient(to top, rgba(0,200,255,0.1), rgba(0,200,255,0.6))', borderRadius: '4px 4px 0 0' }} />
            ))}
          </div>
        </div>

        <div className="card glass-card" style={{ padding: 24 }}>
          <div className="page-label">{t('dash.recent')}</div>
          <div style={{ marginTop: 15, display: 'flex', flexDirection: 'column', gap: 12 }}>
            {recent.map((tx: DashboardRecentTx) => (
              <div key={tx.id} className="wi" style={{ background: 'transparent', padding: 12 }}>
                <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                  <div className={`ib ${tx.type === 'receive' ? 'ib-gr' : 'ib-c'}`} style={{ width: 40, height: 40, fontSize: 18 }}>{tx.type === 'receive' ? '↙' : '↗'}</div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{tx.label}</div>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{formatRelativeTime(tx.created_at, t, locale)}</div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontWeight: 700, color: tx.type === 'receive' ? 'var(--green)' : 'var(--cyan)' }}>{formatCurrency(tx.amount, tx.currency, locale)}</div>
                    <div className={`pill ${tx.status === 'completed' ? 'p-gr' : 'p-c'}`} style={{ marginTop: 4, fontSize: 8 }}>{t(`status.${tx.status}`)}</div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
