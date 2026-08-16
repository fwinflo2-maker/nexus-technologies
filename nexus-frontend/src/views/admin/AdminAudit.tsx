import { useEffect, useState } from 'react';
import { apiControlAudit } from '../../api/client';
import { Stat, Table, fmtDate } from './adminUi';

interface AuditRow { id: number; user_id: number | null; actor: string | null; action: string; entity_type: string; metadata: Record<string, unknown> | null; ip_address: string | null; created_at: string; }

export default function AdminAudit() {
  const [rows, setRows] = useState<AuditRow[]>([]);
  const [filter, setFilter] = useState('');
  useEffect(() => {
    void apiControlAudit().then((res) => { if (res.success && res.data) setRows(res.data.items as unknown as AuditRow[]); });
  }, []);
  const filtered = filter ? rows.filter((r) => r.action.toLowerCase().includes(filter.toLowerCase())) : rows;

  const counts = rows.reduce<Record<string, number>>((acc, r) => { acc[r.action] = (acc[r.action] ?? 0) + 1; return acc; }, {});
  const topActions = Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, 5);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Événements" value={rows.length} />
        <Stat label="Actions distinctes" value={Object.keys(counts).length} />
        <Stat label="Sessions / auth" value={counts['auth.login'] ?? 0} tone="var(--cyan2)" />
        <Stat label="KYC / conformité" value={(counts['kyc.approve'] ?? 0) + (counts['kyc.reject'] ?? 0)} tone="var(--violet)" />
      </div>
      {topActions.length > 0 && (
        <div className="card" style={{ padding: 12, display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontSize: 11, color: 'var(--text-dim)' }}>Filtrer par action :</span>
          <button onClick={() => setFilter('')} style={chip(filter === '')}>Tout</button>
          {topActions.map(([a]) => <button key={a} onClick={() => setFilter(a)} style={chip(filter === a)}>{a} · {counts[a]}</button>)}
        </div>
      )}
      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>📜 Journal d'audit {filter && <span style={{ color: 'var(--cyan)' }}>· {filter}</span>}</div>
        <Table
          head={['Heure', 'Acteur', 'Action', 'Entité', 'IP']}
          rows={filtered.map((r) => [
            <span style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{fmtDate(r.created_at)}</span>,
            <span style={{ color: 'var(--text-bright)' }}>{r.actor ?? '—'}</span>,
            <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)' }}>{r.action}</span>,
            <span style={{ textTransform: 'capitalize' }}>{r.entity_type ?? '—'}</span>,
            <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>{r.ip_address ?? '—'}</span>,
          ])}
          empty={filter ? 'Aucun événement pour ce filtre.' : 'Aucun événement.'}
        />
      </div>
    </div>
  );
}

const chip = (active: boolean): React.CSSProperties => ({
  background: active ? 'var(--cyan)' : 'var(--panel2)', color: active ? '#fff' : 'var(--text-main)',
  border: '1px solid var(--border-soft)', borderRadius: 20, padding: '5px 12px', fontSize: 11.5,
  cursor: 'pointer', fontWeight: 600,
});
