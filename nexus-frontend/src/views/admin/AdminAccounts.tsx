import { useCallback, useEffect, useState } from 'react';
import {
  apiControlClients, apiControlClient,
  type ControlClient, type ControlClientDetail,
} from '../../api/client';

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
export default function AdminAccounts() {
  const [rows, setRows] = useState<ControlClient[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [query, setQuery] = useState('');
  const [sector, setSector] = useState<'all' | 'personal' | 'business'>('all');
  const [status, setStatus] = useState<'all' | 'ACTIVE' | 'PENDING' | 'SUSPENDED'>('all');
  const [detail, setDetail] = useState<ControlClientDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlClients();
    if (res.success && res.data) { setRows(res.data.items as ControlClient[]); setState('ready'); }
    else setState('error');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const openDetail = async (id: number) => {
    setDetailLoading(true);
    const res = await apiControlClient(id);
    if (res.success && res.data) setDetail(res.data.client);
    setDetailLoading(false);
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

      {/* ── Popup détail ── */}
      {detail && (
        <DetailPopup client={detail} loading={detailLoading} onClose={() => setDetail(null)} />
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

function DetailPopup({ client, loading, onClose }: { client: ControlClientDetail; loading: boolean; onClose: () => void }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 300, display: 'flex', justifyContent: 'flex-end' }} onClick={onClose}>
      <div onClick={(e) => e.stopPropagation()} style={{
        width: 'min(560px, 96vw)', height: '100vh', background: 'var(--panel)', borderLeft: '1px solid var(--borderStrong, var(--border))',
        padding: 24, overflowY: 'auto', position: 'relative', boxShadow: '-20px 0 60px rgba(0,0,0,0.5)',
      }}>
        <button onClick={onClose} style={{ position: 'absolute', top: 14, right: 14, border: 'none', background: 'transparent', color: 'var(--text-dim)', fontSize: 20, cursor: 'pointer' }}>✕</button>

        {loading ? (
          <div style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>
        ) : (
          <>
            {/* En-tête */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 20 }}>
              {client.avatar ? <img src={client.avatar} alt="" style={{ width: 56, height: 56, borderRadius: '50%', objectFit: 'cover' }} />
                : <span style={{ fontSize: 34 }}>{client.account_type === 'business' ? '🏢' : '👤'}</span>}
              <div>
                <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: 'var(--text-bright)' }}>{client.full_name}</h2>
                <div style={{ fontSize: 12, color: 'var(--text-dim)' }}>{client.email} · {client.phone || '—'}</div>
                <div style={{ fontSize: 12, color: statusColor(client.status), marginTop: 2 }}>{client.status} · KYC {client.kyc_level} · {client.account_type === 'business' ? 'Business' : 'Personnel'}</div>
              </div>
            </div>

            {/* Informations */}
            <Section title="Informations">
              <Row k="Type" v={client.account_type === 'business' ? 'Entreprise' : 'Personnel'} />
              <Row k="Pays de résidence" v={countryLabel(client.country_of_residence)} />
              <Row k="Téléphone" v={client.phone || '—'} />
              <Row k="Adresse" v={client.address || '—'} />
              <Row k="Ville" v={client.city || '—'} />
              {client.postal_code && <Row k="Code postal" v={client.postal_code} />}
              <Row k="Rôle plateforme" v={client.platform_role} />
              <Row k="Membre depuis" v={new Date(client.created_at).toLocaleDateString('fr-FR')} />
            </Section>

            {/* Personnes physiques */}
            {client.account_type !== 'business' && (client.birth_date || client.gender) && (
              <Section title="Personne physique">
                {client.birth_date && <Row k="Date de naissance" v={client.birth_date} />}
                {client.gender && <Row k="Genre" v={client.gender} />}
              </Section>
            )}

            {/* Entreprise */}
            {client.account_type === 'business' && (
              <Section title="Entreprise">
                <Row k="Raison sociale" v={client.company_name || client.full_name} />
                <Row k="Forme juridique" v={client.legal_form || '—'} />
                <Row k="Immatriculation" v={client.company_registration_number || '—'} />
                <Row k="Secteur d'activité" v={client.industry || '—'} />
                <Row k="Taille" v={client.company_size || '—'} />
                <Row k="Site web" v={client.website || '—'} />
              </Section>
            )}

            {/* Soldes */}
            <Section title="Soldes">
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8 }}>
                {(['EUR', 'USD', 'XAF'] as const).map((cur) => (
                  <div key={cur} style={{ background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 10, padding: 10, textAlign: 'center' }}>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{cur}</div>
                    <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{money(client.balances[cur], cur)}</div>
                  </div>
                ))}
              </div>
            </Section>

            {/* Comptes de paiement */}
            <Section title={`Comptes de paiement (${client.accounts.length})`}>
              {client.accounts.length === 0 ? <div style={{ fontSize: 12, color: 'var(--text-dim)' }}>Aucun compte.</div>
                : client.accounts.map((a) => (
                  <div key={a.id} style={{ background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 10, padding: 12, marginBottom: 8 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 600, color: 'var(--text-bright)', fontSize: 13 }}>
                      <span>{a.label}</span><span style={{ color: 'var(--text-dim)', fontSize: 11 }}>{a.kind}</span>
                    </div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 6 }}>
                      {a.holder_name && <div>Titulaire : {a.holder_name}</div>}
                      {a.operator && <div>Opérateur : {a.operator}</div>}
                      {a.network && <div>Réseau : {a.network}</div>}
                      {a.country && <div>Pays : {countryLabel(a.country)}</div>}
                      {a.city && <div>Ville : {a.city}</div>}
                      {a.address && <div>Adresse : {a.address}</div>}
                      {a.phone && <div>Téléphone : {a.phone}</div>}
                    </div>
                  </div>
                ))}
            </Section>

            {/* Historique */}
            <Section title={`Historique (${client.transactions.length})`}>
              {client.transactions.length === 0 ? <div style={{ fontSize: 12, color: 'var(--text-dim)' }}>Aucune transaction.</div>
                : client.transactions.map((tx, i) => (
                  <div key={i} style={{ display: 'flex', justifyContent: 'space-between', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 8, padding: '8px 10px', fontSize: 12, marginBottom: 6 }}>
                    <span style={{ color: 'var(--text-bright)' }}>{String(tx.label ?? tx.type ?? '')}</span>
                    <span style={{ color: 'var(--text-dim)', display: 'flex', gap: 8 }}>
                      <span>{String(tx.currency ?? '')} {String(tx.amount ?? '')}</span>
                      <span style={{ color: statusColor(String(tx.status ?? '')) }}>{String(tx.status ?? '')}</span>
                    </span>
                  </div>
                ))}
            </Section>
          </>
        )}
      </div>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <div style={{ marginTop: 18 }}><div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.8, color: 'var(--text-dim)', marginBottom: 8 }}>{title}</div>{children}</div>;
}
function Row({ k, v }: { k: string; v: string }) {
  return <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '6px 0', borderBottom: '1px solid var(--border-soft)', fontSize: 13 }}><span style={{ color: 'var(--text-dim)' }}>{k}</span><span style={{ color: 'var(--text-bright)', textAlign: 'right' }}>{v}</span></div>;
}
