import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { apiReconciliationList, apiReconciliationUpsert, apiReconciliationResolve, type ReconciliationItem } from '../../api/client';
import { fmtMoney, pillForStatus, labelForStatus } from './ui';
import { useDashT } from '../../data/dashboard-i18n';

/** Rapprochement ledger ↔ relevés provider (réel). */
export default function ReconciliationPage() {
  const t = useDashT();
  const [items, setItems] = useState<ReconciliationItem[]>([]);
  const [filter, setFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const [refInputs, setRefInputs] = useState<Record<number, { ref: string; actual: string }>>({});

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiReconciliationList();
    if (!res.success || !res.data) { setError(res.error || 'Erreur de chargement.'); setLoading(false); return; }
    setItems(res.data.items);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  const upsert = async (txId: number) => {
    const input = refInputs[txId];
    if (!input || input.ref.trim() === '' || input.actual.trim() === '') {
      setBanner('⚠️ Renseignez une référence provider et un montant réel.');
      return;
    }
    setBanner(null);
    const res = await apiReconciliationUpsert({ transaction_id: txId, provider_reference: input.ref, actual_amount: Number(input.actual) });
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Rapprochement impossible.')); return; }
    setBanner(`Rapprochement enregistré → statut « ${labelForStatus(res.data!.status)} ».`);
    fetch();
  };

  const resolve = async (itemId: number) => {
    const res = await apiReconciliationResolve(itemId);
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Impossible de résoudre.')); return; }
    fetch();
  };

  const visible = filter ? items.filter(i => i.status === filter) : items;

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('nav.reconciliation').toUpperCase()}</div>
        <div className="page-title">{t('page.reconciliation')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>Comparez le ledger Nexus aux relevés providers. Les écarts sont détectés automatiquement.</p>
      </motion.div>

      {banner && <div className="card card-hi-c" style={{ padding: 14, marginBottom: 16, fontSize: 13 }}>{banner}</div>}

      <div className="card" style={{ padding: 14, marginBottom: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {['', 'pending', 'matched', 'discrepancy', 'resolved'].map(s => (
          <button key={s || 'all'} className={`pill ${filter === s ? 'p-c' : 'p'}`} style={{ cursor: 'pointer', fontSize: 12 }} onClick={() => setFilter(s)}>
            {s === '' ? t('common.all') : labelForStatus(s)}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>
      ) : error ? (
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><p style={{ color: 'var(--text-mid)', marginBottom: 12 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>
      ) : visible.length === 0 ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 40, marginBottom: 12 }}>🧾</div>
          <p style={{ color: 'var(--text-mid)' }}>{t('empty.noReconciliation')}</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 12 }}>
          {visible.map(i => (
            <div key={i.transaction_id} style={{ padding: 14, borderBottom: '1px solid var(--border)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 240 }}>
                  <div style={{ fontWeight: 700, color: 'var(--text-bright)' }}>
                    Transaction #{i.transaction_id} · {i.provider || '—'}
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--text-mid)' }}>
                    attendu {fmtMoney(i.expected_amount, i.currency)}
                    {i.actual_amount != null && <span style={{ marginLeft: 6 }}>· réel {fmtMoney(i.actual_amount, i.currency)}</span>}
                    {i.provider_reference && <span className="mono" style={{ marginLeft: 8, color: 'var(--text-dim)' }}>ref {i.provider_reference}</span>}
                  </div>
                </div>
                <span className={`pill ${pillForStatus(i.status)}`} style={{ fontSize: 10 }}>{labelForStatus(i.status)}</span>
                {i.status !== 'resolved' && (
                  i.item_id ? (
                    <button className="pill p-c" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => resolve(i.item_id!)}>{t('status.resolved')}</button>
                  ) : (
                    <div style={{ display: 'flex', gap: 6 }}>
                      <input className="form-control" style={{ width: 130 }} placeholder="Réf provider"
                        value={refInputs[i.transaction_id]?.ref ?? ''}
                        onChange={e => setRefInputs(s => ({ ...s, [i.transaction_id]: { ...s[i.transaction_id], ref: e.target.value } }))} />
                      <input className="form-control" style={{ width: 110 }} type="number" placeholder="Montant réel"
                        value={refInputs[i.transaction_id]?.actual ?? ''}
                        onChange={e => setRefInputs(s => ({ ...s, [i.transaction_id]: { ...s[i.transaction_id], actual: e.target.value } }))} />
                      <button className="pill p-g" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => upsert(i.transaction_id)}>{t('nav.reconciliation')}</button>
                    </div>
                  )
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
