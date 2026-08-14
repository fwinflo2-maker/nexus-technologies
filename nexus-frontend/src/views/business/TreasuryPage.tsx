import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { apiBusinessTreasury, type BusinessWallet } from '../../api/client';
import { fmtMoney } from './ui';

/** Trésorerie — multi-devises, liquidité, exposition FX (backend). */
export default function TreasuryPage() {
  const [wallets, setWallets] = useState<BusinessWallet[]>([]);
  const [totals, setTotals] = useState<{ total_assets: number; available: number; ref_currency: string } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiBusinessTreasury();
    if (!res.success || !res.data) {
      setError(res.error || 'Impossible de charger la trésorerie.');
      setLoading(false);
      return;
    }
    setWallets(res.data.wallets);
    setTotals({ total_assets: res.data.totals.total_assets, available: res.data.totals.available, ref_currency: res.data.totals.ref_currency });
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  if (loading) return <div className="card card-hi-c" style={{ padding: 48, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Chargement…</p></div>;
  if (error) return <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><p style={{ color: 'var(--text-mid)', marginBottom: 14 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>;

  const maxVal = Math.max(1, ...wallets.map(w => w.ref_value));

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">TRÉSORERIE</div>
        <div className="page-title">Trésorerie multi-devises</div>
      </motion.div>

      {totals && (
        <div style={{ display: 'flex', gap: 14, marginBottom: 22, flexWrap: 'wrap' }}>
          <div className="card" style={{ padding: 18, flex: 1, minWidth: 200 }}>
            <div style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase' }}>Liquidité totale</div>
            <div className="mono" style={{ fontSize: 24, fontWeight: 800, color: 'var(--cyan)', marginTop: 6 }}>{fmtMoney(totals.total_assets, totals.ref_currency)}</div>
          </div>
          <div className="card" style={{ padding: 18, flex: 1, minWidth: 200 }}>
            <div style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase' }}>Disponible immédiatement</div>
            <div className="mono" style={{ fontSize: 24, fontWeight: 800, color: 'var(--green)', marginTop: 6 }}>{fmtMoney(totals.available, totals.ref_currency)}</div>
          </div>
        </div>
      )}

      {wallets.length === 0 ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 40, marginBottom: 12 }}>💼</div>
          <p style={{ color: 'var(--text-mid)' }}>Aucun wallet pour le moment.</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 20 }}>
          <div className="page-label" style={{ marginBottom: 14 }}>Positions par devise + exposition FX</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            {wallets.map(w => (
              <div key={w.currency} style={{ borderBottom: '1px solid var(--border)', paddingBottom: 14 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div>
                    <span style={{ fontWeight: 700, color: 'var(--text-bright)', fontSize: 15 }}>{w.currency}</span>
                    <span style={{ fontSize: 11, color: 'var(--text-dim)', marginLeft: 8 }}>solde {fmtMoney(w.balance, w.currency)}</span>
                  </div>
                  <div className="mono" style={{ fontWeight: 700, color: 'var(--text-bright)' }}>≈ {fmtMoney(w.ref_value, 'EUR')}</div>
                </div>
                <div style={{ display: 'flex', gap: 10, marginTop: 6, fontSize: 11, color: 'var(--text-dim)' }}>
                  <span>dispo {fmtMoney(w.available, w.currency)}</span>
                  {w.pending > 0 && <span>· en attente {fmtMoney(w.pending, w.currency)}</span>}
                  {w.in_transit > 0 && <span>· en transit {fmtMoney(w.in_transit, w.currency)}</span>}
                  {w.settlement > 0 && <span>· règlement {fmtMoney(w.settlement, w.currency)}</span>}
                </div>
                <div style={{ marginTop: 8, height: 6, background: 'var(--panel2)', borderRadius: 3, overflow: 'hidden' }}>
                  <div style={{ width: `${Math.round((w.ref_value / maxVal) * 100)}%`, height: '100%', background: 'linear-gradient(90deg, var(--cyan), var(--violet))' }} />
                </div>
              </div>
            ))}
          </div>
          <Link to="/convert" className="btn btn-ghost" style={{ marginTop: 16, fontSize: 11 }}>Convertir des devises →</Link>
        </div>
      )}
    </div>
  );
}
