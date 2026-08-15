import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';

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

/** Carte de section premium (style Revolut). */
function IntCard({ title, icon, children, tone = 'c' }: { title: string; icon?: string; children: React.ReactNode; tone?: string }) {
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

/** Ligne clé/valeur. */
function IntRow({ k, v, tone }: { k: string; v: React.ReactNode; tone?: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '5px 0', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
      <span style={{ color: 'var(--text-dim)' }}>{k}</span>
      <span style={{ color: tone || 'var(--text-bright)', fontWeight: 600, textAlign: 'right' }}>{v}</span>
    </div>
  );
}

export default function SuperAdminDashboard() {
  const { user } = useAuth();
  const [ov, setOv] = useState<AdminOverview | null>(null);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');

  const load = useCallback(async () => {
    setState('loading');
    const res = await fetch('/api/admin/overview', {
      headers: { Authorization: `Bearer ${sessionStorage.getItem('nexus_token')}` },
    }).then((r) => r.json());
    if (res.success && res.data) { setOv(res.data); setState('ready'); }
    else setState('error');
  }, []);
  useEffect(() => { void load(); }, [load]);

  return (
    <div className="page">
      <div className="page-header animate-up" style={{ marginBottom: 24 }}>
        <div className="page-label">SUPER ADMIN · CENTRE DE CONTRÔLE</div>
        <div className="page-title">Dashboard <span className="gc">Super Admin</span></div>
        <p style={{ marginTop: 8, fontSize: 13, color: 'var(--text-mid)', maxWidth: 760, lineHeight: 1.6 }}>
          Vue globale des activités de Nexus Technologies : comptes, actifs, transactions, KYC, providers et audit.
        </p>
      </div>

      {state === 'loading' && <div className="card" style={{ padding: 50, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Chargement…</p></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}>Impossible de charger les données.</div>}

      {state === 'ready' && ov && (
        <>
          {/* ── KPIs Comptes ── */}
          <div className="page-label" style={{ marginBottom: 10 }}>Comptes</div>
          <div className="g4">
            <IntCard title="Total comptes" icon="👥" tone="c">
              <div style={{ fontSize: 30, fontWeight: 800, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{ov.accounts.total}</div>
            </IntCard>
            <IntCard title="Personnel" icon="👤" tone="c"><div style={{ fontSize: 26, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{ov.accounts.personal}</div></IntCard>
            <IntCard title="Business" icon="🏢" tone="c"><div style={{ fontSize: 26, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{ov.accounts.business}</div></IntCard>
            <IntCard title="Connect" icon="🔌" tone="c"><div style={{ fontSize: 26, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{ov.accounts.connect}</div></IntCard>
          </div>
          <div className="g4" style={{ marginTop: 12 }}>
            <IntCard title="Actifs" icon="✅" tone="gr"><div style={{ fontSize: 22, color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>{ov.accounts.active}</div></IntCard>
            <IntCard title="En attente" icon="⏳" tone="g"><div style={{ fontSize: 22, color: 'var(--gold)', fontFamily: 'var(--font-mono)' }}>{ov.accounts.pending}</div></IntCard>
            <IntCard title="Suspendus" icon="⛔" tone="v"><div style={{ fontSize: 22, color: 'var(--red)', fontFamily: 'var(--font-mono)' }}>{ov.accounts.suspended}</div></IntCard>
            <IntCard title="Wallets" icon="💳" tone="c"><div style={{ fontSize: 22, fontFamily: 'var(--font-mono)' }}>{ov.wallets}</div></IntCard>
          </div>

          {/* ── Actifs par devise ── */}
          <div className="page-label" style={{ marginTop: 28, marginBottom: 10 }}>Actifs (wallets)</div>
          <div className="g3">
            <IntCard title="EUR" icon="🇪🇺" tone="c"><div style={{ fontSize: 26, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{money(Number(ov.assets.EUR))} €</div></IntCard>
            <IntCard title="USD" icon="🇺🇸" tone="c"><div style={{ fontSize: 26, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{money(Number(ov.assets.USD))} $</div></IntCard>
            <IntCard title="XAF" icon="🌍" tone="c"><div style={{ fontSize: 26, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{money(Number(ov.assets.XAF))} XAF</div></IntCard>
          </div>

          {/* ── Transactions & KYC & Providers ── */}
          <div className="g2" style={{ marginTop: 28, alignItems: 'start' }}>
            <div>
              <div className="page-label" style={{ marginBottom: 10 }}>Transactions</div>
              <div className="g3">
                <IntCard title="Total" icon="🔄" tone="c"><div style={{ fontSize: 24, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{ov.transactions.total}</div></IntCard>
                <IntCard title="Terminées" icon="✅" tone="gr"><div style={{ fontSize: 24, color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>{ov.transactions.completed}</div></IntCard>
                <IntCard title="En cours" icon="⚙️" tone="g"><div style={{ fontSize: 24, color: 'var(--gold)', fontFamily: 'var(--font-mono)' }}>{ov.transactions.processing}</div></IntCard>
              </div>
              <div className="g3" style={{ marginTop: 12 }}>
                <IntCard title="Échouées" icon="❌" tone="v"><div style={{ fontSize: 22, color: ov.transactions.failed > 0 ? 'var(--red)' : 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{ov.transactions.failed}</div></IntCard>
                <IntCard title="Volume (XAF)" icon="💰" tone="c"><div style={{ fontSize: 22, fontFamily: 'var(--font-mono)' }}>{money(ov.transactions.volume_xaf)}</div></IntCard>
                <IntCard title="En attente" icon="⏳" tone="c"><div style={{ fontSize: 22, fontFamily: 'var(--font-mono)' }}>{ov.transactions.pending}</div></IntCard>
              </div>
            </div>

            <div>
              <div className="page-label" style={{ marginBottom: 10 }}>KYC & Providers</div>
              <div className="g2">
                <IntCard title="Dossiers KYC" icon="🛡️" tone="c"><div style={{ fontSize: 24, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{ov.kyc.total}</div><IntRow k="En attente" v={ov.kyc.pending} /><IntRow k="Validés" v={ov.kyc.approved} /><IntRow k="Rejetés" v={ov.kyc.rejected} /></IntCard>
                <IntCard title="Providers" icon="🔌" tone="c"><div style={{ fontSize: 24, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{ov.providers.total}</div><IntRow k="Configurés" v={ov.providers.configured} /></IntCard>
              </div>
            </div>
          </div>

          {/* ── Activité récente (audit) ── */}
          <div className="page-label" style={{ marginTop: 28, marginBottom: 10 }}>Activité récente (audit)</div>
          <div className="card" style={{ padding: 12 }}>
            {ov.recent_activity.length === 0 ? (
              <div style={{ fontSize: 12, color: 'var(--text-dim)', padding: 12 }}>Aucune activité enregistrée.</div>
            ) : (
              ov.recent_activity.map((a, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '8px 10px', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
                  <span style={{ color: 'var(--text-mid)', fontFamily: 'var(--font-mono)' }}>{a.action}</span>
                  <span style={{ color: 'var(--text-bright)', fontWeight: 600 }}>{a.count}</span>
                </div>
              ))
            )}
          </div>

          <div style={{ marginTop: 20, fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
            Connecté en tant que : {user?.name} · Rôle : {user?.platform_role} · Généré : {new Date(ov.generated_at).toLocaleString('fr-FR')}
          </div>
        </>
      )}
    </div>
  );
}
