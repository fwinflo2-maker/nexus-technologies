import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { apiBusinessOverview, type BusinessOverview } from '../../api/client';
import { fmtMoney } from './ui';
import { useDashT } from '../../data/dashboard-i18n';

/** Business Dashboard — Financial Operations Console (données 100 % backend). */
export default function BusinessDashboard() {
  const [data, setData] = useState<BusinessOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

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
  const td = useDashT();
  const kpis: [string, string, string][] = [
    [td('biz.total_assets'), fmtMoney(t.total_assets, t.ref_currency), 'var(--cyan)'],
    [td('biz.available'), fmtMoney(t.available, t.ref_currency), 'var(--green)'],
    [td('biz.pending'), fmtMoney(t.pending, t.ref_currency), 'var(--gold)'],
    [td('biz.in_transit'), fmtMoney(t.in_transit, t.ref_currency), 'var(--violet)'],
    [td('biz.settlement'), fmtMoney(t.settlement, t.ref_currency), 'var(--gold)'],
    [td('biz.payables'), fmtMoney(t.payables, t.ref_currency), 'var(--red)'],
    [td('biz.volume_30d'), fmtMoney(t.volume_xaf, 'XAF'), 'var(--cyan)'],
    [td('biz.fees_30d'), fmtMoney(t.fees_ref, t.ref_currency), 'var(--text-mid)'],
    [td('biz.success_rate'), `${t.success_rate} %`, 'var(--green)'],
    [td('biz.avg_exec'), t.avg_exec_sec ? `${t.avg_exec_sec} s` : '—', 'var(--text-mid)'],
  ];

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">YOUR FINANCIAL OPERATIONS, ORCHESTRATED BY NEXUS</div>
        <div className="page-title">Console financière</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          Actifs, flux, frais et performance — calculés depuis le ledger en temps réel.
        </p>
      </motion.div>

      {/* KPIs */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 14, marginBottom: 22 }}>
        {kpis.map(([label, value, color], i) => (
          <motion.div key={label} className="card" style={{ padding: 18 }} initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
            <div style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: '0.06em' }}>{label}</div>
            <div className="mono" style={{ fontSize: 22, fontWeight: 800, color, marginTop: 6 }}>{value}</div>
          </motion.div>
        ))}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, alignItems: 'start' }}>
        {/* Wallets */}
        <div className="card" style={{ padding: 20 }}>
          <div className="page-label" style={{ marginBottom: 12 }}>{td('biz.treasury_by_currency')}</div>
          {data.wallets.length === 0 ? (
            <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{td('empty.noWallets')}</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {data.wallets.map(w => (
                <div key={w.currency} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid var(--border)' }}>
                  <div>
                    <div style={{ fontWeight: 700, color: 'var(--text-bright)', fontSize: 14 }}>{w.currency}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>disponible {fmtMoney(w.available, w.currency)}</div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div className="mono" style={{ fontWeight: 700, color: 'var(--text-bright)' }}>{fmtMoney(w.balance, w.currency)}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>≈ {fmtMoney(w.ref_value, 'EUR')}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
          <Link to="/treasury" className="btn btn-ghost" style={{ marginTop: 14, fontSize: 11 }}>Ouvrir la trésorerie →</Link>
        </div>

        {/* Cash flow + providers */}
        <div className="card" style={{ padding: 20 }}>
          <div className="page-label" style={{ marginBottom: 12 }}>{td('biz.cash_flow')}</div>
          {data.cash_flow.length === 0 ? (
            <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{td('empty.noCashFlow')}</p>
          ) : (
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: 120 }}>
              {data.cash_flow.map(d => {
                const max = Math.max(1, ...data.cash_flow.map(x => Math.max(x.inflow, x.outflow)));
                return (
                  <div key={d.date} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 3 }} title={`${d.date} · in ${fmtMoney(d.inflow, 'EUR')} / out ${fmtMoney(d.outflow, 'EUR')}`}>
                    <div style={{ width: '100%', maxWidth: 26, height: Math.max(2, (d.inflow / max) * 90), background: 'rgba(0,207,160,0.55)', borderRadius: '3px 3px 0 0' }} />
                    <div style={{ width: '100%', maxWidth: 26, height: Math.max(2, (d.outflow / max) * 90), background: 'rgba(234,72,108,0.5)', borderRadius: '3px 3px 0 0' }} />
                  </div>
                );
              })}
            </div>
          )}
          <div style={{ display: 'flex', gap: 14, marginTop: 10, fontSize: 11, color: 'var(--text-dim)' }}>
            <span>■ <span style={{ color: 'var(--green)' }}>Entrées</span></span>
            <span>■ <span style={{ color: 'var(--red)' }}>Sorties</span></span>
          </div>
        </div>
      </div>

      {/* Providers */}
      <div className="card" style={{ padding: 20, marginTop: 20 }}>
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
              {data.providers.map(p => (
                <tr key={p.provider} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td style={{ padding: '8px 6px', color: 'var(--text-bright)', fontWeight: 600 }}>{p.provider}</td>
                  <td style={{ padding: '8px 6px' }}>{p.transactions}</td>
                  <td style={{ padding: '8px 6px' }}>{fmtMoney(p.volume_xaf, 'XAF')}</td>
                  <td style={{ padding: '8px 6px', color: p.success_rate >= 99 ? 'var(--green)' : 'var(--gold)' }}>{p.success_rate} %</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
