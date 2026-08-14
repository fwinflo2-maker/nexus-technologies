import { motion } from 'framer-motion';
import { useDashT } from '../../data/dashboard-i18n';

const ENGINES = [
  { name: 'Capability Engine', desc: 'Détermine ce que Nexus peut réellement exécuter (pays, devises, providers, KYC, limites).', color: 'var(--cyan)' },
  { name: 'Quote Engine', desc: 'Calcule les devis comparables : taux, frais, spread, montant reçu, ETA.', color: 'var(--violet)' },
  { name: 'Routing Engine', desc: 'Détermine la route optimale SOURCE → RAILS → DESTINATION (Optimized, Fastest, Cheapest, Max Received, Most Reliable).', color: 'var(--violet)' },
  { name: 'Policy Engine', desc: 'Applique la conformité : statut KYC, plafonds, sanctions — avant tout devis.', color: 'var(--gold)' },
  { name: 'Execution Engine', desc: 'Exécute la route en saga atomique (hold → capture → ledger), avec self-healing.', color: 'var(--green)' },
  { name: 'Ledger', desc: 'Comptabilité en partie double : aucun solde modifié sans écriture comptable.', color: 'var(--cyan)' },
];

/** Nexus AI — les moteurs déterministes du Core (aucune donnée fictive). */
export default function AgentsPage() {
  const t = useDashT();
  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('page.agents').toUpperCase()}</div>
        <div className="page-title">{t('page.agents')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)', maxWidth: 560 }}>
          L'intelligence de Nexus est déterministe et auditée : chaque moteur est un service backend
          (nexus-api/src/Services) connecté au ledger. Aucun agent ne prend de décision financière seul.
        </p>
      </motion.div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 16 }}>
        {ENGINES.map((e, i) => (
          <motion.div key={e.name} className="card" style={{ padding: 20 }} initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.05 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
              <span style={{ width: 10, height: 10, borderRadius: '50%', background: e.color, boxShadow: `0 0 12px ${e.color}` }} />
              <span style={{ fontWeight: 700, color: 'var(--text-bright)' }}>{e.name}</span>
            </div>
            <p style={{ fontSize: 12, color: 'var(--text-mid)', lineHeight: 1.6 }}>{e.desc}</p>
          </motion.div>
        ))}
      </div>
    </div>
  );
}
