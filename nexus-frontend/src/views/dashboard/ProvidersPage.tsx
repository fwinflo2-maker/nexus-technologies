import { useState, useEffect, useCallback } from 'react';
import {
  apiProvidersCatalog,
  apiProviderCredentialsList,
  apiProviderCredentialsUpsert,
  apiProviderTest,
  type ProviderCatalogData,
  type ProviderCatalogItem,
  type ProviderCredentialState,
} from '../../api/client';

type Tab = 'all' | 'mobile_money' | 'banking' | 'fx' | 'cards' | 'crypto' | 'payout_network';

/**
 * ProvidersPage — Configuration des providers de paiement.
 *
 * Affiche le catalogue des providers et permet de configurer les
 * identifiants API (chiffrés AES-256-GCM côté serveur).
 * Les secrets ne sont JAMAIS affichés en clair dans l'interface.
 */
export default function ProvidersPage() {
  const [tab, setTab] = useState<Tab>('all');
  const [catalog, setCatalog] = useState<ProviderCatalogData | null>(null);
  const [credentials, setCredentials] = useState<ProviderCredentialState[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // État du modal de configuration
  const [configOpen, setConfigOpen] = useState(false);
  const [configProvider, setConfigProvider] = useState<ProviderCatalogItem | null>(null);
  const [configValues, setConfigValues] = useState<Record<string, string>>({});
  const [configEnvironment, setConfigEnvironment] = useState<'sandbox' | 'production'>('sandbox');
  const [configSubmitLoading, setConfigSubmitLoading] = useState(false);
  const [configSubmitError, setConfigSubmitError] = useState<string | null>(null);
  const [configTestLoading, setConfigTestLoading] = useState(false);

  const fetchAll = useCallback(async () => {
    setLoading(true);
    setError(null);
    const [catRes, credRes] = await Promise.all([apiProvidersCatalog(), apiProviderCredentialsList()]);
    if (!catRes.success || !catRes.data) {
      setError(catRes.error || 'Erreur lors du chargement du catalogue.');
      setLoading(false);
      return;
    }
    setCatalog(catRes.data);
    if (credRes.success && credRes.data) {
      setCredentials(credRes.data.items);
    }
    setLoading(false);
  }, []);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  /** Récupère l'état credential d'un provider par slug. */
  const getCredential = (slug: string) => credentials.find(c => c.provider_slug === slug);

  /** Statut d'affichage pour un provider. */
  const statusLabel = (status: string) => {
    switch (status) {
      case 'active': return { text: 'Actif', color: 'var(--green)', pillCls: 'p-gr' };
      case 'sandbox_only': return { text: 'Sandbox', color: 'var(--gold)', pillCls: 'p-g' };
      case 'error': return { text: 'Erreur', color: 'var(--red)', pillCls: 'p-r' };
      default: return { text: 'Non configuré', color: 'var(--text-dim)', pillCls: '' };
    }
  };

  /** Ouvrir le modal de config pour un provider. */
  const openConfig = (provider: ProviderCatalogItem) => {
    const cred = getCredential(provider.slug);
    const initialValues: Record<string, string> = {};
    provider.fields.forEach(f => { initialValues[f.key] = ''; });
    setConfigProvider(provider);
    setConfigValues(initialValues);
    setConfigEnvironment(cred?.environment ?? 'sandbox');
    setConfigSubmitError(null);
    setConfigOpen(true);
  };

  /** Soumettre les credentials (upsert). */
  const submitConfig = async () => {
    if (!configProvider) return;
    setConfigSubmitLoading(true);
    setConfigSubmitError(null);

    // Ne pas envoyer les champs vides (conserver les valeurs existantes).
    const filteredCreds: Record<string, string> = {};
    Object.entries(configValues).forEach(([k, v]) => {
      if (v.trim() !== '') filteredCreds[k] = v.trim();
    });

    const res = await apiProviderCredentialsUpsert(configProvider.slug, configEnvironment, filteredCreds);
    if (!res.success) {
      setConfigSubmitError(res.error || 'Erreur lors de l\'enregistrement.');
      setConfigSubmitLoading(false);
      return;
    }

    // Rafraîchir les états.
    setConfigOpen(false);
    setConfigSubmitLoading(false);
    fetchAll();
  };

  /** Tester la connectivité d'un provider. */
  const testProvider = async (slug: string) => {
    setConfigTestLoading(true);
    await apiProviderTest(slug);
    setConfigTestLoading(false);
    fetchAll();
  };

  // --- États de chargement / erreur ------------------------------------------
  if (loading || !catalog) {
    return (
      <div className="page">
        <div className="shimmer-bg" style={{ height: 80, borderRadius: 16, marginBottom: 16 }} />
        <div className="g3" style={{ gap: 14 }}>
          {[0,1,2,3,4,5].map(i => (
            <div key={i} className="shimmer-bg" style={{ height: 120, borderRadius: 16 }} />
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' }}>
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center', maxWidth: 460 }}>
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10 }}>Impossible de charger les providers</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20 }}>{error}</p>
          <button className="btn btn-cyan" onClick={fetchAll}>↻ Réessayer</button>
        </div>
      </div>
    );
  }

  // Filtre des providers par onglet.
  const filteredProviders = catalog.providers.filter(p =>
    tab === 'all' || p.category === tab
  );

  // Catégories disponibles dans les providers filtrés.
  const cats = catalog.categories;

  const tabs: { id: Tab; label: string }[] = [
    { id: 'all', label: 'Tous' },
    { id: 'mobile_money', label: '📱 Mobile Money' },
    { id: 'banking', label: '🏦 Banking' },
    { id: 'fx', label: '💱 FX' },
    { id: 'cards', label: '💳 Cards' },
    { id: 'crypto', label: '🔗 Crypto' },
    { id: 'payout_network', label: '🌍 Payout' },
  ];

  return (
    <div className="page">
      {/* En-tête */}
      <div className="page-header animate-up">
        <div className="page-label">Nexus Providers — Intégrations API</div>
        <div className="page-title">Configurez vos <span className="gc">providers de paiement.</span></div>
        <div style={{ marginTop: 10, fontSize: 12, color: 'var(--text-mid)', maxWidth: 600, lineHeight: 1.6 }}>
          Les identifiants API sont chiffrés en AES-256-GCM et ne sont jamais affichés en clair.
          Configurez chaque provider pour activer les routes de paiement.
        </div>
      </div>

      {/* KPIs */}
      <div className="g4 animate-up delay-1" style={{ marginBottom: 20 }}>
        {[
          { label: 'Providers', val: catalog.total.toString(), color: 'var(--cyan)', sub: 'Disponibles' },
          { label: 'Configurés', val: credentials.filter(c => c.has_credentials).length.toString(), color: 'var(--green)', sub: 'Avec clés API' },
          { label: 'Actifs', val: credentials.filter(c => c.status === 'active').length.toString(), color: 'var(--green)', sub: 'Prêts pour la production' },
          { label: 'En erreur', val: credentials.filter(c => c.status === 'error').length.toString(), color: 'var(--red)', sub: 'À vérifier' },
        ].map(s => (
          <div key={s.label} className="card stat-card">
            <div className="stat-label">{s.label}</div>
            <div className="stat-value" style={{ fontSize: 24, color: s.color }}>{s.val}</div>
            <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{s.sub}</div>
          </div>
        ))}
      </div>

      {/* Onglets */}
      <div className="account-tabs animate-up delay-1" style={{ marginBottom: 20, width: 'fit-content', flexWrap: 'wrap' }}>
        {tabs.map(t => (
          <button
            key={t.id}
            className={`account-tab ${tab === t.id ? 'active-personal' : ''}`}
            onClick={() => setTab(t.id)}
            style={{ fontSize: 11, fontWeight: 600 }}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Grille des providers */}
      <div className="g3 animate-up delay-2" style={{ gap: 14 }}>
        {filteredProviders.map(p => {
          const cred = getCredential(p.slug);
          const cat = cats[p.category] ?? { label: p.category, icon: '🌐', description: '' };
          const st = statusLabel(cred?.status ?? 'not_configured');
          return (
            <div
              key={p.slug}
              className="card"
              style={{ padding: 18, cursor: 'pointer', overflow: 'hidden', position: 'relative' }}
              onClick={() => openConfig(p)}
              onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(0,200,255,0.2)'; e.currentTarget.style.transform = 'translateY(-2px)'; }}
              onMouseLeave={e => { e.currentTarget.style.borderColor = ''; e.currentTarget.style.transform = ''; }}
            >
              {/* Barre colorée du haut */}
              <div style={{
                position: 'absolute', top: 0, left: 0, right: 0, height: 3,
                background: `linear-gradient(90deg, ${st.color}, transparent)`,
              }} />

              {/* Ligne supérieure : icône + statut */}
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 10 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <div className={`ib ${cat.icon === '📱' ? 'ib-gr' : cat.icon === '🏦' ? 'ib-c' : cat.icon === '💱' ? 'ib-g' : 'ib-v'}`} style={{ width: 40, height: 40, borderRadius: 10, fontSize: 18 }}>
                    {p.icon}
                  </div>
                  <div>
                    <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>{p.name}</div>
                    <div style={{ fontSize: 9, color: 'var(--text-dim)' }}>{cat.icon} {cat.label}</div>
                  </div>
                </div>
                <div className={`pill ${st.pillCls}`} style={{ fontSize: 7, flexShrink: 0 }}>
                  {st.text}
                </div>
              </div>

              {/* Champs credentials */}
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 10 }}>
                {p.fields.map(f => (
                  <div key={f.key} style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', display: 'flex', alignItems: 'center', gap: 4 }}>
                    <span className="dot" style={{ background: f.required ? 'var(--red)' : 'var(--text-dim)', width: 4, height: 4 }} />
                    {f.label}
                  </div>
                ))}
              </div>

              {/* Pied : doc + test */}
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderTop: '1px solid var(--border-soft)', paddingTop: 10 }}>
                <a
                  href={p.doc_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  onClick={e => e.stopPropagation()}
                  style={{ fontSize: 9, color: 'var(--cyan)', fontFamily: 'var(--font-mono)', letterSpacing: '0.05em' }}
                >
                  Documentation ↗
                </a>
                {cred?.has_credentials && (
                  <button
                    className="btn btn-ghost"
                    style={{ fontSize: 9, padding: '3px 8px' }}
                    onClick={e => { e.stopPropagation(); testProvider(p.slug); }}
                    disabled={configTestLoading}
                  >
                    {configTestLoading ? '...' : '🔍 Tester'}
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* Modal de configuration */}
      {configOpen && configProvider && (
        <div
          style={{
            position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)',
            backdropFilter: 'blur(8px)', display: 'flex', alignItems: 'center', justifyContent: 'center',
            zIndex: 200, padding: 20,
          }}
          onClick={() => setConfigOpen(false)}
        >
          <div
            className="card card-hi-c animate-fade"
            style={{ width: '100%', maxWidth: 520, padding: 24, maxHeight: '85vh', overflowY: 'auto' }}
            onClick={e => e.stopPropagation()}
          >
            {/* Header */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span style={{ fontSize: 24 }}>{configProvider.icon}</span>
                <div>
                  <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text-bright)' }}>{configProvider.name}</div>
                  <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>
                    Auth: {configProvider.auth_type} · {configProvider.base_url}
                  </div>
                </div>
              </div>
              <button
                className="btn btn-ghost"
                style={{ fontSize: 11, padding: '5px 10px' }}
                onClick={() => setConfigOpen(false)}
              >
                ✕
              </button>
            </div>

            {/* Sélecteur environment */}
            <div style={{ marginBottom: 16 }}>
              <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.1em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 6 }}>
                Environnement
              </div>
              <div style={{ display: 'flex', gap: 4 }}>
                {(['sandbox', 'production'] as const).map(env => (
                  <button
                    key={env}
                    className={`pill ${configEnvironment === env ? 'p-c' : ''}`}
                    style={{
                      fontSize: 9, cursor: 'pointer',
                      background: configEnvironment === env ? 'rgba(0,200,255,0.1)' : 'var(--panel2)',
                      borderColor: configEnvironment === env ? 'rgba(0,200,255,0.35)' : 'var(--border)',
                      color: configEnvironment === env ? 'var(--cyan)' : 'var(--text-dim)',
                    }}
                    onClick={() => setConfigEnvironment(env)}
                  >
                    {env === 'sandbox' ? '🧪 Sandbox' : '🚀 Production'}
                  </button>
                ))}
              </div>
            </div>

            {/* Champs credentials */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginBottom: 16 }}>
              {configProvider.fields.map(f => (
                <div key={f.key}>
                  <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.1em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 4 }}>
                    {f.label} {f.required && <span style={{ color: 'var(--red)' }}>*</span>}
                  </div>
                  <input
                    type={f.type === 'password' || f.type === 'textarea' ? 'password' : 'text'}
                    value={configValues[f.key] ?? ''}
                    onChange={e => setConfigValues(prev => ({ ...prev, [f.key]: e.target.value }))}
                    placeholder={f.placeholder}
                    style={{
                      width: '100%', padding: '8px 10px', background: 'var(--panel2)',
                      border: '1px solid var(--border)', borderRadius: 8,
                      color: 'var(--text-bright)', fontSize: 12, fontFamily: 'var(--font-mono)',
                      outline: 'none',
                    }}
                  />
                  <div style={{ fontSize: 9, color: 'var(--text-dim)', marginTop: 2 }}>
                    Laissez vide pour conserver la valeur existante
                  </div>
                </div>
              ))}
            </div>

            {/* Erreur */}
            {configSubmitError && (
              <div style={{ background: 'rgba(255,69,96,0.08)', border: '1px solid rgba(255,69,96,0.3)', borderRadius: 8, padding: 10, marginBottom: 12, fontSize: 11, color: 'var(--red)' }}>
                {configSubmitError}
              </div>
            )}

            {/* Actions */}
            <div style={{ display: 'flex', gap: 8 }}>
              <button
                className="btn btn-cyan"
                style={{ flex: 1, justifyContent: 'center', fontSize: 12 }}
                onClick={submitConfig}
                disabled={configSubmitLoading}
              >
                {configSubmitLoading ? 'Enregistrement...' : '✓ Enregistrer'}
              </button>
              <button
                className="btn btn-ghost"
                onClick={() => setConfigOpen(false)}
                style={{ fontSize: 12 }}
              >
                Annuler
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
