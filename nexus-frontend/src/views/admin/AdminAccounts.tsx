import { useCallback, useEffect, useState } from 'react';
import {
  apiControlClients, apiControlClient, apiControlClientStatus,
  type ControlClient, type ControlClientDetail,
} from '../../api/client';
import AdminClientDossier, { type DossierSection } from './AdminClientDossier';

const COUNTRY_NAME: Record<string, string> = {
  FR: 'France', CG: 'Congo', CD: 'RDC', CM: 'Cameroun', GA: 'Gabon',
  SN: 'Sénégal', CI: "Côte d'Ivoire", TG: 'Togo', BJ: 'Bénin', BF: 'Burkina Faso',
  ML: 'Mali', NE: 'Niger', TD: 'Tchad', MA: 'Maroc', DZ: 'Algérie', TN: 'Tunisie',
  US: 'États-Unis', GB: 'Royaume-Uni', DE: 'Allemagne', BE: 'Belgique', CH: 'Suisse',
  ES: 'Espagne', IT: 'Italie', PT: 'Portugal', NL: 'Pays-Bas', CA: 'Canada',
  NG: 'Nigéria', KE: 'Kenya', GH: 'Ghana', ZA: 'Afrique du Sud',
};
function countryLabel(code: string | null): string {
  return (code && COUNTRY_NAME[code]) || code || '—';
}
function money(v: string | number, cur: string): string {
  const n = Number(v);
  if (!isFinite(n) || n === 0) return `0 ${cur}`;
  return `${n.toLocaleString('fr-FR')} ${cur}`;
}
function statusColor(s: string): string {
  if (s === 'ACTIVE') return 'var(--green)';
  if (s === 'PENDING') return 'var(--gold)';
  if (s === 'SUSPENDED' || s === 'CLOSED') return 'var(--red)';
  return 'var(--text-mid)';
}

/**
 * Vue Comptes — Super Admin.
 * Clients classés par secteur (Personnel / Business), recherche + filtres,
 * et popup de détail au clic avec toutes les informations personnelles.
 */
export default function AdminAccounts({
  onOpenSection,
}: {
  onOpenSection?: (section: DossierSection) => void;
}) {
  const [rows, setRows] = useState<ControlClient[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [query, setQuery] = useState('');
  const [sector, setSector] = useState<'all' | 'personal' | 'business'>('all');
  const [status, setStatus] = useState<'all' | 'ACTIVE' | 'PENDING' | 'SUSPENDED' | 'CLOSED'>('all');
  const [detail, setDetail] = useState<ControlClientDetail | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const [actionError, setActionError] = useState('');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlClients();
    if (res.success && res.data) { setRows(res.data.items as ControlClient[]); setState('ready'); }
    else setState('error');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const openDetail = async (id: number) => {
    setDetailOpen(true);
    setDetail(null);
    setActionError('');
    setDetailLoading(true);
    const res = await apiControlClient(id);
    if (res.success && res.data) setDetail(res.data.client);
    setDetailLoading(false);
  };

  const closeDetail = () => {
    setDetailOpen(false);
    setDetail(null);
    setActionError('');
  };

  const setClientStatus = async (client: ControlClientDetail, next: 'ACTIVE' | 'SUSPENDED' | 'CLOSED', reason: string) => {
    if ((next === 'SUSPENDED' || next === 'CLOSED') && reason.trim() === '') return;
    setActionError('');
    const response = await apiControlClientStatus(client.id, next, reason);
    if (!response.success) {
      setActionError(response.error ?? 'Action impossible.');
      return;
    }
    await load();
    const refreshed = await apiControlClient(client.id);
    if (refreshed.success && refreshed.data) setDetail(refreshed.data.client);
  };

  const q = query.trim().toLowerCase();
  const filtered = rows.filter((c) => {
    const matchQ = !q || c.full_name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q) || (c.phone ?? '').toLowerCase().includes(q);
    const matchS = sector === 'all' || c.account_type === sector;
    const matchSt = status === 'all' || c.status === status;
    return matchQ && matchS && matchSt;
  });
  const personal = filtered.filter((c) => c.account_type !== 'business');
  const business = filtered.filter((c) => c.account_type === 'business');

  return (
    <div className="page">
      {/* Recherche + filtres */}
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 18 }}>
        <input type="text" value={query} onChange={(e) => setQuery(e.target.value)}
          placeholder="Rechercher (nom, email, téléphone)…"
          style={{ flex: '1 1 260px', minWidth: 220, padding: '9px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 13 }} />
        <select value={sector} onChange={(e) => setSector(e.target.value as never)}
          style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 13 }}>
          <option value="all">Tous les secteurs</option>
          <option value="personal">Personnel</option>
          <option value="business">Business</option>
        </select>
        <select value={status} onChange={(e) => setStatus(e.target.value as never)}
          style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 13 }}>
          <option value="all">Tous les statuts</option>
          <option value="ACTIVE">Actifs</option>
          <option value="PENDING">En attente</option>
          <option value="SUSPENDED">Suspendus</option>
          <option value="CLOSED">Clôturés</option>
        </select>
        <button className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => void load()}>↻ Actualiser</button>
      </div>

      {state === 'loading' && <div className="card" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 30, textAlign: 'center' }}>Erreur de chargement.</div>}

      {state === 'ready' && (
        <>
          {/* ── Secteur Business ── */}
          <SectionTitle icon="🏢" label={`Business (${business.length})`} />
          {business.length === 0 ? <EmptyText /> : (
            <div className="g3" style={{ marginBottom: 24 }}>
              {business.map((c) => <ClientCard key={c.id} c={c} onClick={() => void openDetail(c.id)} />)}
            </div>
          )}

          {/* ── Secteur Personnel ── */}
          <SectionTitle icon="👤" label={`Personnel (${personal.length})`} />
          {personal.length === 0 ? <EmptyText /> : (
            <div className="g3">
              {personal.map((c) => <ClientCard key={c.id} c={c} onClick={() => void openDetail(c.id)} />)}
            </div>
          )}
        </>
      )}

      {detailOpen && (
        <AdminClientDossier
          client={detail}
          loading={detailLoading}
          actionError={actionError}
          onStatus={(next, reason) => {
            if (detail) void setClientStatus(detail, next, reason);
          }}
          onOpenSection={onOpenSection}
          onClose={closeDetail}
        />
      )}
    </div>
  );
}

function SectionTitle({ icon, label }: { icon: string; label: string }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, marginTop: 8 }}>
      <span style={{ fontSize: 16 }}>{icon}</span>
      <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>{label}</span>
    </div>
  );
}
function EmptyText() {
  return <div style={{ padding: 16, color: 'var(--text-dim)', fontSize: 12.5 }}>Aucun compte dans ce secteur.</div>;
}

function ClientCard({ c, onClick }: { c: ControlClient; onClick: () => void }) {
  return (
    <button onClick={onClick} style={{
      textAlign: 'left', cursor: 'pointer', background: 'var(--panel)', border: '1px solid var(--border)',
      borderRadius: 12, padding: 14, display: 'flex', flexDirection: 'column', gap: 8,
      transition: 'border-color .2s, transform .2s',
    }}
      onMouseEnter={(e) => { e.currentTarget.style.borderColor = 'rgba(59,130,246,.4)'; e.currentTarget.style.transform = 'translateY(-2px)'; }}
      onMouseLeave={(e) => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.transform = 'translateY(0)'; }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        {c.avatar ? <img src={c.avatar} alt="" style={{ width: 36, height: 36, borderRadius: '50%', objectFit: 'cover' }} />
          : <span style={{ fontSize: 22 }}>{c.account_type === 'business' ? '🏢' : '👤'}</span>}
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontWeight: 700, color: 'var(--text-bright)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.full_name}</div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.email}</div>
        </div>
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: 'var(--text-mid)' }}>
        <span>{countryLabel(c.country_of_residence)}</span>
        <span style={{ color: statusColor(c.status) }}>{c.status} · KYC {c.kyc_level}</span>
      </div>
      <div style={{ borderTop: '1px solid var(--border-soft)', paddingTop: 8, fontSize: 11, color: 'var(--text-dim)', display: 'flex', justifyContent: 'space-between' }}>
        <span>{money(c.balances.EUR, 'EUR')}</span>
        <span style={{ color: 'var(--cyan)' }}>{c.transactions} tx · Voir détail →</span>
      </div>
    </button>
  );
}

