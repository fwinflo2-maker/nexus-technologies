/**
 * Registre des clients & entreprises — réservé au superadmin.
 *
 * Regroupe les clients par type (Entreprises / Personnes). Le clic sur un
 * client ouvre une carte détaillée avec toutes ses informations : profil,
 * pays, téléphone, adresse, comptes de paiement, soldes et historique.
 *
 * Données réelles (GET /api/control/clients et /clients/{id}).
 */
import { useCallback, useEffect, useState } from 'react';
import { apiControlClients, apiControlClient, type ControlClient, type ControlClientDetail } from '../../api/client';
import { useCtrlT } from '../../data/control-i18n';
import { C, Panel, State } from './ui';

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

function statusColor(status: string): string {
  if (status === 'ACTIVE') return C.ok;
  if (status === 'PENDING') return C.warn;
  if (status === 'SUSPENDED' || status === 'CLOSED') return C.danger;
  return C.dim;
}

export default function ControlClientsPage() {
  const t = useCtrlT();
  const [rows, setRows] = useState<ControlClient[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [query, setQuery] = useState('');
  const [detail, setDetail] = useState<ControlClientDetail | null>(null);
  const [detailState, setDetailState] = useState<'idle' | 'loading' | 'error'>('idle');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlClients();
    if (res.success && res.data) {
      setRows(res.data.items as ControlClient[]);
      setState('ready');
    } else {
      setState('error');
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const openDetail = async (id: number) => {
    setDetail(null);
    setDetailState('loading');
    const res = await apiControlClient(id);
    if (res.success && res.data) {
      setDetail(res.data.client);
      setDetailState('idle');
    } else {
      setDetailState('error');
    }
  };

  const q = query.trim().toLowerCase();
  const filtered = rows.filter((c) => {
    const matchQ = !q
      || c.full_name.toLowerCase().includes(q)
      || c.email.toLowerCase().includes(q)
      || (c.phone ?? '').toLowerCase().includes(q);
    return matchQ;
  });
  const businesses = filtered.filter((c) => c.account_type === 'business');
  const persons = filtered.filter((c) => c.account_type !== 'business');

  return (
    <Panel
      title={t('ctrl.cl.title')}
      subtitle={t('ctrl.cl.subtitle')}
      right={
        <button onClick={() => void load()} style={{
          padding: '6px 14px', borderRadius: 8, border: `1px solid ${C.borderStrong}`,
          background: 'transparent', color: C.dim, fontSize: 12, cursor: 'pointer',
        }}>{t('ctrl.refresh')}</button>
      }
    >
      {/* Recherche */}
      <input
        type="text"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder="Rechercher (nom, e-mail, téléphone)…"
        style={{
          width: '100%', boxSizing: 'border-box', padding: '10px 14px', borderRadius: 10, marginBottom: 18,
          border: `1px solid ${C.border}`, background: 'rgba(255,255,255,0.03)',
          color: C.text, fontSize: 13,
        }}
      />

      {state === 'loading' && <State kind="loading" label={t('ctrl.loading')} />}
      {state === 'error' && <State kind="error" label={t('ctrl.error')} onRetry={() => void load()} retryLabel={t('ctrl.retry')} />}
      {state === 'ready' && filtered.length === 0 && <State kind="empty" label={t('ctrl.empty')} />}

      {state === 'ready' && filtered.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
          {/* ── Entreprises ── */}
          {businesses.length > 0 && (
            <div>
              <GroupTitle icon="🏢" label={`Entreprises (${businesses.length})`} />
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))', gap: 12 }}>
                {businesses.map((c) => <ClientCard key={c.id} c={c} onClick={() => void openDetail(c.id)} />)}
              </div>
            </div>
          )}

          {/* ── Personnes ── */}
          {persons.length > 0 && (
            <div>
              <GroupTitle icon="👤" label={`Personnes (${persons.length})`} />
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))', gap: 12 }}>
                {persons.map((c) => <ClientCard key={c.id} c={c} onClick={() => void openDetail(c.id)} />)}
              </div>
            </div>
          )}
        </div>
      )}

      {/* ── Carte détaillée (drawer) ── */}
      {detailState !== 'idle' && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 200, display: 'flex', justifyContent: 'flex-end' }}>
          <div style={{
            width: 'min(520px, 94vw)', height: '100vh', background: C.panel,
            borderLeft: `1px solid ${C.borderStrong}`, padding: 24, overflowY: 'auto', position: 'relative',
          }}>
            <button onClick={() => setDetailState('idle')} style={{ position: 'absolute', top: 14, right: 14, border: 'none', background: 'transparent', color: C.dim, fontSize: 18, cursor: 'pointer' }}>✕</button>
            {detailState === 'loading' && <State kind="loading" label={t('ctrl.loading')} />}
            {detailState === 'error' && <State kind="error" label={t('ctrl.error')} />}
          </div>
        </div>
      )}

      {detail && detailState === 'idle' && (
        <ClientDetailCard client={detail} onClose={() => setDetail(null)} />
      )}
    </Panel>
  );
}

function GroupTitle({ icon, label }: { icon: string; label: string }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
      <span style={{ fontSize: 16 }}>{icon}</span>
      <span style={{ fontSize: 13, fontWeight: 700, color: C.text, letterSpacing: 0.3 }}>{label}</span>
    </div>
  );
}

function ClientCard({ c, onClick }: { c: ControlClient; onClick: () => void }) {
  return (
    <button onClick={onClick} style={{
      textAlign: 'left', cursor: 'pointer', background: 'rgba(255,255,255,0.02)',
      border: `1px solid ${C.border}`, borderRadius: 12, padding: 14,
      display: 'flex', flexDirection: 'column', gap: 8, transition: 'border-color .2s, transform .2s',
    }}
      onMouseEnter={(e) => { e.currentTarget.style.borderColor = C.accent; e.currentTarget.style.transform = 'translateY(-2px)'; }}
      onMouseLeave={(e) => { e.currentTarget.style.borderColor = C.border; e.currentTarget.style.transform = 'translateY(0)'; }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        {c.avatar ? (
          <img src={c.avatar} alt="" style={{ width: 38, height: 38, borderRadius: '50%', objectFit: 'cover' }} />
        ) : (
          <span style={{ fontSize: 22 }}>{c.account_type === 'business' ? '🏢' : '👤'}</span>
        )}
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontWeight: 700, color: C.text, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.full_name}</div>
          <div style={{ fontSize: 11, color: C.dim, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.email}</div>
        </div>
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: C.dim }}>
        <span>{countryLabel(c.country_of_residence)}</span>
        <span style={{ color: statusColor(c.status) }}>{c.status}</span>
      </div>
      <div style={{ borderTop: `1px solid ${C.border}`, paddingTop: 8, fontSize: 11, color: C.dim, display: 'flex', justifyContent: 'space-between' }}>
        <span>{money(c.balances.EUR, 'EUR')}</span>
        <span style={{ color: C.accent }}>{c.transactions} tx</span>
      </div>
    </button>
  );
}

function ClientDetailCard({ client, onClose }: { client: ControlClientDetail; onClose: () => void }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 200, display: 'flex', justifyContent: 'flex-end' }}>
      <div style={{
        width: 'min(560px, 96vw)', height: '100vh', background: C.panel,
        borderLeft: `1px solid ${C.borderStrong}`, padding: 24, overflowY: 'auto', position: 'relative',
      }}>
        <button onClick={onClose} style={{ position: 'absolute', top: 14, right: 14, border: 'none', background: 'transparent', color: C.dim, fontSize: 20, cursor: 'pointer' }}>✕</button>

        {/* En-tête */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 20 }}>
          {client.avatar ? (
            <img src={client.avatar} alt="" style={{ width: 56, height: 56, borderRadius: '50%', objectFit: 'cover' }} />
          ) : (
            <span style={{ fontSize: 34 }}>{client.account_type === 'business' ? '🏢' : '👤'}</span>
          )}
          <div>
            <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: C.text }}>{client.full_name}</h2>
            <div style={{ fontSize: 12, color: C.dim }}>{client.email}</div>
            <div style={{ fontSize: 12, color: statusColor(client.status), marginTop: 2 }}>{client.status} · KYC {client.kyc_level}</div>
          </div>
        </div>

        {/* Informations de contact */}
        <Section title="Informations">
          <Row label="Type" value={client.account_type === 'business' ? 'Entreprise' : 'Personnel'} />
          <Row label="Pays de résidence" value={countryLabel(client.country_of_residence)} />
          <Row label="Téléphone" value={client.phone || '—'} />
          <Row label="Adresse" value={client.address || '—'} />
          <Row label="Ville" value={client.city || '—'} />
          <Row label="Rôle plateforme" value={client.platform_role} />
          <Row label="Membre depuis" value={new Date(client.created_at).toLocaleDateString('fr-FR')} />
        </Section>

        {/* Soldes */}
        <Section title="Soldes">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8 }}>
            {(['EUR', 'USD', 'XAF'] as const).map((cur) => (
              <div key={cur} style={{ background: 'rgba(255,255,255,0.02)', border: `1px solid ${C.border}`, borderRadius: 10, padding: 10, textAlign: 'center' }}>
                <div style={{ fontSize: 11, color: C.dim }}>{cur}</div>
                <div style={{ fontSize: 15, fontWeight: 700, color: C.text, fontFamily: 'ui-monospace, monospace' }}>{money(client.balances[cur], cur)}</div>
              </div>
            ))}
          </div>
        </Section>

        {/* Comptes de paiement */}
        <Section title={`Comptes de paiement (${client.accounts.length})`}>
          {client.accounts.length === 0 ? (
            <div style={{ fontSize: 12, color: C.dim }}>Aucun compte.</div>
          ) : client.accounts.map((a) => (
            <div key={a.id} style={{ background: 'rgba(255,255,255,0.02)', border: `1px solid ${C.border}`, borderRadius: 10, padding: 12, marginBottom: 8 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 600, color: C.text, fontSize: 13 }}>
                <span>{a.label}</span>
                <span style={{ color: C.dim, fontSize: 11 }}>{a.kind}</span>
              </div>
              <div style={{ fontSize: 11, color: C.dim, marginTop: 6 }}>
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

        {/* Historique des transactions */}
        <Section title={`Historique (${client.transactions.length})`}>
          {client.transactions.length === 0 ? (
            <div style={{ fontSize: 12, color: C.dim }}>Aucune transaction.</div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              {client.transactions.map((tx, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', background: 'rgba(255,255,255,0.02)', border: `1px solid ${C.border}`, borderRadius: 8, padding: '8px 10px', fontSize: 12 }}>
                  <span style={{ color: C.text }}>{String(tx.label ?? tx.type ?? '')}</span>
                  <span style={{ color: C.dim, display: 'flex', gap: 8 }}>
                    <span>{String(tx.currency ?? '')} {String(tx.amount ?? '')}</span>
                    <span style={{ color: statusColor(String(tx.status ?? '')) }}>{String(tx.status ?? '')}</span>
                  </span>
                </div>
              ))}
            </div>
          )}
        </Section>
      </div>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div style={{ marginTop: 20 }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.8, color: C.dim, marginBottom: 10 }}>{title}</div>
      {children}
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '7px 0', borderBottom: `1px solid ${C.border}`, fontSize: 13 }}>
      <span style={{ color: C.dim }}>{label}</span>
      <span style={{ color: C.text, textAlign: 'right' }}>{value}</span>
    </div>
  );
}
