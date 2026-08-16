import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { apiBusinessAnalytics, type BusinessOverview } from '../../api/client';
import { fmtMoney } from './ui';
import { useDashT } from '../../data/dashboard-i18n';
import { AnimatedNumber, EASE } from '../../components/anim/Premium';

/** Analytics — volume, cash flow, providers (réponses à de vraies questions métier). */
export default function AnalyticsPage() {
  const t = useDashT();
  const [data, setData] = useState<{ volume: BusinessOverview['totals']; cash_flow: BusinessOverview['cash_flow']; providers: BusinessOverview['providers'] } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiBusinessAnalytics();
    if (!res.success || !res.data) { setError(res.error || 'Erreur de chargement.'); setLoading(false); return; }
    setData(res.data);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  if (loading) return <div className="card card-hi-c" style={{ padding: 48, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Calcul des analytics…</p></div>;
  if (error) return <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><p style={{ color: 'var(--text-mid)', marginBottom: 12 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>;
  if (!data) return null;

  const v = data.volume;
  const flow = data.cash_flow;
  const maxFlow = Math.max(1, ...flow.map(d => Math.max(d.inflow, d.outflow)));
  const net = flow.reduce((acc, d) => acc + d.inflow - d.outflow, 0);

  const kpis: Array<{ label: string; value: number; suffix?: string; decimals?: number; color: string; format?: (n: number) => string }> = [
    { label: 'Volume 30 j', value: v.volume_xaf, format: (n: number) => fmtMoney(n, 'XAF'), color: 'var(--cyan)' },
    { label: 'Frais 30 j', value: v.fees_ref, format: (n: number) => fmtMoney(n, v.ref_currency), color: 'var(--text-mid)' },
    { label: 'Taux de réussite', value: v.success_rate, suffix: ' %', decimals: 1, color: 'var(--green)' },
    { label: 'Flux net 30 j', value: net, format: (n: number) => fmtMoney(n, v.ref_currency), color: net >= 0 ? 'var(--green)' : 'var(--red)' },
    { label: 'À payer', value: v.payables, format: (n: number) => fmtMoney(n, v.ref_currency), color: 'var(--gold)' },
  ];

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">ANALYTICS</div>
        <div className="page-title">Reporting & analytics</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>Volume, coûts, fiabilité et performance des providers — depuis les données réelles.</p>
      </motion.div>

      <motion.div
        style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 14, marginBottom: 22 }}
        initial="hidden" animate="visible" variants={{ hidden: {}, visible: { transition: { staggerChildren: 0.06 } } }}
      >
        {kpis.map((kpi) => (
          <motion.div
            key={kpi.label} className="card shine-sweep" style={{ padding: 18, position: 'relative', overflow: 'hidden' }}
            variants={{ hidden: { opacity: 0, y: 20, scale: 0.98 }, visible: { opacity: 1, y: 0, scale: 1, transition: { duration: 0.5, ease: EASE } } }}
            whileHover={{ y: -5, scale: 1.02 }} transition={{ type: 'spring', stiffness: 260, damping: 18 }}
          >
            <div style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase' }}>{kpi.label}</div>
            <div className="mono" style={{ fontSize: 22, fontWeight: 800, color: kpi.color, marginTop: 6 }}>
              <AnimatedNumber value={kpi.value} suffix={kpi.suffix ?? ''} decimals={kpi.decimals ?? 0} format={kpi.format} />
            </div>
          </motion.div>
        ))}
      </motion.div>

      <div className="card" style={{ padding: 20, marginBottom: 20 }}>
        <div className="page-label" style={{ marginBottom: 14 }}>{t('biz.cash_flow')}</div>
        {flow.length === 0 ? (
          <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{t('common.noData')}</p>
        ) : (
          <div style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: 160 }}>
            {flow.map((d, i) => (
              <div key={d.date} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 }} title={`${d.date}`}>
                <motion.div style={{ width: '100%', maxWidth: 22, background: 'rgba(0,207,160,0.55)', borderRadius: '3px 3px 0 0' }}
                  initial={{ height: 0 }} animate={{ height: Math.max(2, (d.inflow / maxFlow) * 70) }}
                  transition={{ delay: 0.2 + i * 0.04, duration: 0.5, ease: EASE }} />
                <motion.div style={{ width: '100%', maxWidth: 22, background: 'rgba(234,72,108,0.5)', borderRadius: '3px 3px 0 0' }}
                  initial={{ height: 0 }} animate={{ height: Math.max(2, (d.outflow / maxFlow) * 70) }}
                  transition={{ delay: 0.2 + i * 0.04, duration: 0.5, ease: EASE }} />
              </div>
            ))}
          </div>
        )}
        <div style={{ display: 'flex', gap: 14, marginTop: 10, fontSize: 11, color: 'var(--text-dim)' }}>
          <span>■ <span style={{ color: 'var(--green)' }}>Entrées</span></span>
          <span>■ <span style={{ color: 'var(--red)' }}>Sorties</span></span>
        </div>
      </div>

      <div className="card" style={{ padding: 20 }}>
        <div className="page-label" style={{ marginBottom: 14 }}>{t('biz.providers_perf')}</div>
        {data.providers.length === 0 ? (
          <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{t('empty.noProviders')}</p>
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
                  initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 + i * 0.05, duration: 0.35, ease: EASE }}>
                  <td style={{ padding: '8px 6px', color: 'var(--text-bright)', fontWeight: 600 }}>{p.provider}</td>
                  <td style={{ padding: '8px 6px' }}>{p.transactions}</td>
                  <td style={{ padding: '8px 6px' }}>{fmtMoney(p.volume_xaf, 'XAF')}</td>
                  <td style={{ padding: '8px 6px', color: p.success_rate >= 99 ? 'var(--green)' : 'var(--gold)' }}>{p.success_rate} %</td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
