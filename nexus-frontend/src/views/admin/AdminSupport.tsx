import { useEffect, useState } from 'react';
import { apiControlAudit, apiControlClients } from '../../api/client';
import { Stat, Panel, fmtDate } from './adminUi';

interface Row { id: number; actor: string | null; action: string; created_at: string; }
interface Client { id: number; full_name: string; email: string; account_type: string; status: string; kyc_level: string; }

export default function AdminSupport() {
  const [tickets, setTickets] = useState<Row[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  useEffect(() => {
    void Promise.all([apiControlAudit(), apiControlClients()]).then(([a, c]) => {
      if (a.success && a.data) setTickets((a.data.items as unknown as Row[]).filter((r) => r.action.startsWith('support.')));
      if (c.success && c.data) setClients(c.data.items as unknown as Client[]);
    });
  }, []);

  const pending = clients.filter((c) => c.status === 'PENDING').length;
  const active = clients.filter((c) => c.status === 'ACTIVE').length;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Clients actifs" value={active} tone="var(--green)" />
        <Stat label="Comptes en attente" value={pending} tone="var(--gold)" />
        <Stat label="Tickets support" value={tickets.length} tone="var(--cyan2)" />
        <Stat label="KYC de base" value={clients.filter((c) => c.kyc_level === 'basic').length} sub="à faire monter de niveau" tone="var(--violet)" />
      </div>
      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🧑‍💻 Dernières requêtes support</div>
        <Panel title="" pad={0}>
          {tickets.length === 0 ? <div style={{ padding: 20, textAlign: 'center', fontSize: 12.5, color: 'var(--text-dim)' }}>Aucun ticket support enregistré.</div> : tickets.map((t) => (
            <div key={t.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 4px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
              <div>
                <div style={{ color: 'var(--text-bright)' }}>{t.actor ?? '—'}</div>
                <div style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)', fontSize: 11.5 }}>{t.action}</div>
              </div>
              <span style={{ fontSize: 11.5, color: 'var(--text-dim)' }}>{fmtDate(t.created_at)}</span>
            </div>
          ))}
        </Panel>
      </div>
    </div>
  );
}
