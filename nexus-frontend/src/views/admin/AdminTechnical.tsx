import { useEffect, useState } from 'react';
import { apiAdminTechnical } from '../../api/client';
import { Badge, Stat, Table } from './adminUi';

interface Service { name: string; status: string; latency_ms: number; }
interface Pc { provider_slug: string; environment: string; state: string; last_tested_at: string | null; last_error: string | null; }

export default function AdminTechnical() {
  const [services, setServices] = useState<Service[]>([]);
  const [providers, setProviders] = useState<Pc[]>([]);
  const [db, setDb] = useState(true);
  useEffect(() => {
    void apiAdminTechnical().then((res) => { if (res.success && res.data) { setServices(res.data.services); setProviders(res.data.providers as unknown as Pc[]); setDb(res.data.db_ok); } });
  }, []);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Services" value={services.filter((s) => s.status === 'operational').length} sub={`/${services.length} opérationnels`} tone="var(--green)" />
        <Stat label="Base de données" value={db ? 'OK' : 'DOWN'} tone={db ? 'var(--green)' : 'var(--red)'} />
        <Stat label="Providers configurés" value={providers.filter((p) => p.state === 'configured').length} />
        <Stat label="Environnements" value={providers.length} />
      </div>

      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🛠️ Santé des services</div>
        <Table
          head={['Service', 'État', 'Latence']}
          rows={services.map((s) => [
            <span style={{ color: 'var(--text-bright)' }}>{s.name}</span>,
            <Badge status={s.status} />,
            <span style={{ fontFamily: 'var(--font-mono)' }}>{s.latency_ms} ms</span>,
          ])}
        />
      </div>

      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🔌 État des credentials providers</div>
        <Table
          head={['Provider', 'Environnement', 'État']}
          rows={providers.map((p) => [
            <span style={{ textTransform: 'capitalize' }}>{p.provider_slug}</span>,
            <span style={{ textTransform: 'uppercase', fontSize: 11, color: 'var(--text-mid)' }}>{p.environment}</span>,
            <Badge status={p.state === 'configured' ? 'active' : 'not_configured'} label={p.state === 'configured' ? 'Configuré' : 'Non configuré'} />,
          ])}
        />
      </div>
    </div>
  );
}
