import { useEffect, useState } from 'react';
import { apiAdminRisk, apiControlAudit } from '../../api/client';
import { Stat, Table, fmtDate } from './adminUi';

interface Row { id: number; actor: string | null; action: string; entity_type: string; ip_address: string | null; created_at: string; }

export default function AdminSecurity() {
  const [rows, setRows] = useState<Row[]>([]);
  const [suspended, setSuspended] = useState(0);
  useEffect(() => {
    void Promise.all([apiAdminRisk(), apiControlAudit()]).then(([r, a]) => {
      if (r.success && r.data) setSuspended(r.data.risk.suspended_accounts);
      if (a.success && a.data) setRows(a.data.items as unknown as Row[]);
    });
  }, []);
  const authEvents = rows.filter((r) => r.action.startsWith('auth.'));
  const riskEvents = rows.filter((r) => r.action.startsWith('risk.'));

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Événements auth" value={authEvents.length} tone="var(--cyan2)" />
        <Stat label="Alertes risque" value={riskEvents.length} tone="var(--red)" />
        <Stat label="Comptes suspendus" value={suspended} tone="var(--gold)" />
        <Stat label="Connexions admin" value={rows.filter((r) => r.action === 'auth.login').length} tone="var(--green)" />
      </div>
      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🔐 Événements de sécurité récents</div>
        <Table
          head={['Heure', 'Acteur', 'Action', 'Entité', 'IP']}
          rows={authEvents.concat(riskEvents).slice(0, 40).map((r) => [
            <span style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{fmtDate(r.created_at)}</span>,
            <span style={{ color: 'var(--text-bright)' }}>{r.actor ?? '—'}</span>,
            <span style={{ fontFamily: 'var(--font-mono)', color: r.action.startsWith('risk.') ? 'var(--red)' : 'var(--cyan2)' }}>{r.action}</span>,
            <span style={{ textTransform: 'capitalize' }}>{r.entity_type ?? '—'}</span>,
            <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>{r.ip_address ?? '—'}</span>,
          ])}
        />
      </div>
    </div>
  );
}
