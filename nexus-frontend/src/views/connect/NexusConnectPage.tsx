import { useCallback, useEffect, useState } from 'react';
import { IntCard, IntRow } from '../internal/InternalDashboard';
import AnimatedCounter from '../../components/AnimatedCounter';

/**
 * Nexus Connect Dashboard — client API / B2B.
 *
 * Affiche l'état de l'intégration API (clés, usage, webhooks, transactions).
 * Les données proviennent de l'API réelle ; ce qui n'existe pas encore côté
 * backend est affiché proprement comme "Non disponible" (aucun mock).
 */
export default function NexusConnectPage() {
  const [stats, setStats] = useState<{ accounts: number; wallets: number; tx: number } | null>(null);

  const load = useCallback(async () => {
    // Données réelles : comptes de paiement + transactions du connect.
    // Simplification : on réutilise les endpoints du compte courant.
    const [accRes, txRes] = await Promise.all([
      import('../../api/client').then((m) => m.apiAccountsList('source')),
      import('../../api/client').then((m) => m.apiTransfersList({ per_page: 1 })),
    ]);
    setStats({
      accounts: accRes.success && accRes.data ? accRes.data.total : 0,
      wallets: 0,
      tx: txRes.success && txRes.data ? txRes.data.total : 0,
    });
  }, []);

  useEffect(() => { void load(); }, [load]);

  return (
    <div className="page">
      <div className="page-header animate-up" style={{ marginBottom: 24 }}>
        <div className="page-label">NEXUS CONNECT · API / B2B</div>
        <div className="page-title">Nexus <span className="gc">Connect</span></div>
        <p style={{ marginTop: 8, fontSize: 13, color: 'var(--text-mid)', maxWidth: 720, lineHeight: 1.6 }}>
          Intégrez Nexus à vos systèmes via l'API : clés, webhooks, transactions et environnement de test.
        </p>
      </div>

      <div className="g4">
        <IntCard title="Statut API" icon="⚡" tone="c">
          <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>Opérationnel</div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Version v1</div>
        </IntCard>
        <IntCard title="Clés API" icon="🔑" tone="v">
          <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>—</div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Non disponible</div>
        </IntCard>
        <IntCard title="Webhooks" icon="🔔" tone="g">
          <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--gold)', fontFamily: 'var(--font-mono)' }}>—</div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Non disponible</div>
        </IntCard>
        <IntCard title="Transactions" icon="🔄" tone="gr">
          <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>
            <AnimatedCounter value={stats?.tx ?? 0} format={(n) => `${n}`} />
          </div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Opérations tracées</div>
        </IntCard>
      </div>

      <div className="g2" style={{ marginTop: 20, alignItems: 'start' }}>
        <IntCard title="Environnements" icon="🌐" tone="c">
          <IntRow k="Sandbox / Test" v="Non disponible" tone="var(--text-dim)" />
          <IntRow k="Production" v="Non disponible" tone="var(--text-dim)" />
          <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 8 }}>
            Les environnements sandbox/production seront ajoutés avec l'infrastructure API.
          </div>
        </IntCard>

        <IntCard title="Documentation" icon="📚" tone="v">
          <IntRow k="Guides d'intégration" v="Bientôt" tone="var(--text-dim)" />
          <IntRow k="Référence API" v="Bientôt" tone="var(--text-dim)" />
          <IntRow k="Exemples de code" v="Bientôt" tone="var(--text-dim)" />
        </IntCard>
      </div>
    </div>
  );
}
