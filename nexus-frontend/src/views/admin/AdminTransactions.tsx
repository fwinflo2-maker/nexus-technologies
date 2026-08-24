import { useCallback, useEffect, useState } from 'react';
import { apiAdminTransactions, type AdminTransaction } from '../../api/client';
import { Stat, Table, fmtMoney, fmtDate, Panel, Badge } from './adminUi';

const STATUS = ['', 'completed', 'processing', 'pending', 'failed'];
const CURRENCIES = ['', 'EUR', 'USD', 'XAF'];
const TYPES = ['', 'send', 'receive', 'fx', 'convert'];

export default function AdminTransactions() {
  const [items, setItems] = useState<AdminTransaction[]>([]);
  const [meta, setMeta] = useState({ total: 0, page: 1, pages: 1, per: 25 });
  const [filters, setFilters] = useState<Record<string, string>>({ status: '', currency: '', type: '', q: '' });
  const [loading, setLoading] = useState(true);

  const load = useCallback(async (page = 1) => {
    setLoading(true);
    const res = await apiAdminTransactions({ ...filters, page, per: 25 });
    if (res.success && res.data) { setItems(res.data.items); setMeta({ total: res.data.total, page: res.data.page, pages: res.data.pages, per: res.data.per }); }
    setLoading(false);
  }, [filters]);

  useEffect(() => { void load(1); }, [load]);

  const set = (k: string, v: string) => { setFilters((f) => ({ ...f, [k]: v })); };
  const select = (options: string[], k: string, label: string) => (
    <select value={filters[k]} onChange={(e) => set(k, e.target.value)} style={selStyle}>
      <option value="">{label}</option>
      {options.slice(1).map((o) => <option key={o} value={o}>{o}</option>)}
    </select>
  );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Total" value={meta.total} />
        <Stat label="Terminées" value={items.filter((t) => t.status === 'completed').length} tone="var(--green)" />
        <Stat label="En cours" value={items.filter((t) => t.status === 'processing' || t.status === 'pending').length} tone="var(--gold)" />
        <Stat label="Échouées" value={items.filter((t) => t.status === 'failed').length} tone="var(--red)" />
      </div>

      <div className="card" style={{ padding: 12, display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
        <input placeholder="Rechercher (label, description, client, email)…" value={filters.q}
          onChange={(e) => set('q', e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') void load(1); }}
          style={{ ...inputStyle, minWidth: 260 }} />
        {select(STATUS, 'status', 'Statut')}
        {select(CURRENCIES, 'currency', 'Devise')}
        {select(TYPES, 'type', 'Type')}
        <button onClick={() => void load(1)} style={btnStyle}>Filtrer</button>
        <button onClick={() => { setFilters({ status: '', currency: '', type: '', q: '' }); }} style={{ ...btnStyle, background: 'transparent' }}>Réinitialiser</button>
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-dim)' }}>{meta.total} transaction(s) · page {meta.page}/{meta.pages}</span>
      </div>

      {loading ? <div className="card" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div> : (
        <Panel title="Registre des transactions" icon="🔄">
          <Table
            head={['ID', 'Date', 'Client', 'Type', 'Libellé', 'Montant', 'Devise', 'Provider', 'Statut']}
            rows={items.map((t) => [
              <span key="id" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>#{t.id}</span>,
              <span key="date" style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{fmtDate(t.created_at)}</span>,
              <span key="client">
                <div style={{ color: 'var(--text-bright)', fontWeight: 600 }}>{t.user_name ?? '—'}</div>
                <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{t.user_email ?? ''}</div>
              </span>,
              <span key="type" style={{ textTransform: 'capitalize' }}>{t.type}</span>,
              <span key="label">{t.label}</span>,
              <span key="amount" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-bright)' }}>{fmtMoney(Number(t.amount), t.currency)}</span>,
              t.currency,
              <span key="provider" style={{ textTransform: 'capitalize' }}>{t.provider ?? '—'}</span>,
              <Badge key="status" status={t.status} />,
            ])}
          />
          {meta.pages > 1 && (
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 10, alignItems: 'center' }}>
              <button disabled={meta.page <= 1} onClick={() => void load(meta.page - 1)} style={{ ...btnStyle, opacity: meta.page <= 1 ? 0.4 : 1 }}>‹ Préc</button>
              <span style={{ fontSize: 12, color: 'var(--text-mid)' }}>{meta.page} / {meta.pages}</span>
              <button disabled={meta.page >= meta.pages} onClick={() => void load(meta.page + 1)} style={{ ...btnStyle, opacity: meta.page >= meta.pages ? 0.4 : 1 }}>Suiv ›</button>
            </div>
          )}
        </Panel>
      )}
    </div>
  );
}

const selStyle: React.CSSProperties = {
  background: 'var(--panel2)', color: 'var(--text-main)', border: '1px solid var(--border-soft)',
  borderRadius: 8, padding: '7px 10px', fontSize: 12.5, outline: 'none',
};
const inputStyle: React.CSSProperties = {
  background: 'var(--panel2)', color: 'var(--text-main)', border: '1px solid var(--border-soft)',
  borderRadius: 8, padding: '7px 12px', fontSize: 12.5, outline: 'none', flex: 1,
};
const btnStyle: React.CSSProperties = {
  background: 'var(--cyan)', color: '#fff', border: 'none', borderRadius: 8,
  padding: '7px 14px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer',
};
