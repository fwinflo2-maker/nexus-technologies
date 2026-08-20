import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  apiControlProviders,
  apiControlPublicKeys,
  apiProviderCredentialsUpsert,
  apiProviderTest,
  type CredentialFieldSchema,
  type ProviderCard,
  type PublicKeyRow,
} from '../../api/client';

type Tab = 'providers' | 'keys';
type Env = 'sandbox' | 'production';

const CATEGORY_LABELS: Record<string, string> = {
  card_issuing: 'Émission cartes',
  cards: 'Cartes / paiements',
  mobile_money: 'Mobile money',
  banking: 'Banking',
  fx: 'FX',
  crypto: 'Crypto',
  payout_network: 'Payout',
  kyc: 'KYC',
};

function envConfigured(p: ProviderCard, env: Env): boolean {
  return Boolean(p.environments?.[env]?.configured);
}

function isImplemented(p: ProviderCard): boolean {
  return p.integration === 'IMPLEMENTED' || p.operations_enabled || Boolean(p.operations?.testConnection);
}

function statusPill(p: ProviderCard): { cls: string; label: string } {
  if (envConfigured(p, 'sandbox') || envConfigured(p, 'production')) {
    const sb = p.environments?.sandbox?.status;
    const pr = p.environments?.production?.status;
    if (sb === 'error' || pr === 'error') return { cls: 'p-r', label: 'Erreur test' };
    if (envConfigured(p, 'production')) return { cls: 'p-gr', label: 'Production' };
    return { cls: 'p-g', label: 'Sandbox' };
  }
  if (isImplemented(p)) return { cls: 'p-v', label: 'À configurer' };
  return { cls: 'p-c', label: 'Catalogue' };
}

/**
 * Super Admin — providers & credentials.
 * Configure / teste les clés plateforme (jamais affichées en clair).
 */
export default function ProviderKeys() {
  const [tab, setTab] = useState<Tab>('providers');
  const [providers, setProviders] = useState<ProviderCard[]>([]);
  const [keys, setKeys] = useState<PublicKeyRow[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [q, setQ] = useState('');
  const [category, setCategory] = useState<string>('all');
  const [onlyImplemented, setOnlyImplemented] = useState(false);
  const [onlyConfigured, setOnlyConfigured] = useState(false);

  const [modal, setModal] = useState<ProviderCard | null>(null);
  const [env, setEnv] = useState<Env>('sandbox');
  const [values, setValues] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [testBusy, setTestBusy] = useState<string | null>(null);
  const [flash, setFlash] = useState<{ type: 'ok' | 'err'; text: string } | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    setFlash(null);
    const [provRes, keysRes] = await Promise.all([apiControlProviders(), apiControlPublicKeys()]);
    if (!provRes.success || !provRes.data) {
      setState('error');
      return;
    }
    setProviders(provRes.data.items);
    if (keysRes.success && keysRes.data) setKeys(keysRes.data.items);
    setState('ready');
  }, []);

  useEffect(() => { void load(); }, [load]);

  const categories = useMemo(() => {
    const set = new Set(providers.map((p) => p.category));
    return ['all', ...Array.from(set).sort()];
  }, [providers]);

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    let list = [...providers];
    if (category !== 'all') list = list.filter((p) => p.category === category);
    if (onlyImplemented) list = list.filter(isImplemented);
    if (onlyConfigured) {
      list = list.filter((p) => envConfigured(p, 'sandbox') || envConfigured(p, 'production'));
    }
    if (needle) {
      list = list.filter((p) =>
        p.slug.includes(needle)
        || p.name.toLowerCase().includes(needle)
        || p.category.toLowerCase().includes(needle),
      );
    }
    list.sort((a, b) => {
      const ai = isImplemented(a) ? 0 : 1;
      const bi = isImplemented(b) ? 0 : 1;
      if (ai !== bi) return ai - bi;
      const ac = (envConfigured(a, 'sandbox') || envConfigured(a, 'production')) ? 0 : 1;
      const bc = (envConfigured(b, 'sandbox') || envConfigured(b, 'production')) ? 0 : 1;
      if (ac !== bc) return ac - bc;
      // Priorité cartes virtuelles
      if (a.slug === 'stripe_issuing') return -1;
      if (b.slug === 'stripe_issuing') return 1;
      return a.name.localeCompare(b.name);
    });
    return list;
  }, [providers, q, category, onlyImplemented, onlyConfigured]);

  const kpis = useMemo(() => {
    const impl = providers.filter(isImplemented).length;
    const configured = providers.filter((p) => envConfigured(p, 'sandbox') || envConfigured(p, 'production')).length;
    const issuing = providers.find((p) => p.slug === 'stripe_issuing');
    return { total: providers.length, impl, configured, issuing };
  }, [providers]);

  const schemaFields = (p: ProviderCard): CredentialFieldSchema[] =>
    p.credential_schema?.credentials ?? [];

  const openConfig = (p: ProviderCard) => {
    const fields = schemaFields(p);
    const init: Record<string, string> = {};
    fields.forEach((f) => { init[f.key] = ''; });
    if (fields.length === 0) {
      init.secret_key = '';
    }
    setValues(init);
    setEnv(envConfigured(p, 'sandbox') || !envConfigured(p, 'production') ? 'sandbox' : 'production');
    setModal(p);
    setFlash(null);
  };

  const save = async () => {
    if (!modal) return;
    setBusy(true);
    setFlash(null);
    const filteredCreds: Record<string, string> = {};
    Object.entries(values).forEach(([k, v]) => {
      if (v.trim() !== '') filteredCreds[k] = v.trim();
    });
    const res = await apiProviderCredentialsUpsert(modal.slug, env, filteredCreds);
    setBusy(false);
    if (!res.success) {
      setFlash({ type: 'err', text: res.error || 'Enregistrement impossible.' });
      return;
    }
    setFlash({ type: 'ok', text: `Credentials ${modal.slug} (${env}) enregistrées (chiffrées).` });
    setModal(null);
    await load();
  };

  const test = async (p: ProviderCard, testEnv: Env) => {
    setTestBusy(`${p.slug}:${testEnv}`);
    setFlash(null);
    const res = await apiProviderTest(p.slug, testEnv);
    setTestBusy(null);
    if (!res.success || !res.data) {
      setFlash({ type: 'err', text: res.error || 'Test impossible.' });
      return;
    }
    const st = res.data.result?.status ?? 'UNKNOWN';
    const msg = res.data.result?.message ?? '';
    setFlash({
      type: st === 'CONNECTION_SUCCESS' ? 'ok' : 'err',
      text: `${p.name} · ${testEnv} → ${st}${msg ? ` — ${msg}` : ''}`,
    });
    await load();
  };

  const frontendKeys = keys.filter((k) => k.exposure === 'frontend');
  const backendKeys = keys.filter((k) => k.exposure === 'backend');

  return (
    <div className="page" style={{ paddingTop: 0 }}>
      {flash && (
        <div
          className="card"
          style={{
            padding: 12,
            marginBottom: 14,
            fontSize: 12.5,
            borderColor: flash.type === 'ok' ? 'var(--green)' : 'var(--danger, #e5484d)',
            color: flash.type === 'ok' ? 'var(--green)' : 'var(--danger, #e5484d)',
          }}
          role="status"
        >
          {flash.text}
        </div>
      )}

      <div className="g3" style={{ marginBottom: 16 }}>
        <div className="card" style={{ padding: 14 }}>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Implémentés</div>
          <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--cyan)' }}>{kpis.impl}<span style={{ fontSize: 12, fontWeight: 500, color: 'var(--text-dim)' }}> / {kpis.total}</span></div>
        </div>
        <div className="card" style={{ padding: 14 }}>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Credentials OK</div>
          <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--green)' }}>{kpis.configured}</div>
        </div>
        <div className="card" style={{ padding: 14 }}>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Stripe Issuing</div>
          <div style={{ fontSize: 14, fontWeight: 700, marginTop: 6 }}>
            {kpis.issuing ? (
              <span className={`pill ${statusPill(kpis.issuing).cls}`} style={{ fontSize: 10 }}>
                {statusPill(kpis.issuing).label}
                {isImplemented(kpis.issuing) ? ' · IMPLEMENTED' : ''}
              </span>
            ) : '—'}
          </div>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 10, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' }}>
        <div className="account-tabs">
          {([['providers', '🔌 Providers'], ['keys', '🗝️ Registre des clés']] as const).map(([id, label]) => (
            <button
              key={id}
              type="button"
              className={`account-tab ${tab === id ? 'active-personal' : ''}`}
              onClick={() => setTab(id)}
              style={{ fontSize: 11, fontWeight: 600 }}
            >
              {label}
            </button>
          ))}
        </div>
        <button type="button" className="btn btn-ghost" style={{ fontSize: 11 }} onClick={() => void load()}>↻ Actualiser</button>
      </div>

      {state === 'loading' && <div className="card" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 30, textAlign: 'center' }}>Erreur de chargement du Control Center.</div>}

      {state === 'ready' && tab === 'providers' && (
        <>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14, alignItems: 'center' }}>
            <input
              className="input-field"
              style={{ maxWidth: 260, fontSize: 12.5 }}
              placeholder="Rechercher slug, nom…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
            <select
              className="input-field"
              style={{ maxWidth: 180, fontSize: 12.5 }}
              value={category}
              onChange={(e) => setCategory(e.target.value)}
            >
              {categories.map((c) => (
                <option key={c} value={c}>{c === 'all' ? 'Toutes catégories' : (CATEGORY_LABELS[c] ?? c)}</option>
              ))}
            </select>
            <label style={{ fontSize: 12, color: 'var(--text-mid)', display: 'flex', gap: 6, alignItems: 'center' }}>
              <input type="checkbox" checked={onlyImplemented} onChange={(e) => setOnlyImplemented(e.target.checked)} />
              Implémentés seulement
            </label>
            <label style={{ fontSize: 12, color: 'var(--text-mid)', display: 'flex', gap: 6, alignItems: 'center' }}>
              <input type="checkbox" checked={onlyConfigured} onChange={(e) => setOnlyConfigured(e.target.checked)} />
              Configurés seulement
            </label>
          </div>

          {filtered.length === 0 ? (
            <div className="card" style={{ padding: 20, color: 'var(--text-dim)', fontSize: 12.5 }}>
              Aucun provider ne correspond aux filtres. Désactivez « Implémentés seulement » pour voir le catalogue.
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {filtered.map((p) => {
                const pill = statusPill(p);
                const impl = isImplemented(p);
                const highlight = p.slug === 'stripe_issuing' || p.category === 'card_issuing';
                return (
                  <div
                    key={p.slug}
                    className="card"
                    style={{
                      padding: 14,
                      borderColor: highlight ? 'rgba(0,200,255,0.35)' : undefined,
                      background: highlight ? 'rgba(0,200,255,0.04)' : undefined,
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                          <span style={{ fontSize: 18 }}>{p.icon ?? '🔌'}</span>
                          <span style={{ fontWeight: 700, color: 'var(--text-bright)', fontSize: 14 }}>{p.name}</span>
                          <span className={`pill ${pill.cls}`} style={{ fontSize: 9 }}>{pill.label}</span>
                          {impl && <span className="pill p-c" style={{ fontSize: 9 }}>IMPLEMENTED</span>}
                          {!impl && <span className="pill" style={{ fontSize: 9, opacity: 0.7 }}>NOT_IMPLEMENTED</span>}
                        </div>
                        <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', marginTop: 4 }}>
                          {p.slug} · {CATEGORY_LABELS[p.category] ?? p.category}
                          {p.doc_url ? (
                            <> · <a href={p.doc_url} target="_blank" rel="noreferrer" style={{ color: 'var(--cyan)' }}>docs</a></>
                          ) : null}
                        </div>
                        {p.slug === 'stripe_issuing' && (
                          <div style={{ fontSize: 11.5, color: 'var(--text-mid)', marginTop: 8, lineHeight: 1.45 }}>
                            Cartes virtuelles Nexus. Clé <code style={{ fontSize: 10 }}>sk_test_…</code> avec Issuing activé
                            (repli possible sur les credentials <code style={{ fontSize: 10 }}>stripe</code>).
                          </div>
                        )}
                        <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
                          {(['sandbox', 'production'] as const).map((e) => {
                            const row = p.environments?.[e];
                            return (
                              <span key={e} className={`pill ${row?.configured ? 'p-gr' : 'p-r'}`} style={{ fontSize: 9 }}>
                                {e} · {row?.configured ? (row.status || 'OK') : '—'}
                              </span>
                            );
                          })}
                        </div>
                      </div>
                      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button type="button" className="btn btn-cyan" style={{ fontSize: 11 }} onClick={() => openConfig(p)}>
                          Configurer
                        </button>
                        <button
                          type="button"
                          className="btn btn-ghost"
                          style={{ fontSize: 11 }}
                          disabled={testBusy === `${p.slug}:sandbox`}
                          onClick={() => void test(p, 'sandbox')}
                        >
                          {testBusy === `${p.slug}:sandbox` ? 'Test…' : 'Tester sandbox'}
                        </button>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}

      {state === 'ready' && tab === 'keys' && (
        <>
          <div className="page-label" style={{ marginBottom: 10 }}>Clés publiques exposables (frontend)</div>
          {frontendKeys.length === 0 ? (
            <div className="card" style={{ padding: 20, color: 'var(--text-dim)', fontSize: 12.5, marginBottom: 18 }}>Aucune clé publique exposable.</div>
          ) : (
            <div className="card" style={{ padding: 12, marginBottom: 18 }}>
              {frontendKeys.map((k, i) => (
                <div key={`${k.provider}-${k.key}-${i}`} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '10px 12px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                  <div>
                    <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{k.provider_name} · {k.label}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{k.key} · {k.usage}</div>
                  </div>
                  <span className={`pill ${k.configured ? 'p-gr' : 'p-r'}`} style={{ fontSize: 9 }}>{k.configured ? 'Configurée' : 'Non configurée'}</span>
                </div>
              ))}
            </div>
          )}

          <div className="page-label" style={{ marginBottom: 10 }}>Clés backend (jamais exposées)</div>
          <div className="card" style={{ padding: 12 }}>
            {backendKeys.map((k, i) => (
              <div key={`${k.provider}-${k.key}-${k.environment}-${i}`} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '10px 12px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                <div>
                  <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{k.provider_name} · {k.label}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{k.key} · {k.environment}</div>
                </div>
                <span className={`pill ${k.configured ? 'p-gr' : 'p-r'}`} style={{ fontSize: 9 }}>{k.configured ? 'Configuré' : 'Non configuré'}</span>
              </div>
            ))}
          </div>
        </>
      )}

      {modal && (
        <div
          role="dialog"
          aria-modal="true"
          style={{
            position: 'fixed', inset: 0, zIndex: 80,
            background: 'rgba(0,0,0,0.55)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            padding: 16,
          }}
          onClick={() => !busy && setModal(null)}
        >
          <div
            className="card"
            style={{ width: '100%', maxWidth: 440, padding: 20 }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ fontWeight: 800, fontSize: 15, marginBottom: 4 }}>{modal.name}</div>
            <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', marginBottom: 14 }}>{modal.slug}</div>
            <div style={{ fontSize: 12, color: 'var(--text-mid)', marginBottom: 12, lineHeight: 1.45 }}>
              Les secrets sont chiffrés AES-256-GCM. Les champs vides ne remplacent pas une valeur déjà stockée.
              {modal.slug === 'stripe_issuing' && ' Issuing doit être activé sur le compte Stripe.'}
            </div>

            <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
              {(['sandbox', 'production'] as const).map((e) => (
                <button
                  key={e}
                  type="button"
                  className={`se-chip ${env === e ? 'se-chip-selected' : ''}`}
                  onClick={() => setEnv(e)}
                >
                  {e}
                </button>
              ))}
            </div>

            {(schemaFields(modal).length > 0
              ? schemaFields(modal)
              : [
                  { key: 'secret_key', label: 'Secret key', required: true, sensitivity: 'secret', frontend_exposable: false, usage: 'api_auth', type: 'password', placeholder: 'sk_test_…' },
                ] as CredentialFieldSchema[]
            ).map((f) => (
              <div key={f.key} className="form-group" style={{ marginBottom: 12 }}>
                <label className="form-label">
                  {f.label}{f.required ? ' *' : ''}
                </label>
                <input
                  className="input-field"
                  type={f.type === 'password' || f.usage === 'api_auth' || f.usage === 'webhook' ? 'password' : 'text'}
                  autoComplete="off"
                  placeholder={f.placeholder || (envConfigured(modal, env) ? '•••• (inchangé si vide)' : '')}
                  value={values[f.key] ?? ''}
                  onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                />
              </div>
            ))}

            <div style={{ display: 'flex', gap: 8, marginTop: 8, justifyContent: 'flex-end' }}>
              <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setModal(null)}>Annuler</button>
              <button type="button" className="btn btn-cyan" disabled={busy} onClick={() => void save()}>
                {busy ? 'Enregistrement…' : 'Enregistrer'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
