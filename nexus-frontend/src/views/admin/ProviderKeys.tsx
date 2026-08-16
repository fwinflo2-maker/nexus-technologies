import { useCallback, useEffect, useState } from 'react';
import { apiControlPublicKeys, apiControlCredentials, type PublicKeyRow } from '../../api/client';

/**
 * Vue "Clés API & publiques" des providers — Super Admin.
 *
 * Affiche le registre des clés publiques exposables (frontend) vs backend,
 * et l'état des credentials par provider/environnement. Ne renvoie jamais
 * la VALEUR d'un secret (uniquement "configuré" ou non).
 */
export default function ProviderKeys() {
  const [keys, setKeys] = useState<PublicKeyRow[]>([]);
  const [creds, setCreds] = useState<Array<{ slug: string; name: string; environments: Record<string, { configured: boolean; status: string }> }>>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [tab, setTab] = useState<'keys' | 'credentials'>('keys');

  const load = useCallback(async () => {
    setState('loading');
    const [keysRes, credsRes] = await Promise.all([apiControlPublicKeys(), apiControlCredentials()]);
    if (keysRes.success && keysRes.data) setKeys(keysRes.data.items as PublicKeyRow[]);
    if (credsRes.success && credsRes.data) setCreds(credsRes.data.items as typeof creds);
    setState('ready');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const frontendKeys = keys.filter((k) => k.exposure === 'frontend');
  const backendKeys = keys.filter((k) => k.exposure === 'backend');

  return (
    <div className="page">
      <div style={{ display: 'flex', gap: 10, marginBottom: 18, flexWrap: 'wrap' }}>
        <div className="account-tabs">
          {([['keys', '🔑 Clés API / publiques'], ['credentials', '🔐 Credentials providers']] as const).map(([id, label]) => (
            <button key={id} className={`account-tab ${tab === id ? 'active-personal' : ''}`} onClick={() => setTab(id)} style={{ fontSize: 11, fontWeight: 600 }}>
              {label}
            </button>
          ))}
        </div>
        <button className="btn btn-ghost" style={{ fontSize: 11 }} onClick={() => void load()}>↻ Actualiser</button>
      </div>

      {state === 'loading' && <div className="card" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 30, textAlign: 'center' }}>Erreur de chargement.</div>}

      {state === 'ready' && tab === 'keys' && (
        <>
          {/* Clés exposables (frontend) */}
          <div className="page-label" style={{ marginBottom: 10 }}>Clés publiques exposables (frontend)</div>
          {frontendKeys.length === 0 ? (
            <div className="card" style={{ padding: 20, color: 'var(--text-dim)', fontSize: 12.5 }}>Aucune clé publique exposable.</div>
          ) : (
            <div className="card" style={{ padding: 12, marginBottom: 20 }}>
              {frontendKeys.map((k, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, padding: '10px 12px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{k.provider_name} · {k.label}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{k.key} · {k.usage}</div>
                  </div>
                  <span className={`pill ${k.configured ? 'p-gr' : 'p-r'}`} style={{ fontSize: 9, flexShrink: 0 }}>{k.configured ? 'Configurée' : 'Non configurée'}</span>
                </div>
              ))}
            </div>
          )}

          {/* Clés backend */}
          <div className="page-label" style={{ marginBottom: 10 }}>Clés backend (jamais exposées au navigateur)</div>
          {backendKeys.length === 0 ? (
            <div className="card" style={{ padding: 20, color: 'var(--text-dim)', fontSize: 12.5 }}>Aucune clé backend.</div>
          ) : (
            <div className="card" style={{ padding: 12 }}>
              {backendKeys.slice(0, 20).map((k, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, padding: '10px 12px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{k.provider_name} · {k.label}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{k.key} · {k.environment}</div>
                  </div>
                  <span className={`pill ${k.configured ? 'p-gr' : 'p-r'}`} style={{ fontSize: 9, flexShrink: 0 }}>{k.configured ? 'Configuré' : 'Non configuré'}</span>
                </div>
              ))}
              {backendKeys.length > 20 && <div style={{ padding: 10, fontSize: 11, color: 'var(--text-dim)' }}>… et {backendKeys.length - 20} autres.</div>}
            </div>
          )}
        </>
      )}

      {state === 'ready' && tab === 'credentials' && (
        <>
          <div className="page-label" style={{ marginBottom: 10 }}>Credentials par provider & environnement</div>
          {creds.length === 0 ? (
            <div className="card" style={{ padding: 20, color: 'var(--text-dim)', fontSize: 12.5 }}>Aucun provider configuré.</div>
          ) : (
            <div className="card" style={{ padding: 12 }}>
              {creds.map((c) => (
                <div key={c.slug} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, padding: '10px 12px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                  <div>
                    <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{c.name}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{c.slug}</div>
                  </div>
                  <div style={{ display: 'flex', gap: 8 }}>
                    {(['sandbox', 'production'] as const).map((env) => {
                      const e = c.environments[env];
                      return (
                        <span key={env} className={`pill ${e?.configured ? 'p-gr' : 'p-r'}`} style={{ fontSize: 9 }}>
                          {env} · {e?.configured ? 'OK' : '—'}
                        </span>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}
