import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { apiBeneficiariesList, apiBeneficiaryCreate, apiBeneficiarySetStatus, apiBeneficiaryVerify, type Beneficiary } from '../../api/client';
import { fmtMoney, pillForStatus, labelForStatus, METHOD_LABEL } from './ui';
import { useDashT } from '../../data/dashboard-i18n';

/** Bénéficiaires Business — CRUD réel (chiffré côté backend). */
export default function BeneficiariesPage() {
  const t = useDashT();
  const [items, setItems] = useState<Beneficiary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const [name, setName] = useState('');
  const [country, setCountry] = useState('CG');
  const [currency, setCurrency] = useState('XAF');
  const [method, setMethod] = useState('mobile_money');
  const [reference, setReference] = useState('');
  const [operator, setOperator] = useState('');
  const [saving, setSaving] = useState(false);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiBeneficiariesList();
    if (!res.success || !res.data) { setError(res.error || 'Erreur de chargement.'); setLoading(false); return; }
    setItems(res.data.items);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    const res = await apiBeneficiaryCreate({ name, country, currency, method, account_reference: reference, operator: operator || undefined });
    setSaving(false);
    if (!res.success) { setFormError(res.error || 'Création impossible.'); return; }
    setName(''); setReference(''); setOperator('');
    fetch();
  };

  const toggleStatus = async (b: Beneficiary) => {
    await apiBeneficiarySetStatus(b.id, b.status === 'active' ? 'inactive' : 'active');
    fetch();
  };

  const verify = async (id: number) => {
    await apiBeneficiaryVerify(id);
    fetch();
  };

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('nav.beneficiaries').toUpperCase()}</div>
        <div className="page-title">{t('page.beneficiaries')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>Gérez les destinataires de vos paiements. Les références sont chiffrées côté backend.</p>
      </motion.div>

      {/* Formulaire de création */}
      <div className="card" style={{ padding: 20, marginBottom: 20 }}>
        <div className="page-label" style={{ marginBottom: 14 }}>{t('form.new_beneficiary')}</div>
        <form onSubmit={create} style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Nom
            <input className="form-control" style={{ width: 220 }} value={name} onChange={e => setName(e.target.value)} placeholder="Nom du bénéficiaire" required />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Pays
            <input className="form-control" style={{ width: 70 }} value={country} onChange={e => setCountry(e.target.value.toUpperCase())} maxLength={2} required />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Devise
            <select className="form-control" style={{ width: 90 }} value={currency} onChange={e => setCurrency(e.target.value)}>
              {['XAF', 'XOF', 'EUR', 'USD', 'USDT'].map(c => <option key={c} value={c}>{c}</option>)}
            </select>
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Méthode
            <select className="form-control" style={{ width: 150 }} value={method} onChange={e => setMethod(e.target.value)}>
              {Object.entries(METHOD_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Référence
            <input className="form-control" style={{ width: 220 }} value={reference} onChange={e => setReference(e.target.value)} placeholder="Téléphone / IBAN / adresse" required />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Opérateur / banque
            <input className="form-control" style={{ width: 170 }} value={operator} onChange={e => setOperator(e.target.value)} placeholder="MTN, Orange…" />
          </label>
          <button className="se-cta" type="submit" disabled={saving} style={{ fontSize: 12 }}>{saving ? '…' : `+ ${t('common.create')}`}</button>
        </form>
        {formError && <div className="pill p-r" style={{ marginTop: 12 }}>{formError}</div>}
      </div>

      {/* Liste */}
      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>
      ) : error ? (
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><p style={{ color: 'var(--text-mid)', marginBottom: 12 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>
      ) : items.length === 0 ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 40, marginBottom: 12 }}>👥</div>
          <p style={{ color: 'var(--text-mid)' }}>{t('empty.noBeneficiaries')}</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 12 }}>
          {items.map(b => (
            <div key={b.id} style={{ display: 'flex', alignItems: 'center', gap: 14, padding: 14, borderBottom: '1px solid var(--border)' }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 700, color: 'var(--text-bright)' }}>{b.name}</div>
                <div style={{ fontSize: 12, color: 'var(--text-mid)' }}>
                  {b.country} · {METHOD_LABEL[b.method]} · {fmtMoney(0, b.currency).replace('0', '')}{b.currency}
                  {b.reference_masked && <span className="mono" style={{ marginLeft: 8, color: 'var(--text-dim)' }}>{b.reference_masked}</span>}
                </div>
              </div>
              <span className={`pill ${pillForStatus(b.status)}`} style={{ fontSize: 10 }}>{labelForStatus(b.status)}</span>
              <span className={`pill ${pillForStatus(b.verification_status)}`} style={{ fontSize: 10 }}>{labelForStatus(b.verification_status)}</span>
              <div style={{ display: 'flex', gap: 6 }}>
                {b.verification_status !== 'verified' && (
                  <button className="pill p-c" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => verify(b.id)}>{t('common.verify')}</button>
                )}
                <button className="pill p-g" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => toggleStatus(b)}>
                  {b.status === 'active' ? t('common.deactivate') : t('common.activate')}
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
