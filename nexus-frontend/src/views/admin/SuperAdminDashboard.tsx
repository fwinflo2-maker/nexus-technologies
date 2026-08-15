import { useCallback, useEffect, useState } from 'react';
import AdminLayout from './AdminLayout';
import ProviderKeys from './ProviderKeys';
import AdminAccounts from './AdminAccounts';

/** Données agrégées du Super Admin (GET /api/admin/overview). */
interface AdminOverview {
  accounts: { total: number; personal: number; business: number; active: number; pending: number; suspended: number; connect: number };
  wallets: number;
  assets: { EUR: string; USD: string; XAF: string };
  transactions: { total: number; completed: number; failed: number; pending: number; processing: number; volume_xaf: number };
  kyc: { total: number; pending: number; approved: number; rejected: number };
  providers: { total: number; configured: number };
  recent_activity: Array<{ action: string; count: number }>;
  generated_at: string;
}

function money(n: number): string {
  return n.toLocaleString('fr-FR');
}

function Card({ title, icon, children, tone = 'c' }: { title: string; icon?: string; children: React.ReactNode; tone?: string }) {
  const cls = { c: 'card-hi-c', g: 'card-hi-g', gr: 'card-hi-gr', v: 'card-hi-v' }[tone] ?? 'card-hi-c';
  return (
    <div className={`card ${cls}`} style={{ padding: 18, display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
        {icon && <span style={{ fontSize: 16 }}>{icon}</span>}
        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>{title}</span>
      </div>
      {children}
    </div>
  );
}

function Row({ k, v, tone }: { k: string; v: React.ReactNode; tone?: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '5px 0', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
      <span style={{ color: 'var(--text-dim)' }}>{k}</span>
      <span style={{ color: tone || 'var(--text-bright)', fontWeight: 600, textAlign: 'right' }}>{v}</span>
    </div>
  );
}

export default function SuperAdminDashboard() {
  const [ov, setOv] = useState<AdminOverview | null>(null);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [section, setSection] = useState('overview');

  const load = useCallback(async () => {
    setState('loading');
    try {
      const res = await fetch('/api/admin/overview', {
        headers: { Authorization: `Bearer ${sessionStorage.getItem('nexus_token')}` },
      }).then((r) => r.json());
      if (res.success && res.data) { setOv(res.data); setState('ready'); }
      else setState('error');
    } catch {
      setState('error');
    }
  }, []);
  useEffect(() => { void load(); }, [load]);

  return (
    <AdminLayout active={section} onNavigate={setSection}>
      {state === 'loading' && <div className="card" style={{ padding: 50, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Chargement…</p></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}>Impossible de charger les données.</div>}

      {state === 'ready' && ov && (
        <div className="page">
          {/* ── Vue d'ensemble ── */}
          {section === 'overview' && (
            <>
              <Header title="Vue d’ensemble" desc="Activité globale de Nexus Technologies en temps réel." />
              <div className="page-label" style={{ marginBottom: 10 }}>Comptes</div>
              <div className="g4">
                <Card title="Total comptes" icon="👥"><Num v={ov.accounts.total} /></Card>
                <Card title="Personnel" icon="👤"><Num v={ov.accounts.personal} /></Card>
                <Card title="Business" icon="🏢"><Num v={ov.accounts.business} /></Card>
                <Card title="Connect" icon="🔌"><Num v={ov.accounts.connect} /></Card>
              </div>
              <div className="g4" style={{ marginTop: 12 }}>
                <Card title="Actifs" icon="✅" tone="gr"><Num v={ov.accounts.active} c="var(--green)" /></Card>
                <Card title="En attente" icon="⏳" tone="g"><Num v={ov.accounts.pending} c="var(--gold)" /></Card>
                <Card title="Suspendus" icon="⛔" tone="v"><Num v={ov.accounts.suspended} c="var(--red)" /></Card>
                <Card title="Wallets" icon="💳"><Num v={ov.wallets} /></Card>
              </div>

              <div className="page-label" style={{ marginTop: 28, marginBottom: 10 }}>Actifs (wallets)</div>
              <div className="g3">
                <Card title="EUR" icon="🇪🇺"><Num v={Number(ov.assets.EUR)} suffix=" €" /></Card>
                <Card title="USD" icon="🇺🇸"><Num v={Number(ov.assets.USD)} suffix=" $" /></Card>
                <Card title="XAF" icon="🌍"><Num v={Number(ov.assets.XAF)} suffix=" XAF" /></Card>
              </div>

              <div className="g2" style={{ marginTop: 28, alignItems: 'start' }}>
                <div>
                  <div className="page-label" style={{ marginBottom: 10 }}>Transactions</div>
                  <div className="g3">
                    <Card title="Total" icon="🔄"><Num v={ov.transactions.total} /></Card>
                    <Card title="Terminées" icon="✅" tone="gr"><Num v={ov.transactions.completed} c="var(--green)" /></Card>
                    <Card title="En cours" icon="⚙️" tone="g"><Num v={ov.transactions.processing} c="var(--gold)" /></Card>
                  </div>
                  <div className="g3" style={{ marginTop: 12 }}>
                    <Card title="Échouées" icon="❌" tone="v"><Num v={ov.transactions.failed} c={ov.transactions.failed > 0 ? 'var(--red)' : undefined} /></Card>
                    <Card title="Volume (XAF)" icon="💰"><Num v={ov.transactions.volume_xaf} /></Card>
                    <Card title="En attente" icon="⏳"><Num v={ov.transactions.pending} /></Card>
                  </div>
                </div>
                <div>
                  <div className="page-label" style={{ marginBottom: 10 }}>KYC & Providers</div>
                  <div className="g2">
                    <Card title="Dossiers KYC" icon="🛡️"><Num v={ov.kyc.total} /><Row k="En attente" v={ov.kyc.pending} /><Row k="Validés" v={ov.kyc.approved} /><Row k="Rejetés" v={ov.kyc.rejected} /></Card>
                    <Card title="Providers" icon="🔌"><Num v={ov.providers.total} /><Row k="Configurés" v={ov.providers.configured} /></Card>
                  </div>
                </div>
              </div>

              <div className="page-label" style={{ marginTop: 28, marginBottom: 10 }}>Activité récente (audit)</div>
              <div className="card" style={{ padding: 12 }}>
                {ov.recent_activity.length === 0 ? (
                  <div style={{ fontSize: 12, color: 'var(--text-dim)', padding: 12 }}>Aucune activité enregistrée.</div>
                ) : ov.recent_activity.map((a, i) => (
                  <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '8px 10px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                    <span style={{ color: 'var(--text-mid)', fontFamily: 'var(--font-mono)' }}>{a.action}</span>
                    <span style={{ color: 'var(--text-bright)', fontWeight: 600 }}>{a.count}</span>
                  </div>
                ))}
              </div>
            </>
          )}

          {/* ── Comptes : liste riche classée par secteur + popup détail ── */}
          {section === 'accounts' && (
            <>
              <Header title="Comptes" desc="Tous les clients Nexus classés par secteur (Personnel / Business). Cliquez sur un compte pour voir le détail complet." />
              <div className="g4" style={{ marginBottom: 20 }}>
                <Card title="Total" icon="👥"><Num v={ov.accounts.total} /></Card>
                <Card title="Personnel" icon="👤"><Num v={ov.accounts.personal} /></Card>
                <Card title="Business" icon="🏢"><Num v={ov.accounts.business} /></Card>
                <Card title="Connect" icon="🔌"><Num v={ov.accounts.connect} /></Card>
              </div>
              <AdminAccounts />
            </>
          )}

          {/* ── Transactions ── */}
          {section === 'transactions' && (
            <>
              <Header title="Transactions" desc="Volume et état des opérations Nexus." />
              <div className="g4">
                <Card title="Total" icon="🔄"><Num v={ov.transactions.total} /></Card>
                <Card title="Terminées" icon="✅" tone="gr"><Num v={ov.transactions.completed} c="var(--green)" /></Card>
                <Card title="En cours" icon="⚙️" tone="g"><Num v={ov.transactions.processing} c="var(--gold)" /></Card>
                <Card title="Échouées" icon="❌" tone="v"><Num v={ov.transactions.failed} c={ov.transactions.failed > 0 ? 'var(--red)' : undefined} /></Card>
              </div>
              <div className="g3" style={{ marginTop: 12 }}>
                <Card title="En attente" icon="⏳"><Num v={ov.transactions.pending} /></Card>
                <Card title="Volume (XAF)" icon="💰"><Num v={ov.transactions.volume_xaf} /></Card>
                <Card title="Taux de succès" icon="📈" tone="gr"><Num v={ov.transactions.total > 0 ? Math.round((ov.transactions.completed / ov.transactions.total) * 100) : 0} suffix="%" c="var(--green)" /></Card>
              </div>
            </>
          )}

          {/* ── Autres sections (placeholders données réelles quand disponibles) ── */}
          {['operations', 'treasury', 'compliance', 'risk', 'support', 'security', 'technical', 'audit'].includes(section) && (
            <SectionPlaceholder section={section} ov={ov} />
          )}

          {/* ── Providers : vue d'ensemble + clés API/publiques ── */}
          {section === 'providers' && (
            <>
              <Header title="Providers" desc="Réseau de providers et clés API / publiques associées." />
              <div className="g3" style={{ marginBottom: 20 }}>
                <Card title="Total providers" icon="🔌"><Num v={ov.providers.total} /></Card>
                <Card title="Configurés" icon="✅" tone="gr"><Num v={ov.providers.configured} c="var(--green)" /></Card>
                <Card title="État" icon="📡"><div style={{ fontSize: 18, fontWeight: 700, color: 'var(--gold)' }}>À configurer</div></Card>
              </div>
              <ProviderKeys />
            </>
          )}

          {section === 'settings' && (
            <>
              <Header title="Paramètres" desc="Configuration de la plateforme Nexus." />
              <div className="card card-hi-c" style={{ padding: 24, maxWidth: 560 }}>
                <Row k="Environnement" v="Sandbox" />
                <Row k="Mode strict" v="Désactivé" />
                <Row k="Providers configurés" v={ov.providers.configured} />
                <Row k="Dernière génération" v={new Date(ov.generated_at).toLocaleString('fr-FR')} />
              </div>
            </>
          )}
        </div>
      )}
    </AdminLayout>
  );
}

function Header({ title, desc }: { title: string; desc: string }) {
  return (
    <div style={{ marginBottom: 22 }}>
      <div className="page-label">SUPER ADMIN · {title.toUpperCase()}</div>
      <div className="page-title">{title}</div>
      <p style={{ marginTop: 6, fontSize: 13, color: 'var(--text-mid)', maxWidth: 760 }}>{desc}</p>
    </div>
  );
}

function Num({ v, suffix = '', c }: { v: number; suffix?: string; c?: string }) {
  return <div style={{ fontSize: 26, fontWeight: 800, color: c || 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{money(v)}{suffix}</div>;
}

/** Sections dont les données détaillées arrivent au fur et à mesure. */
function SectionPlaceholder({ section, ov }: { section: string; ov: AdminOverview }) {
  const meta: Record<string, { icon: string; label: string; desc: string; rows: Array<[string, string | number]> }> = {
    operations: { icon: '⚙️', label: 'Opérations', desc: 'Transactions en cours, retries, routing et incidents.', rows: [['En cours', ov.transactions.processing], ['En attente', ov.transactions.pending], ['Terminées', ov.transactions.completed]] },
    treasury: { icon: '💰', label: 'Trésorerie', desc: 'Liquidité et actifs par devise.', rows: [['EUR', money(Number(ov.assets.EUR)) + ' €'], ['USD', money(Number(ov.assets.USD)) + ' $'], ['XAF', money(Number(ov.assets.XAF)) + ' XAF']] },
    compliance: { icon: '🛡️', label: 'Compliance / KYC', desc: 'Dossiers de vérification d’identité.', rows: [['Dossiers KYC', ov.kyc.total], ['En attente', ov.kyc.pending], ['Validés', ov.kyc.approved], ['Rejetés', ov.kyc.rejected]] },
    risk: { icon: '📊', label: 'Risque / Fraude', desc: 'Alertes et comportements à risque.', rows: [['Comptes suspendus', ov.accounts.suspended], ['Transactions échouées', ov.transactions.failed]] },
    providers: { icon: '🔌', label: 'Providers', desc: 'État du réseau de providers.', rows: [['Total', ov.providers.total], ['Configurés', ov.providers.configured]] },
    support: { icon: '🧑‍💻', label: 'Support', desc: 'Clients et activité de support.', rows: [['Comptes total', ov.accounts.total], ['Comptes actifs', ov.accounts.active]] },
    security: { icon: '🔐', label: 'Sécurité', desc: 'Événements et accès.', rows: [['Événements d’audit', ov.recent_activity.reduce((s, a) => s + a.count, 0)]] },
    technical: { icon: '🛠️', label: 'Technique', desc: 'État des services et de l’API.', rows: [['API', 'Opérationnelle'], ['Providers', ov.providers.total]] },
    audit: { icon: '📜', label: 'Audit', desc: 'Journal d’activité.', rows: ov.recent_activity.slice(0, 6).map((a) => [a.action, a.count] as [string, number]) },
  };
  const m = meta[section];
  return (
    <>
      <Header title={m.label} desc={m.desc} />
      <div className="card card-hi-c" style={{ padding: 18, maxWidth: 560 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          <span style={{ fontSize: 18 }}>{m.icon}</span>
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>{m.label}</span>
        </div>
        {m.rows.map(([k, v], i) => <Row key={i} k={String(k)} v={String(v)} />)}
        <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 12 }}>
          Les données détaillées de cette section seront enrichies progressivement.
        </div>
      </div>
    </>
  );
}
