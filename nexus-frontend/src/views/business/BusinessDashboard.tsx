import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { apiBusinessOverview, type BusinessOverview } from '../../api/client';
import { fmtMoney } from './ui';
import { useDashT } from '../../data/dashboard-i18n';
import { AnimatedNumber, EASE, RevealGroup, HoverCard, SectionTransition } from '../../components/anim/Premium';

/** Business Dashboard — Financial Operations Console (données 100 % backend). */
export default function BusinessDashboard() {
  const [data, setData] = useState<BusinessOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // Appelé inconditionnellement, avant tout retour anticipé (règles des Hooks) :
  // les branches loading/error/!data sortent plus bas et changeraient sinon
  // l'ordre des Hooks d'un rendu à l'autre.
  const td = useDashT();

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiBusinessOverview();
    if (!res.success || !res.data) {
      setError(res.error || 'Impossible de charger la console.');
      setLoading(false);
      return;
    }
    setData(res.data);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  if (loading) {
    return <div className="card card-hi-c" style={{ padding: 48, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Chargement de la console financière…</p></div>;
  }
  if (error) {
    return <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><div style={{ fontSize: 30, marginBottom: 10 }}>⚠️</div><p style={{ color: 'var(--text-mid)', marginBottom: 14 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>;
  }
  if (!data) return null;

  const t = data.totals;
  const kpis: Array<{ label: string; value: number; suffix?: string; decimals?: number; color: string }> = [
    { label: td('biz.total_assets'), value: t.total_assets, color: 'var(--cyan)' },
    { label: td('biz.available'), value: t.available, color: 'var(--green)' },
    { label: td('biz.pending'), value: t.pending, color: 'var(--gold)' },
    { label: td('biz.in_transit'), value: t.in_transit, color: 'var(--violet)' },
    { label: td('biz.settlement'), value: t.settlement, color: 'var(--gold)' },
    { label: td('biz.payables'), value: t.payables, color: 'var(--red)' },
    { label: td('biz.volume_30d'), value: t.volume_xaf, color: 'var(--cyan)' },
    { label: td('biz.fees_30d'), value: t.fees_ref, color: 'var(--text-mid)' },
    { label: td('biz.success_rate'), value: t.success_rate, suffix: ' %', decimals: 1, color: 'var(--green)' },
    { label: td('biz.avg_exec'), value: t.avg_exec_sec ?? 0, suffix: ' s', decimals: 1, color: 'var(--text-mid)' },
  ];

  return (
    <SectionTransition id="biz-dash">
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">YOUR FINANCIAL OPERATIONS, ORCHESTRATED BY NEXUS</div>
        <div className="page-title">Console financière</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          Actifs, flux, frais et performance — calculés depuis le ledger en temps réel.
        </p>
      </motion.div>

      {/* KPIs animés */}
      <RevealGroup className="kpi-grid" stagger={0.05}>
        {kpis.map((kpi, i) => (
          <HoverCard key={kpi.label} className="card" index={i}
            style={{ padding: 18, position: 'relative', overflow: 'hidden' }}>
            <div style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: '0.06em', position: 'relative', zIndex: 1 }}>{kpi.label}</div>
            <div className="mono" style={{ fontSize: 22, fontWeight: 800, color: kpi.color, marginTop: 6, position: 'relative', zIndex: 1 }}>
              <AnimatedNumber value={kpi.value} suffix={kpi.suffix ?? ''} decimals={kpi.decimals ?? 0} />
            </div>
          </HoverCard>
        ))}
      </RevealGroup>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, alignItems: 'start', marginTop: 22 }}>
        {/* Wallets */}
        <motion.div className="card" style={{ padding: 20, position: 'relative', overflow: 'hidden' }}
          initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15, duration: 0.55, ease: EASE }}>
          <div className="page-label" style={{ marginBottom: 12 }}>{td('biz.treasury_by_currency')}</div>
          {data.wallets.length === 0 ? (
            <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{td('empty.noWallets')}</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {data.wallets.map((w, i) => (
                <motion.div key={w.currency} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid var(--border)' }}
                  initial={{ opacity: 0, x: -16 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.2 + i * 0.06, duration: 0.4, ease: EASE }}>
                  <div>
                    <div style={{ fontWeight: 700, color: 'var(--text-bright)', fontSize: 14 }}>{w.currency}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>disponible <AnimatedNumber value={w.available} /></div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div className="mono" style={{ fontWeight: 700, color: 'var(--text-bright)' }}><AnimatedNumber value={w.balance} /></div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>≈ <AnimatedNumber value={w.ref_value} /> EUR</div>
                  </div>
                </motion.div>
              ))}
            </div>
          )}
          <motion.span whileHover={{ x: 4 }}><Link to="/treasury" className="btn btn-ghost" style={{ marginTop: 14, fontSize: 11 }}>Ouvrir la trésorerie →</Link></motion.span>
        </motion.div>

        {/* Cash flow animé */}
        <motion.div className="card" style={{ padding: 20, position: 'relative', overflow: 'hidden' }}
          initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.25, duration: 0.55, ease: EASE }}>
          <div className="page-label" style={{ marginBottom: 12 }}>{td('biz.cash_flow')}</div>
          {data.cash_flow.length === 0 ? (
            <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{td('empty.noCashFlow')}</p>
          ) : (
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: 120 }}>
              {data.cash_flow.map((d, i) => {
                const max = Math.max(1, ...data.cash_flow.map(x => Math.max(x.inflow, x.outflow)));
                return (
                  <div key={d.date} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 3 }} title={`${d.date} · in ${fmtMoney(d.inflow, 'EUR')} / out ${fmtMoney(d.outflow, 'EUR')}`}>
                    <motion.div style={{ width: '100%', maxWidth: 26, background: 'rgba(0,207,160,0.55)', borderRadius: '3px 3px 0 0' }}
                      initial={{ height: 0 }} animate={{ height: Math.max(2, (d.inflow / max) * 90) }} transition={{ delay: 0.3 + i * 0.05, duration: 0.5, ease: EASE }} />
                    <motion.div style={{ width: '100%', maxWidth: 26, background: 'rgba(234,72,108,0.5)', borderRadius: '3px 3px 0 0' }}
                      initial={{ height: 0 }} animate={{ height: Math.max(2, (d.outflow / max) * 90) }} transition={{ delay: 0.3 + i * 0.05, duration: 0.5, ease: EASE }} />
                  </div>
                );
              })}
            </div>
          )}
          <div style={{ display: 'flex', gap: 14, marginTop: 10, fontSize: 11, color: 'var(--text-dim)' }}>
            <span>■ <span style={{ color: 'var(--green)' }}>Entrées</span></span>
            <span>■ <span style={{ color: 'var(--red)' }}>Sorties</span></span>
          </div>
        </motion.div>
      </div>

      {/* Providers */}
      <motion.div className="card" style={{ padding: 20, marginTop: 20, position: 'relative', overflow: 'hidden' }}
        initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.35, duration: 0.55, ease: EASE }}>
        <div className="page-label" style={{ marginBottom: 12 }}>{td('biz.providers_perf')}</div>
        {data.providers.length === 0 ? (
          <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{td('empty.noProviders')}</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-dim)', borderBottom: '1px solid var(--border)' }}>
                <th style={{ padding: '8px 6px' }}>Provider</th>
                <th style={{ padding: '8px 6px' }}>Transactions</th>
                <th style={{ padding: '8px 6px' }}>Volume</th>
                <th style={{ padding: '8px 6px' }}>Réussite</th>
              </tr>
            </thead>
            <tbody>
              {data.providers.map((p, i) => (
                <motion.tr key={p.provider} style={{ borderBottom: '1px solid var(--border)' }}
                  initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.4 + i * 0.05, duration: 0.35, ease: EASE }}>
                  <td style={{ padding: '8px 6px', color: 'var(--text-bright)', fontWeight: 600 }}>{p.provider}</td>
                  <td style={{ padding: '8px 6px' }}>{p.transactions}</td>
                  <td style={{ padding: '8px 6px' }}>{fmtMoney(p.volume_xaf, 'XAF')}</td>
                  <td style={{ padding: '8px 6px', color: p.success_rate >= 99 ? 'var(--green)' : 'var(--gold)' }}>{p.success_rate} %</td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        )}
      </motion.div>
    </div>
    </SectionTransition>
  );
}
