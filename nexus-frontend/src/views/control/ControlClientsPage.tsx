/**
 * Registre des clients & entreprises — réservé au superadmin.
 *
 * Liste tous les utilisateurs (personnes et entreprises) avec leurs
 * informations : profil, type de compte, pays de résidence, statut, niveau
 * KYC, soldes par devise et compteur d'activité.
 *
 * Données réelles (GET /api/control/clients). Aucun secret renvoyé.
 */
import { useCallback, useEffect, useState } from 'react';
import { apiControlClients, type ControlClient } from '../../api/client';
import { useCtrlT } from '../../data/control-i18n';
import { C, Panel, State, Table, Td } from './ui';

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

function statusTone(status: string): 'ok' | 'warn' | 'danger' | 'unknown' {
  if (status === 'ACTIVE') return 'ok';
  if (status === 'PENDING') return 'warn';
  if (status === 'SUSPENDED' || status === 'CLOSED') return 'danger';
  return 'unknown';
}

export default function ControlClientsPage() {
  const t = useCtrlT();
  const [rows, setRows] = useState<ControlClient[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [query, setQuery] = useState('');
  const [filterType, setFilterType] = useState<'all' | 'personal' | 'business'>('all');

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

  const filtered = rows.filter((c) => {
    const q = query.trim().toLowerCase();
    const matchQ = !q
      || c.full_name.toLowerCase().includes(q)
      || c.email.toLowerCase().includes(q)
      || (c.phone ?? '').toLowerCase().includes(q);
    const matchT = filterType === 'all' || c.account_type === filterType;
    return matchQ && matchT;
  });

  const totalBusiness = rows.filter((c) => c.account_type === 'business').length;

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
      {/* Barre de recherche + filtres */}
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 16 }}>
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Rechercher (nom, e-mail, téléphone)…"
          style={{
            flex: '1 1 260px', minWidth: 220, padding: '9px 12px', borderRadius: 8,
            border: `1px solid ${C.border}`, background: 'rgba(255,255,255,0.03)',
            color: C.text, fontSize: 13,
          }}
        />
        <select value={filterType} onChange={(e) => setFilterType(e.target.value as never)}
          style={{ padding: '9px 12px', borderRadius: 8, border: `1px solid ${C.border}`, background: 'rgba(255,255,255,0.03)', color: C.text, fontSize: 13 }}>
          <option value="all">Tous ({rows.length})</option>
          <option value="personal">Personnes ({rows.length - totalBusiness})</option>
          <option value="business">Entreprises ({totalBusiness})</option>
        </select>
      </div>

      {state === 'loading' && <State kind="loading" label={t('ctrl.loading')} />}
      {state === 'error' && <State kind="error" label={t('ctrl.error')} onRetry={() => void load()} retryLabel={t('ctrl.retry')} />}

      {state === 'ready' && filtered.length === 0 && (
        <State kind="empty" label={t('ctrl.empty')} />
      )}

      {state === 'ready' && filtered.length > 0 && (
        <div style={{ overflowX: 'auto' }}>
          <Table head={[
            t('ctrl.cl.client'), t('ctrl.cl.type'), t('ctrl.cl.country'),
            t('ctrl.cl.status'), t('ctrl.cl.kyc'), t('ctrl.cl.balances'),
            t('ctrl.cl.actions'), t('ctrl.cl.since'),
          ]}>
            {filtered.map((c) => (
              <tr key={c.id}>
                <Td>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    {c.avatar ? (
                      <img src={c.avatar} alt="" style={{ width: 30, height: 30, borderRadius: '50%', objectFit: 'cover' }} />
                    ) : (
                      <span style={{ fontSize: 18 }}>{c.account_type === 'business' ? '🏢' : '👤'}</span>
                    )}
                    <div>
                      <div style={{ fontWeight: 600, color: C.text }}>{c.full_name}</div>
                      <div style={{ fontSize: 11, color: C.dim }}>{c.email}</div>
                      {c.phone && <div style={{ fontSize: 11, color: C.dim }}>{c.phone}</div>}
                    </div>
                  </div>
                </Td>
                <Td>
                  <span style={{
                    fontSize: 11, padding: '3px 8px', borderRadius: 100,
                    border: `1px solid ${c.account_type === 'business' ? 'rgba(234,184,48,0.4)' : 'rgba(0,200,255,0.4)'}`,
                    color: c.account_type === 'business' ? C.warn : C.accent,
                  }}>
                    {c.account_type === 'business' ? 'Entreprise' : 'Personnel'}
                  </span>
                </Td>
                <Td>{countryLabel(c.country_of_residence)}</Td>
                <Td>
                  <span style={{ fontSize: 11, color: statusTone(c.status) === 'ok' ? C.ok : statusTone(c.status) === 'warn' ? C.warn : statusTone(c.status) === 'danger' ? C.danger : C.dim }}>
                    {c.status}
                  </span>
                </Td>
                <Td mono>{c.kyc_level}</Td>
                <Td>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 2, fontSize: 12 }}>
                    <span>{money(c.balances.EUR, 'EUR')}</span>
                    <span style={{ color: C.dim }}>{money(c.balances.USD, 'USD')}</span>
                    <span style={{ color: C.dim }}>{money(c.balances.XAF, 'XAF')}</span>
                  </div>
                </Td>
                <Td mono style={{ color: C.accent }}>{c.transactions}</Td>
                <Td mono>{new Date(c.created_at).toLocaleDateString('fr-FR')}</Td>
              </tr>
            ))}
          </Table>
        </div>
      )}
    </Panel>
  );
}
