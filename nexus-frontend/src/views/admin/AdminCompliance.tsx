import { useEffect, useState } from 'react';
import { apiControlKyc } from '../../api/client';
import { Badge, Stat, Table, fmtDate } from './adminUi';

interface Applicant {
  id: number; user_id: number; full_name: string; email: string; provider: string;
  environment: string; subject_type: string; applicant_id: string; level_name: string;
  status: string; reason: string | null; reviewed_at: string | null; created_at: string;
}

export default function AdminCompliance() {
  const [counters, setCounters] = useState<Record<string, unknown>>({});
  const [applicants, setApplicants] = useState<Applicant[]>([]);

  useEffect(() => {
    void apiControlKyc().then((res) => {
      if (res.success && res.data) { setCounters(res.data.counters as unknown as Record<string, unknown>); setApplicants(res.data.applicants as unknown as Applicant[]); }
    });
  }, []);

  const c = counters as unknown as { total?: number; pending?: number; verified?: number };
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Dossiers KYC" value={c.total ?? applicants.length} />
        <Stat label="En attente" value={c.pending ?? 0} tone="var(--gold)" />
        <Stat label="Vérifiés" value={c.verified ?? 0} tone="var(--green)" />
        <Stat label="Provider" value="SumSub" sub={counters.provider ? String((counters.provider as { slug?: string }).slug ?? '') : ''} />
      </div>

      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🛡️ Dossiers de vérification d'identité</div>
        <Table
          head={['ID', 'Client', 'Type', 'Niveau', 'Statut', 'Motif / remarque', 'Reçu le']}
          rows={applicants.map((a) => [
            <span key="id" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>#{a.id}</span>,
            <span key="client">
              <div style={{ color: 'var(--text-bright)', fontWeight: 600 }}>{a.full_name}</div>
              <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{a.email}</div>
            </span>,
            <span key="type" style={{ textTransform: 'capitalize' }}>{a.subject_type}</span>,
            <span key="level" style={{ textTransform: 'uppercase', fontSize: 11 }}>{a.level_name ?? '—'}</span>,
            <Badge key="status" status={a.status} />,
            <span key="reason" style={{ fontSize: 12 }}>{a.reason ?? '—'}</span>,
            <span key="date" style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{fmtDate(a.created_at)}</span>,
          ])}
        />
      </div>
    </div>
  );
}
