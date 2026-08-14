import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { apiPaymentsList, apiPaymentAction, type Payment } from '../../api/client';
import { fmtMoney } from './ui';
import { useDashT } from '../../data/dashboard-i18n';

/** Approbations — file des paiements en attente (approuver / rejeter). */
export default function ApprovalsPage() {
  const t = useDashT();
  const [items, setItems] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiPaymentsList({ status: 'pending_approval', per_page: 50 });
    if (!res.success || !res.data) { setError(res.error || 'Erreur de chargement.'); setLoading(false); return; }
    setItems(res.data.items);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  const decide = async (id: number, action: 'approve' | 'reject') => {
    setBanner(null);
    const res = await apiPaymentAction(id, action);
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Action impossible.')); return; }
    setBanner(action === 'approve' ? '✅ Paiement approuvé — prêt à être exécuté.' : 'Paiement rejeté.');
    fetch();
  };

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('nav.approvals').toUpperCase()}</div>
        <div className="page-title">{t('page.approvals')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>File des paiements en attente. L'approbation est vérifiée côté backend (rôles Finance Manager / Admin / Owner).</p>
      </motion.div>

      {banner && <div className="card card-hi-c" style={{ padding: 14, marginBottom: 16, fontSize: 13 }}>{banner}</div>}

      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>
      ) : error ? (
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><p style={{ color: 'var(--text-mid)', marginBottom: 12 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>
      ) : items.length === 0 ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 40, marginBottom: 12 }}>✓</div>
          <p style={{ color: 'var(--text-mid)' }}>{t('empty.noApprovals')}</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 12 }}>
          {items.map(p => (
            <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 14, borderBottom: '1px solid var(--border)', flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 220 }}>
                <div style={{ fontWeight: 700, color: 'var(--text-bright)' }}>
                  Paiement #{p.id} — {fmtMoney(p.amount, p.source_currency)}
                  {p.dest_amount != null && <span style={{ color: 'var(--green)', marginLeft: 6 }}>→ {fmtMoney(p.dest_amount, p.dest_currency)}</span>}
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-mid)' }}>
                  {p.purpose || 'Sans objet'} · {p.provider || '—'} · frais {fmtMoney(p.fee, p.fee_currency)} · créé le {new Date(p.created_at).toLocaleDateString('fr-FR')}
                </div>
              </div>
              <button className="pill p-gr" style={{ cursor: 'pointer', fontSize: 12 }} onClick={() => decide(p.id, 'approve')}>✓ {t('common.approve')}</button>
              <button className="pill p-r" style={{ cursor: 'pointer', fontSize: 12 }} onClick={() => decide(p.id, 'reject')}>✕ {t('common.reject')}</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
