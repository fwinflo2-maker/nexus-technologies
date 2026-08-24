import { useCallback, useEffect, useState } from 'react';
import AdminLayout from './AdminLayout';
import SendPage from '../dashboard/SendPage';
import WalletPage from '../dashboard/WalletPage';
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
import AdminEmployees from './AdminEmployees';
import AdminSettings from './AdminSettings';
import { AdminLinkedAccounts, AdminMaintenance, AdminWebhooks } from './AdminControlSurfaces';
import { VolumeAreaChart, TransactionsStackChart, AssetDonut, StatusDonut, ProviderTopChart, AuditBarChart } from './CockpitCharts';
import { Row, Panel, fmtMoney } from './adminUi';
import { motion } from 'framer-motion';
import { apiAdminOverview, type AdminOverviewData } from '../../api/client';
import { HoverCard, AnimatedNumber, SectionTransition, RevealGroup, AnimatedTitle, LivePulse } from '../../components/anim/Premium';

function Num({ v, suffix = '', c, size = 26 }: { v: number; suffix?: string; c?: string; size?: number }) {
  return (
    <div style={{ fontSize: size, fontWeight: 800, color: c || 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>
      <AnimatedNumber value={v} suffix={suffix} />
    </div>
  );
}
function Card({ title, icon, children, index = 0, glow }: { title: string; icon?: string; children: React.ReactNode; index?: number; glow?: string }) {
  return (
    <HoverCard className="card" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 8, position: 'relative', overflow: 'hidden' }} glow={glow} index={index}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, position: 'relative', zIndex: 1 }}>
        <span style={{ fontSize: 15 }}>{icon}</span>
        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>{title}</span>
        <LivePulse color={glow || 'var(--green)'} />
      </div>
      <div style={{ position: 'relative', zIndex: 1 }}>{children}</div>
    </HoverCard>
  );
}

export default function SuperAdminDashboard() {
  const [ov, setOv] = useState<AdminOverviewData | null>(null);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [section, setSection] = useState('overview');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiAdminOverview();
    if (res.success && res.data) { setOv(res.data); setState('ready'); } else setState('error');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const assetDonut = ov ? [
    { name: 'EUR', value: Number(ov.assets.EUR), color: 'var(--cyan)' },
    { name: 'USD', value: Number(ov.assets.USD), color: 'var(--cyan2)' },
    { name: 'XAF', value: Number(ov.assets.XAF), color: 'var(--violet)' },
  ] : [];

  return (
    <AdminLayout active={section} onNavigate={setSection}>
      {section === 'overview' && state === 'loading' && <div className="card" style={{ padding: 50, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Chargement…</p></div>}
      {section === 'overview' && state === 'error' && <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}>Impossible de charger la vue d'ensemble. <button className="btn btn-ghost" onClick={() => void load()}>Réessayer</button></div>}

      {(section !== 'overview' || (state === 'ready' && ov)) && (
        <SectionTransition id={section}>
        <div className="page">
          {/* ═══ VUE D'ENSEMBLE — cockpit ═══ */}
          {section === 'overview' && ov && (
            <>
              <Header section="overview" title={<AnimatedTitle text="Vue d'ensemble" />} desc="Cockpit temps réel de Nexus Technologies — activité, liquidité et santé de la plateforme." />

              <RevealGroup className="g4" stagger={0.09}>
                <Card title="Total comptes" icon="👥"><Num v={ov.accounts.total} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{ov.accounts.active} actifs · {ov.accounts.pending} en attente</div></Card>
                <Card title="Personnel / Business" icon="🏢"><Num v={ov.accounts.personal + ov.accounts.business} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{ov.accounts.personal} personnel · {ov.accounts.business} business</div></Card>
                <Card title="Connect (B2B)" icon="🔌"><Num v={ov.accounts.connect} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>comptes API connectés</div></Card>
                <Card title="Volume traité" icon="💰" glow="var(--cyan)"><Num v={ov.transactions.volume_xaf} suffix=" FCFA" c="var(--cyan)" size={22} /><div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{ov.transactions.total} transactions · {ov.wallets} wallets</div></Card>
              </RevealGroup>

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
                  <motion.div key={i} initial={{ opacity: 0, x: -8 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: i * 0.05 }} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '8px 10px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                    <span style={{ color: 'var(--text-mid)', fontFamily: 'var(--font-mono)' }}>{a.action}</span>
                    <span style={{ color: 'var(--text-bright)', fontWeight: 600 }}>{a.count}</span>
                  </motion.div>
                ))}
              </div>
            </>
          )}

          {/* ═══ ENVOYER — intégré, sans restriction (superadmin) ═══ */}
          {section === 'send' && (
            <div style={{ maxWidth: 960 }}>
              <Header title="Envoyer" desc="Transfert direct depuis la console Super Admin — sans restriction : toutes routes, n'importe quel pays, aucun plafond KYC. Validable en temps réel." />
              <SendPage />
            </div>
          )}

          {/* ═══ PORTEFEUILLE — intégré ═══ */}
          {section === 'wallet' && (
            <div style={{ maxWidth: 960 }}>
              <Header title="Portefeuille" desc="Portefeuille multi-devises du Super Admin." />
              <WalletPage />
            </div>
          )}

          {/* ═══ COMPTES ═══ */}
          {section === 'accounts' && (
            <>
              <Header title="Comptes" desc="Tous les clients Nexus classés par secteur (Personnel / Business). Cliquez sur un compte pour le détail complet." />
              {ov && <div className="g4" style={{ marginBottom: 20 }}>
                <Card title="Total" icon="👥"><Num v={ov.accounts.total} /></Card>
                <Card title="Personnel" icon="👤"><Num v={ov.accounts.personal} /></Card>
                <Card title="Business" icon="🏢"><Num v={ov.accounts.business} /></Card>
                <Card title="Connect" icon="🔌"><Num v={ov.accounts.connect} /></Card>
              </div>}
              <AdminAccounts />
            </>
          )}

          {/* ═══ TRANSACTIONS ═══ */}
          {section === 'transactions' && (
            <>
              <Header title="Transactions" desc="Registre détaillé des opérations avec filtres (statut, devise, type, recherche)." />
              {ov && <div className="g4" style={{ marginBottom: 20 }}>
                <Card title="Total" icon="🔄"><Num v={ov.transactions.total} /></Card>
                <Card title="Volume (XAF)" icon="💰"><Num v={ov.transactions.volume_xaf} /></Card>
                <Card title="Taux de succès" icon="📈"><Num v={ov.transactions.total > 0 ? Math.round((ov.transactions.completed / ov.transactions.total) * 100) : 0} suffix="%" c="var(--green)" /></Card>
                <Card title="En cours" icon="⚙️"><Num v={ov.transactions.processing + ov.transactions.pending} c="var(--gold)" /></Card>
              </div>}
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
          {section === 'treasury' && ov && (
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
              <Header title="Providers" desc="Catalogue opérationnel : configurez et testez les credentials plateforme (Stripe Issuing pour les cartes virtuelles)." />
              <ProviderKeys />
            </>
          )}

          {section === 'webhooks' && (
            <>
              <Header title="Webhooks providers & KYC" desc="Journal normalisé des événements entrants, sans payload ni secret." />
              <AdminWebhooks />
            </>
          )}

          {section === 'linked-accounts' && (
            <>
              <Header title="Comptes liés" desc="Signaux réels de rapprochement par e-mail ou téléphone normalisé." />
              <AdminLinkedAccounts />
            </>
          )}

          {section === 'employees' && (
            <>
              <Header title="Employés" desc="Comptes internes, rôles plateforme, invitations et activation." />
              <AdminEmployees />
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

          {section === 'maintenance' && (
            <>
              <Header title="Maintenance" desc="Diagnostic et réconciliation explicite des paiements immobilisés dans l'environnement actif." />
              <AdminMaintenance />
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
              <AdminSettings />
            </>
          )}
        </div>
        </SectionTransition>
      )}
    </AdminLayout>
  );
}

function Header({ title, desc, section }: { title: React.ReactNode; desc: string; section?: string }) {
  return (
    <div style={{ marginBottom: 22 }}>
      <div className="page-label">SUPER ADMIN · {(section || (typeof title === 'string' ? title : '')).toUpperCase()}</div>
      <div className="page-title" style={{ fontSize: 26 }}>{title}</div>
      <p style={{ marginTop: 6, fontSize: 13, color: 'var(--text-mid)', maxWidth: 760 }}>{desc}</p>
    </div>
  );
}
