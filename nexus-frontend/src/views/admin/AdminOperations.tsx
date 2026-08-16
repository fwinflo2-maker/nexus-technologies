import { useEffect, useState } from 'react';
import { apiAdminOperations } from '../../api/client';
import { Badge, Stat, Table, fmtMoney, fmtDate } from './adminUi';

interface Op { id: number; type: string; label: string; amount: number; currency: string; status: string; provider: string | null; execution_time_seconds: number | null; created_at: string; user_name: string | null; user_email: string | null; }

export default function AdminOperations() {
  const [items, setItems] = useState<Op[]>([]);
  const [counters, setCounters] = useState({ pending: 0, processing: 0, completed: 0, failed: 0 });
  const [avg, setAvg] = useState(0);
  useEffect(() => {
    void apiAdminOperations().then((res) => { if (res.success && res.data) { setItems(res.data.items as unknown as Op[]); setCounters(res.data.counters); setAvg(res.data.avg_execution_seconds); } });
  }, []);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="En file (pending)" value={counters.pending} tone="var(--gold)" />
        <Stat label="En traitement" value={counters.processing} tone="var(--cyan2)" />
        <Stat label="Terminées" value={counters.completed} tone="var(--green)" />
        <Stat label="Tps moyen" value={avg ? `${avg.toFixed(1)}s` : '—'} sub="exécution op. terminées" />
      </div>
      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>⚙️ File d'exécution (opérations non terminales)</div>
        <Table
          head={['ID', 'Heure', 'Client', 'Type', 'Libellé', 'Montant', 'Provider', 'Statut']}
          rows={items.map((o) => [
            <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>#{o.id}</span>,
            <span style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{fmtDate(o.created_at)}</span>,
            <span style={{ color: 'var(--text-bright)' }}>{o.user_name ?? '—'}</span>,
            <span style={{ textTransform: 'capitalize' }}>{o.type}</span>,
            <span>{o.label}</span>,
            <span style={{ fontFamily: 'var(--font-mono)' }}>{fmtMoney(Number(o.amount), o.currency)}</span>,
            <span style={{ textTransform: 'capitalize' }}>{o.provider ?? '—'}</span>,
            <Badge status={o.status} />,
          ])}
          empty="Aucune opération en file pour le moment."
        />
      </div>
    </div>
  );
}
