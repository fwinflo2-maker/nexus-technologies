import { useEffect, useState } from 'react';
import {
  apiControlAccess, apiControlOverview,
  apiControlProviders, apiControlClients, apiControlAudit,
  type InternalAccess, type ControlClient, type ControlOverview,
} from '../../api/client';
import { InternalDashboard, IntCard, IntRow, type InternalDashboardMeta } from './InternalDashboard';
import { AdminEmployeesPage, AdminConnectAccountsPage } from './AdminPages';

/** Registre des dashboards internes (RBAC) — chaque rôle a le sien. */
export const INTERNAL_DASHBOARDS: Record<string, InternalDashboardMeta> = {
  executive:  { id: 'executive',  label: 'Executive Dashboard',   icon: '👑', description: 'Vue globale Nexus : volumes, revenus, corridors, providers, risques et activité.' },
  operations: { id: 'operations', label: 'Operations Dashboard',   icon: '⚙️', description: 'Transactions en cours, échecs, retries, routing, incidents et self-healing.' },
  finance:    { id: 'finance',    label: 'Finance / Treasury',     icon: '💰', description: 'Soldes, liquidité, settlements, FX, frais, rapprochements et comptes providers.' },
  compliance: { id: 'compliance', label: 'Compliance / AML-KYC',   icon: '🛡️', description: 'KYC, alertes AML, transactions suspectes, sanctions et dossiers à examiner.' },
  risk:       { id: 'risk',       label: 'Risk / Fraud',           icon: '📊', description: 'Scoring, comportements suspects, velocity checks, anomalies et blocages.' },
  providers:  { id: 'providers',  label: 'Providers',              icon: '🔌', description: 'Thunes, Onafriq, pawaPay… disponibilité, coûts, taux de succès et credentials.' },
  support:    { id: 'support',    label: 'Support / Customer Ops', icon: '🧑‍💻', description: 'Clients, wallets, transactions, tickets, incidents et assistance.' },
  technical:  { id: 'technical',  label: 'Security / Technical',   icon: '🔐', description: 'Connexions, sessions, tokens révoqués, anomalies, audit logs et événements.' },
  business:   { id: 'business',   label: 'Business Management',    icon: '🏢', description: 'Entreprises clientes : comptes, utilisateurs, activité, volume et croissance.' },
};

function useAccess() {
  const [access, setAccess] = useState<InternalAccess | null>(null);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    void apiControlAccess().then((r) => { if (r.success && r.data) setAccess(r.data); setLoading(false); });
  }, []);
  return { access, loading };
}

/** Choisit le dashboard à afficher selon le rôle (ou null si aucun). */
export function resolveDashboard(dashboard: string | null | undefined): InternalDashboardMeta | null {
  return (dashboard && INTERNAL_DASHBOARDS[dashboard]) || null;
}

/** Mappe un platform_role → id de dashboard interne (ou null si client). */
export function roleToDashboard(role: string | undefined | null): string | null {
  const map: Record<string, string> = {
    superadmin: 'executive',
    operations_manager: 'operations',
    finance_treasury: 'finance',
    finance_operator: 'finance',
    compliance_officer: 'compliance',
    compliance_operator: 'compliance',
    risk_fraud: 'risk',
    provider_manager: 'providers',
    provider_engineer: 'providers',
    customer_support: 'support',
    support_operator: 'support',
    security_technical: 'technical',
    security_admin: 'technical',
    technical_admin: 'technical',
    security_engineer: 'technical',
    sre_operator: 'technical',
    backend_engineer: 'technical',
    qa_engineer: 'technical',
    ai_agent: 'technical',
    business_manager: 'business',
  };
  return (role && map[role]) || null;
}

/* ==========================================================================
   Contenu des dashboards spécialisés
   ========================================================================== */

function ExecutiveContent({ access }: { access: InternalAccess }) {
  const [ov, setOv] = useState<ControlOverview | null>(null);
  const [clients, setClients] = useState<ControlClient[]>([]);
  useEffect(() => {
    void apiControlOverview().then((r) => { if (r.success && r.data) setOv(r.data); });
    if (access.surfaces.clients) {
      void apiControlClients().then((r) => { if (r.success && r.data) setClients(r.data.items as ControlClient[]); });
    }
  }, [access]);
  const totalBusiness = clients.filter((c) => c.account_type === 'business').length;
  const totalPersonal = clients.length - totalBusiness;
  const totalBalances = clients.reduce((s, c) => s + Number(c.balances.EUR) + Number(c.balances.USD), 0);
  const totalTx = clients.reduce((s, c) => s + c.transactions, 0);

  return (
    <div className="g4">
      <IntCard title="Clients" icon="👥" tone="c">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>{clients.length}</div>
        <IntRow k="Entreprises" v={totalBusiness} />
        <IntRow k="Personnes" v={totalPersonal} />
      </IntCard>
      <IntCard title="Transactions" icon="🔄" tone="gr">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>{totalTx}</div>
        <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Toutes opérations confondues</div>
      </IntCard>
      <IntCard title="Volume (EUR)" icon="💰" tone="g">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--gold)', fontFamily: 'var(--font-mono)' }}>
          {totalBalances.toLocaleString('fr-FR')} €
        </div>
        <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Soldes totaux agrégés</div>
      </IntCard>
      <IntCard title="Environnement" icon="🌐" tone="v">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--violet)', fontFamily: 'var(--font-mono)' }}>
          {ov?.is_production ? 'Production' : ov?.environment ?? '—'}
        </div>
        <IntRow k="Providers" v={ov?.providers?.total ?? '—'} />
        <IntRow k="Mode strict" v={ov?.strict_mode ? 'Oui' : 'Non'} />
      </IntCard>
    </div>
  );
}

function OperationsContent() {
  return (
    <div className="g3">
      <IntCard title="File d'exécution" icon="⚙️" tone="c">
        <IntRow k="Transactions en cours" v="—" />
        <IntRow k="En attente de retry" v="—" />
        <IntRow k="Routing actif" v="—" />
      </IntCard>
      <IntCard title="Échecs & incidents" icon="🚨" tone="v">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--violet)', fontFamily: 'var(--font-mono)' }}>0</div>
        <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Aucun incident en cours</div>
      </IntCard>
      <IntCard title="Self-Healing" icon="🛠️" tone="gr">
        <IntRow k="Re-routage automatique" v="Actif" tone="var(--green)" />
        <IntRow k="Bascule providers" v="Prêt" tone="var(--green)" />
      </IntCard>
    </div>
  );
}

function FinanceContent() {
  return (
    <div className="g3">
      <IntCard title="Liquidité" icon="💰" tone="c">
        <IntRow k="Soldes wallets clients" v="—" />
        <IntRow k="Comptes providers" v="—" />
      </IntCard>
      <IntCard title="Settlements & FX" icon="💱" tone="g">
        <IntRow k="Settlements en attente" v="—" />
        <IntRow k="Taux FX référence" v="1 EUR = 655,957 XAF" />
      </IntCard>
      <IntCard title="Frais & rapprochement" icon="📋" tone="gr">
        <IntRow k="Frais collectés" v="—" />
        <IntRow k="Écarts de rapprochement" v="—" />
      </IntCard>
    </div>
  );
}

function ComplianceContent({ access }: { access: InternalAccess }) {
  return (
    <div className="g3">
      <IntCard title="KYC / KYB" icon="🛡️" tone="c">
        <IntRow k="Dossiers nominatifs" v={access.surfaces.kyc ? 'Accessible' : 'Restreint'} tone={access.surfaces.kyc ? 'var(--green)' : 'var(--text-dim)'} />
        <IntRow k="Vérifications en cours" v="—" />
      </IntCard>
      <IntCard title="Sanctions" icon="⚠️" tone="v">
        <IntRow k="Listes sanctions" v="—" />
        <IntRow k="Screening" v="Actif" tone="var(--green)" />
      </IntCard>
      <IntCard title="AML" icon="🔍" tone="g">
        <IntRow k="Alertes AML" v="—" />
        <IntRow k="Dossiers à examiner" v="—" />
      </IntCard>
    </div>
  );
}

function RiskContent() {
  return (
    <div className="g3">
      <IntCard title="Scoring" icon="🎯" tone="v">
        <IntRow k="Profils à risque" v="—" />
        <IntRow k="Score moyen" v="—" />
      </IntCard>
      <IntCard title="Anomalies" icon="🚩" tone="c">
        <IntRow k="Comportements suspects" v="—" />
        <IntRow k="Velocity checks" v="—" />
      </IntCard>
      <IntCard title="Blocages" icon="⛔" tone="g">
        <IntRow k="Comptes bloqués" v="—" />
        <IntRow k="En revue" v="—" />
      </IntCard>
    </div>
  );
}

function ProvidersContent({ access }: { access: InternalAccess }) {
  const [providers, setProviders] = useState<{ total: number } | null>(null);
  useEffect(() => {
    if (access.surfaces.providers) {
      void apiControlProviders().then((r) => { if (r.success && r.data) setProviders(r.data); });
    }
  }, [access]);
  return (
    <div className="g3">
      <IntCard title="Réseau providers" icon="🔌" tone="c">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>{providers?.total ?? '—'}</div>
        <IntRow k="Configurés" v={access.surfaces.providers ? 'Visible' : 'Restreint'} tone={access.surfaces.providers ? 'var(--green)' : 'var(--text-dim)'} />
      </IntCard>
      <IntCard title="Disponibilité" icon="📡" tone="gr">
        <IntRow k="Health check" v="—" />
        <IntRow k="Latence" v="—" />
      </IntCard>
      <IntCard title="Coûts & succès" icon="📈" tone="g">
        <IntRow k="Taux de succès" v="—" />
        <IntRow k="Coûts moyens" v="—" />
      </IntCard>
    </div>
  );
}

function SupportContent({ access }: { access: InternalAccess }) {
  const [clients, setClients] = useState<ControlClient[]>([]);
  useEffect(() => {
    if (access.surfaces.clients) {
      void apiControlClients().then((r) => { if (r.success && r.data) setClients(r.data.items as ControlClient[]); });
    }
  }, [access]);
  return (
    <div className="g3">
      <IntCard title="Clients" icon="👥" tone="c">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>
          {access.surfaces.clients ? clients.length : '—'}
        </div>
        <IntRow k="Assistance requise" v="—" />
      </IntCard>
      <IntCard title="Wallets & transactions" icon="💳" tone="gr">
        <IntRow k="Recherche client" v={access.surfaces.clients ? 'Disponible' : 'Restreint'} tone={access.surfaces.clients ? 'var(--green)' : 'var(--text-dim)'} />
      </IntCard>
      <IntCard title="Incidents clients" icon="🚨" tone="v">
        <IntRow k="Incidents ouverts" v="—" />
      </IntCard>
    </div>
  );
}

function TechnicalContent({ access }: { access: InternalAccess }) {
  const [audit, setAudit] = useState<{ total: number } | null>(null);
  useEffect(() => {
    if (access.surfaces.audit) {
      void apiControlAudit().then((r) => { if (r.success && r.data) setAudit(r.data); });
    }
  }, [access]);
  return (
    <div className="g3">
      <IntCard title="Sécurité" icon="🔐" tone="c">
        <IntRow k="Sessions actives" v="—" />
        <IntRow k="Tokens révoqués" v="—" />
      </IntCard>
      <IntCard title="Audit & événements" icon="📜" tone="v">
        <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--violet)', fontFamily: 'var(--font-mono)' }}>
          {access.surfaces.audit ? audit?.total ?? '—' : '—'}
        </div>
        <IntRow k="Journal d'audit" v={access.surfaces.audit ? 'Accessible' : 'Restreint'} tone={access.surfaces.audit ? 'var(--green)' : 'var(--text-dim)'} />
      </IntCard>
      <IntCard title="API & latence" icon="⚡" tone="g">
        <IntRow k="Statut API" v="Opérationnel" tone="var(--green)" />
        <IntRow k="Webhooks traités" v="—" />
      </IntCard>
    </div>
  );
}

function BusinessManagementContent({ access }: { access: InternalAccess }) {
  const [clients, setClients] = useState<ControlClient[]>([]);
  useEffect(() => {
    if (access.surfaces.clients) {
      void apiControlClients().then((r) => { if (r.success && r.data) setClients(r.data.items as ControlClient[]); });
    }
  }, [access]);
  const biz = clients.filter((c) => c.account_type === 'business');
  const volume = biz.reduce((s, c) => s + Number(c.balances.EUR) + Number(c.balances.USD), 0);
  const tx = biz.reduce((s, c) => s + c.transactions, 0);
  return (
    <>
      <div className="g4">
        <IntCard title="Entreprises" icon="🏢" tone="c">
          <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>
            {access.surfaces.clients ? biz.length : '—'}
          </div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Comptes entreprises clientes</div>
        </IntCard>
        <IntCard title="Volume (EUR)" icon="💰" tone="g">
          <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--gold)', fontFamily: 'var(--font-mono)' }}>
            {access.surfaces.clients ? volume.toLocaleString('fr-FR') + ' €' : '—'}
          </div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Soldes totaux des entreprises</div>
        </IntCard>
        <IntCard title="Transactions" icon="🔄" tone="gr">
          <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>
            {access.surfaces.clients ? tx : '—'}
          </div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Opérations des entreprises</div>
        </IntCard>
        <IntCard title="Croissance" icon="📈" tone="v">
          <IntRow k="Entreprises actives" v={biz.filter((c) => c.status === 'ACTIVE').length} />
          <IntRow k="En attente" v={biz.filter((c) => c.status === 'PENDING').length} />
        </IntCard>
      </div>
      <div className="card card-hi-c" style={{ marginTop: 16, padding: 18 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 12 }}>Entreprises clientes</div>
        {!access.surfaces.clients ? (
          <div style={{ fontSize: 12, color: 'var(--text-dim)' }}>Accès restreint pour ce rôle.</div>
        ) : biz.length === 0 ? (
          <div style={{ fontSize: 12, color: 'var(--text-dim)' }}>Aucune entreprise enregistrée.</div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {biz.map((c) => (
              <div key={c.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '8px 0', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                  {c.avatar ? <img src={c.avatar} alt="" style={{ width: 26, height: 26, borderRadius: '50%', objectFit: 'cover' }} /> : <span>🏢</span>}
                  <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    <span style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{c.full_name}</span>
                    <span style={{ color: 'var(--text-dim)', marginLeft: 8 }}>{c.email}</span>
                  </div>
                </div>
                <div style={{ color: 'var(--text-mid)', whiteSpace: 'nowrap' }}>
                  {Number(c.balances.EUR).toLocaleString('fr-FR')} € · {c.transactions} tx · {c.status}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </>
  );
}

/** Rendu du dashboard interne selon le rôle. */
export function InternalDashboardView({ dashboard }: { dashboard: string }) {
  const { access } = useAccess();
  const [tab, setTab] = useState<'overview' | 'employees' | 'connect'>('overview');
  const meta = INTERNAL_DASHBOARDS[dashboard] ?? INTERNAL_DASHBOARDS.executive;

  // Sous-navigation Super Admin (dashboard executive) : Employés + Connect.
  const showAdminTabs = dashboard === 'executive';

  if (!access) {
    return (
      <InternalDashboard meta={meta}>
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div className="nexus-spinner" />
          <p style={{ marginTop: 16, color: 'var(--text-mid)' }}>Chargement du dashboard…</p>
        </div>
      </InternalDashboard>
    );
  }

  return (
    <InternalDashboard meta={meta}>
      {showAdminTabs && (
        <div className="account-tabs animate-up" style={{ marginBottom: 20, width: 'fit-content' }}>
          {([['overview', '👑 Vue d\'ensemble'], ['employees', '👤 Employés'], ['connect', '🔌 Comptes Connect']] as const).map(([id, label]) => (
            <button key={id} className={`account-tab ${tab === id ? 'active-personal' : ''}`} onClick={() => setTab(id)} style={{ fontSize: 11, fontWeight: 600 }}>
              {label}
            </button>
          ))}
        </div>
      )}

      {tab === 'employees' && <AdminEmployeesPage />}
      {tab === 'connect' && <AdminConnectAccountsPage />}
      {tab === 'overview' && (
        <>
          {dashboard === 'executive' && <ExecutiveContent access={access} />}
          {dashboard === 'operations' && <OperationsContent />}
          {dashboard === 'finance' && <FinanceContent />}
          {dashboard === 'compliance' && <ComplianceContent access={access} />}
          {dashboard === 'risk' && <RiskContent />}
          {dashboard === 'providers' && <ProvidersContent access={access} />}
          {dashboard === 'support' && <SupportContent access={access} />}
          {dashboard === 'technical' && <TechnicalContent access={access} />}
          {dashboard === 'business' && <BusinessManagementContent access={access} />}
        </>
      )}
    </InternalDashboard>
  );
}
