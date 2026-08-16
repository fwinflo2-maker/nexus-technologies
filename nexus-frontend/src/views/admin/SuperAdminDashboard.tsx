import { useCallback, useEffect, useState } from 'react';
import AdminLayout from './AdminLayout';
import ProviderKeys from './ProviderKeys';
import AdminAccounts from './AdminAccounts';
import AdminTransactions from './AdminTransactions';
import AdminCompliance from './AdminCompliance';
import AdminAudit from './AdminAudit';
import AdminOperations from './AdminOperations';
import AdminRisk from './AdminRisk';
import AdminTechnical from './AdminTechnical';
import AdminTreasury from './AdminTreasury';
import AdminSecurity from './AdminSecurity';
import AdminSupport from './AdminSupport';
import { VolumeAreaChart, TransactionsStackChart, AssetDonut, StatusDonut, ProviderTopChart, AuditBarChart } from './CockpitCharts';
import { Row, Panel, fmtMoney } from './adminUi';

interface AdminOverview {
  accounts: { total: number; personal: number; business: number; active: number; pending: number; suspended: number; connect: number };
  wallets: number;
  assets: { EUR: string; USD: string; XAF: string };
  transactions: { total: number; completed: number; failed: number; pending: number; processing: number; volume_xaf: number };
  kyc: { total: number; pending: number; approved: number; rejected: number };
  providers: { total: number; configured: number };
  recent_activity: Array<{ action: string; count: number }>;
  series: {
    transactions: Array<{ date: string; count: number }>;
    volume_eur: Array<{ date: string; volume: number }>;
    audit: Array<{ date: string; count: number }>;
  };
  status_breakdown: Array<{ status: string; count: number }>;
  provider_top: Array<{ provider: string; count: number }>;
  generated_at: string;
}

function money(n: number): string { return n.toLocaleString('fr-FR'); }
function Num({ v, suffix = '', c, size = 26 }: { v: number; suffix?: string; c?: string; size?: number }) {
  return <div style={{ fontSize: size, fontWeight: 800, color: c || 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{money(v)}{suffix}</div>;
}
function Card({ title, icon, children }: { title: string; icon?: string; children: React.ReactNode }) {
  return (
    <div className="card" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        {icon && <span style={{ fontSize: 15 }}>{icon}</span>}
        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>{title}</span>
      </div>
      {children}
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
      if (res.success && res.data) { setOv(res.data); setState('ready'); } else setState('error');
    } catch { setState('error'); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const assetDonut = ov ? [
    { name: 'EUR', value: Number(ov.assets.EUR), color: 'var(--cyan)' },
    { name: 'USD', value: Number(ov.assets.USD), color: 'var(--cyan2)' },
    { name: 'XAF', value: Number(ov.assets.XAF), color: 'var(--violet)' },
  ] : [];

  return (
    <AdminLayout active={section} onNavigate={setSection}>
      {state === 'loading' && <div className="card" style={{ padding: 50, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Chargement…</p></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}>Impossible de charger les données.</div>}

      {state === 'ready' && ov && (
        <div className="page">
          {/* ═══ VUE D'ENSEMBLE — cockpit ═══ */}
          {section === 'overview' && (
            <>
              <Header title="Vue d'ensemble" desc="Cockpit temps réel de Nexus Technologies — activité, liquidité et santé de la plateforme." />

              <div className="g4">
                <Card title="Total comptes" icon="👥"><Num v={ov.accounts.total} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{ov.accounts.active} actifs · {ov.accounts.pending} en attente</div></Card>
                <Card title="Personnel / Business" icon="🏢"><Num v={ov.accounts.personal + ov.accounts.business} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{ov.accounts.personal} personnel · {ov.accounts.business} business</div></Card>
                <Card title="Connect (B2B)" icon="🔌"><Num v={ov.accounts.connect} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>comptes API connectés</div></Card>
                <Card title="Volume traité" icon="💰"><Num v={ov.transactions.volume_xaf} suffix=" FCFA" c="var(--cyan)" size={22} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{ov.transactions.total} transactions · {ov.wallets} wallets</div></Card>
              </div>

              <div className="g2" style={{ marginTop: 16, alignItems: 'stretch' }}>
                <VolumeAreaChart data={ov.series.volume_eur} />
                <TransactionsStackChart data={ov.series.transactions} />
              </div>

              <div className="g3" style={{ marginTop: 16, alignItems: 'stretch' }}>
                <AssetDonut data={assetDonut} />
                <StatusDonut data={ov.status_breakdown} />
                <ProviderTopChart data={ov.provider_top} />
              </div>

              <div className="g2" style={{ marginTop: 16, alignItems: 'stretch' }}>
                <AuditBarChart data={ov.series.audit} />
                <Panel title="Indicateurs clés" icon="🎯">
                  <Row k="Comptes actifs" v={<span style={{ color: 'var(--green)' }}>{ov.accounts.active}</span>} />
                  <Row k="Comptes suspendus" v={<span style={{ color: 'var(--red)' }}>{ov.accounts.suspended}</span>} />
                  <Row k="Taux de succès" v={<span style={{ color: 'var(--green)' }}>{ov.transactions.total > 0 ? Math.round((ov.transactions.completed / ov.transactions.total) * 100) : 0}%</span>} />
                  <Row k="Dossiers KYC" v={ov.kyc.total} />
                  <Row k="KYC en attente" v={<span style={{ color: 'var(--gold)' }}>{ov.kyc.pending}</span>} />
                  <Row k="Providers" v={`${ov.providers.configured}/${ov.providers.total} configurés`} />
                  <Row k="Liquidité EUR" v={fmtMoney(Number(ov.assets.EUR), '€')} />
                  <Row k="Liquidité USD" v={fmtMoney(Number(ov.assets.USD), '$')} />
                  <Row k="Liquidité XAF" v={fmtMoney(Number(ov.assets.XAF), 'FCFA')} />
                </Panel>
              </div>

              <div className="page-label" style={{ marginTop: 26, marginBottom: 10 }}>Activité récente (audit)</div>
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

          {/* ═══ COMPTES ═══ */}
          {section === 'accounts' && (
            <>
              <Header title="Comptes" desc="Tous les clients Nexus classés par secteur (Personnel / Business). Cliquez sur un compte pour le détail complet." />
              <div className="g4" style={{ marginBottom: 20 }}>
                <Card title="Total" icon="👥"><Num v={ov.accounts.total} /></Card>
                <Card title="Personnel" icon="👤"><Num v={ov.accounts.personal} /></Card>
                <Card title="Business" icon="🏢"><Num v={ov.accounts.business} /></Card>
                <Card title="Connect" icon="🔌"><Num v={ov.accounts.connect} /></Card>
              </div>
              <AdminAccounts />
            </>
          )}

          {/* ═══ TRANSACTIONS ═══ */}
          {section === 'transactions' && (
            <>
              <Header title="Transactions" desc="Registre détaillé des opérations avec filtres (statut, devise, type, recherche)." />
              <div className="g4" style={{ marginBottom: 20 }}>
                <Card title="Total" icon="🔄"><Num v={ov.transactions.total} /></Card>
                <Card title="Volume (XAF)" icon="💰"><Num v={ov.transactions.volume_xaf} /></Card>
                <Card title="Taux de succès" icon="📈"><Num v={ov.transactions.total > 0 ? Math.round((ov.transactions.completed / ov.transactions.total) * 100) : 0} suffix="%" c="var(--green)" /></Card>
                <Card title="En cours" icon="⚙️"><Num v={ov.transactions.processing + ov.transactions.pending} c="var(--gold)" /></Card>
              </div>
              <AdminTransactions />
            </>
          )}

          {/* ═══ OPÉRATIONS ═══ */}
          {section === 'operations' && (
            <>
              <Header title="Opérations" desc="File d'exécution des transactions non terminales et performance des traitements." />
              <AdminOperations />
            </>
          )}

          {/* ═══ TRÉSORERIE ═══ */}
          {section === 'treasury' && (
            <>
              <Header title="Trésorerie" desc="Liquidité et actifs par devise, évolution du volume traité." />
              <AdminTreasury assets={ov.assets} series={ov.series} />
            </>
          )}

          {/* ═══ COMPLIANCE / KYC ═══ */}
          {section === 'compliance' && (
            <>
              <Header title="Compliance / KYC" desc="Dossiers de vérification d'identité (KYC/KYB) issus du provider SumSub." />
              <AdminCompliance />
            </>
          )}

          {/* ═══ RISQUE / FRAUDE ═══ */}
          {section === 'risk' && (
            <>
              <Header title="Risque / Fraude" desc="Indicateurs de risque, taux d'échec par provider et transactions échouées à surveiller." />
              <AdminRisk />
            </>
          )}

          {/* ═══ PROVIDERS ═══ */}
          {section === 'providers' && (
            <>
              <Header title="Providers" desc="Réseau de providers, credentials et clés API / publiques associées." />
              <div className="g3" style={{ marginBottom: 20 }}>
                <Card title="Total providers" icon="🔌"><Num v={ov.providers.total} /></Card>
                <Card title="Configurés" icon="✅"><Num v={ov.providers.configured} c="var(--green)" /></Card>
                <Card title="Clés actives" icon="🗝️"><Num v={ov.provider_top.length} c="var(--cyan)" /></Card>
              </div>
              <ProviderKeys />
            </>
          )}

          {/* ═══ SUPPORT ═══ */}
          {section === 'support' && (
            <>
              <Header title="Support" desc="Activité support et état de la base clients." />
              <AdminSupport />
            </>
          )}

          {/* ═══ SÉCURITÉ ═══ */}
          {section === 'security' && (
            <>
              <Header title="Sécurité" desc="Événements de sécurité, connexions et alertes." />
              <AdminSecurity />
            </>
          )}

          {/* ═══ TECHNIQUE ═══ */}
          {section === 'technical' && (
            <>
              <Header title="Technique" desc="Santé des services internes et état des credentials providers." />
              <AdminTechnical />
            </>
          )}

          {/* ═══ AUDIT ═══ */}
          {section === 'audit' && (
            <>
              <Header title="Audit" desc="Journal d'activité complet et traçabilité des actions." />
              <AdminAudit />
            </>
          )}

          {/* ═══ PARAMÈTRES ═══ */}
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
