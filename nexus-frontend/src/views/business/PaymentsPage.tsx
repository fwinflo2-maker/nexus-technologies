import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import {
  apiPaymentsList, apiPaymentCreate, apiPaymentAction,
  apiBeneficiariesList, type Payment, type Beneficiary,
} from '../../api/client';
import { fmtMoney, pillForStatus, labelForStatus } from './ui';
import { useDashT } from '../../data/dashboard-i18n';

/** Paiements Business — workflow réel : créer → quote → approbation → exécution. */
export default function PaymentsPage() {
  const t = useDashT();
  const [payments, setPayments] = useState<Payment[]>([]);
  const [beneficiaries, setBeneficiaries] = useState<Beneficiary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);

  const [beneficiaryId, setBeneficiaryId] = useState('');
  const [amount, setAmount] = useState('');
  const [sourceCurrency, setSourceCurrency] = useState('EUR');
  const [purpose, setPurpose] = useState('');
  const [saving, setSaving] = useState(false);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const [pRes, bRes] = await Promise.all([apiPaymentsList({ per_page: 50 }), apiBeneficiariesList()]);
    if (!pRes.success || !pRes.data) { setError(pRes.error || 'Erreur de chargement des paiements.'); setLoading(false); return; }
    setPayments(pRes.data.items);
    if (bRes.success && bRes.data) setBeneficiaries(bRes.data.items);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setBanner(null);
    const res = await apiPaymentCreate({
      beneficiary_id: Number(beneficiaryId),
      amount: Number(amount),
      source_currency: sourceCurrency,
      purpose: purpose || undefined,
      objective: 'optimized',
    });
    setSaving(false);
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Création impossible.')); return; }
    const p = res.data!.payment;
    setBanner(`✅ Paiement #${p.id} créé (${fmtMoney(p.amount, p.source_currency)} → ${fmtMoney(p.dest_amount, p.dest_currency)} via ${p.provider}). Soumettez-le pour approbation.`);
    setAmount(''); setPurpose('');
    fetch();
  };

  const act = async (id: number, action: 'submit' | 'approve' | 'reject' | 'execute' | 'cancel') => {
    setBanner(null);
    const res = await apiPaymentAction(id, action);
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Action impossible.')); return; }
    if (action === 'execute') {
      const tx = res.data?.transaction;
      setBanner(tx ? `✅ Paiement exécuté — transaction #${tx.id} réglée et enregistrée dans le ledger.` : '✅ Paiement exécuté.');
    }
    fetch();
  };

  const activeBeneficiaries = beneficiaries.filter(b => b.status === 'active');

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('nav.payments').toUpperCase()}</div>
        <div className="page-title">{t('page.payments')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>Créer → Quote → Approbation → Exécution → Ledger.</p>
      </motion.div>

      {banner && <div className="card card-hi-c" style={{ padding: 14, marginBottom: 16, fontSize: 13, color: 'var(--text-bright)' }}>{banner}</div>}

      {/* Formulaire */}
      <div className="card" style={{ padding: 20, marginBottom: 20 }}>
        <div className="page-label" style={{ marginBottom: 14 }}>{t('form.new_payment')}</div>
        <form onSubmit={create} style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>{t('nav.beneficiaries')}
            <select className="form-control" style={{ width: 240 }} value={beneficiaryId} onChange={e => setBeneficiaryId(e.target.value)} required>
              <option value="">— choisir —</option>
              {activeBeneficiaries.map(b => <option key={b.id} value={b.id}>{b.name} ({b.country} · {b.currency})</option>)}
            </select>
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>{t('form.amount')}
            <input className="form-control" style={{ width: 130 }} type="number" min="0.01" step="0.01" value={amount} onChange={e => setAmount(e.target.value)} placeholder="0.00" required />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>{t('form.source_currency')}
            <select className="form-control" style={{ width: 90 }} value={sourceCurrency} onChange={e => setSourceCurrency(e.target.value)}>
              {['EUR', 'USD', 'GBP', 'XAF', 'USDT'].map(c => <option key={c} value={c}>{c}</option>)}
            </select>
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>{t('form.purpose')}
            <input className="form-control" style={{ width: 200 }} value={purpose} onChange={e => setPurpose(e.target.value)} placeholder="Facture, salaire…" />
          </label>
          <button className="se-cta" type="submit" disabled={saving || activeBeneficiaries.length === 0} style={{ fontSize: 12 }}>
            {saving ? '…' : `${t('common.create')} + quote`}
          </button>
        </form>
        {activeBeneficiaries.length === 0 && <div className="pill p-g" style={{ marginTop: 12 }}>{t('empty.noBeneficiaryActive')}</div>}
      </div>

      {/* Liste */}
      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>
      ) : error ? (
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><p style={{ color: 'var(--text-mid)', marginBottom: 12 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>
      ) : payments.length === 0 ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 40, marginBottom: 12 }}>💳</div>
          <p style={{ color: 'var(--text-mid)' }}>{t('empty.noPayments')}</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 12 }}>
          {payments.map(p => (
            <div key={p.id} style={{ padding: 14, borderBottom: '1px solid var(--border)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 220 }}>
                  <div style={{ fontWeight: 700, color: 'var(--text-bright)' }}>
                    Paiement #{p.id} — {fmtMoney(p.amount, p.source_currency)}
                    {p.dest_amount != null && <span style={{ color: 'var(--green)', marginLeft: 6 }}>→ {fmtMoney(p.dest_amount, p.dest_currency)}</span>}
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--text-mid)' }}>
                    {p.purpose || 'Sans objet'} · provider {p.provider || '—'} · frais {fmtMoney(p.fee, p.fee_currency)}
                    {p.transaction_id && <span className="mono" style={{ marginLeft: 8 }}>tx #{p.transaction_id}</span>}
                  </div>
                </div>
                <span className={`pill ${pillForStatus(p.status)}`} style={{ fontSize: 10 }}>{labelForStatus(p.status)}</span>
                <div style={{ display: 'flex', gap: 6 }}>
                  {p.status === 'draft' && (
                    <>
                      <button className="pill p-c" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => act(p.id, 'submit')}>{t('common.submit')}</button>
                      <button className="pill p-v" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => act(p.id, 'cancel')}>{t('common.cancel')}</button>
                    </>
                  )}
                  {p.status === 'pending_approval' && (
                    <>
                      <button className="pill p-gr" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => act(p.id, 'approve')}>{t('common.approve')}</button>
                      <button className="pill p-r" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => act(p.id, 'reject')}>{t('common.reject')}</button>
                    </>
                  )}
                  {p.status === 'approved' && (
                    <button className="se-cta" style={{ fontSize: 11 }} onClick={() => act(p.id, 'execute')}>{t('common.execute')}</button>
                  )}
                  {p.status === 'failed' && (
                    <button className="pill p-g" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => act(p.id, 'submit')}>Relancer</button>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
