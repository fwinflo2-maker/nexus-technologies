import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { apiBusinessOverview, type BusinessOverview } from '../../api/client';

import { useDashT } from '../../data/dashboard-i18n';
import { AnimatedNumber, EASE, RevealGroup, HoverCard, SectionTransition } from '../../components/anim/Premium';

/** Business Dashboard — Financial Operations Console (données 100 % backend). */
export default function BusinessDashboard() {
  const [data, setData] = useState<BusinessOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // Appelé inconditionnellement, avant tout retour anticipé (règles des Hooks) :
  // les branches loading/error/!data sortent plus bas et changeraient sinon
  // l'ordre des Hooks d'un rendu à l'autre.
  const td = useDashT();

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiBusinessOverview();
    if (!res.success || !res.data) {
      setError(res.error || 'Impossible de charger la console.');
      setLoading(false);
      return;
    }
    setData(res.data);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  if (loading) {
    return <div className="card card-hi-c" style={{ padding: 48, textAlign: 'center' }}><div className="nexus-spinner" /><p style={{ marginTop: 14, color: 'var(--text-mid)' }}>Chargement de la console financière…</p></div>;
  }
  if (error) {
    return <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><div style={{ fontSize: 30, marginBottom: 10 }}>⚠️</div><p style={{ color: 'var(--text-mid)', marginBottom: 14 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>;
  }
  if (!data) return null;

  const t = data.totals;
  const kpis: Array<{ label: string; value: number; suffix?: string; decimals?: number; color: string }> = [
    { label: td('biz.total_assets'), value: t.total_assets, color: 'var(--cyan)' },
    { label: td('biz.available'), value: t.available, color: 'var(--green)' },
    { label: td('biz.volume_30d'), value: t.volume_xaf, color: 'var(--violet)' },
    { label: td('biz.success_rate'), value: t.success_rate, suffix: ' %', decimals: 1, color: 'var(--green)' },
    { label: td('biz.fees_30d'), value: t.fees_ref, color: 'var(--text-mid)' },
  ];

  return (
    <SectionTransition id="biz-dash">
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">NEXUS · VOTRE ESPACE ENTREPRISE</div>
        <div className="page-title">Tableau de bord entreprise</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          Bienvenue sur votre espace client Nexus. Gérez vos paiements, vos bénéficiaires et votre activité en toute simplicité.
        </p>
      </motion.div>

      {/* Badge statut client */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 18, flexWrap: 'wrap' }}>
        <span className="pill p-g" style={{ fontSize: 10 }}>🏢 Client Nexus vérifié</span>
        <span className="pill p-c" style={{ fontSize: 10 }}>🔒 Compte entreprise sécurisé</span>
        <span className="pill" style={{ fontSize: 10 }}>🌍 Paiements multi-devises</span>
      </div>

      {/* KPIs essentiels */}
      <RevealGroup className="kpi-grid" stagger={0.05}>
        {kpis.map((kpi, i) => (
          <HoverCard key={kpi.label} className="card" index={i}
            style={{ padding: 18, position: 'relative', overflow: 'hidden' }}>
            <div style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: '0.06em', position: 'relative', zIndex: 1 }}>{kpi.label}</div>
            <div className="mono" style={{ fontSize: 22, fontWeight: 800, color: kpi.color, marginTop: 6, position: 'relative', zIndex: 1 }}>
              <AnimatedNumber value={kpi.value} suffix={kpi.suffix ?? ''} decimals={kpi.decimals ?? 0} />
            </div>
          </HoverCard>
        ))}
      </RevealGroup>

      {/* Soldes par devise */}
      <motion.div className="card" style={{ padding: 20, marginTop: 22, position: 'relative', overflow: 'hidden' }}
        initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15, duration: 0.55, ease: EASE }}>
        <div className="page-label" style={{ marginBottom: 12 }}>Soldes par devise</div>
        {data.wallets.length === 0 ? (
          <p style={{ color: 'var(--text-mid)', fontSize: 13 }}>{td('empty.noWallets')}</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {data.wallets.map((w, i) => (
              <motion.div key={w.currency} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid var(--border)' }}
                initial={{ opacity: 0, x: -16 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.2 + i * 0.06, duration: 0.4, ease: EASE }}>
                <div>
                  <div style={{ fontWeight: 700, color: 'var(--text-bright)', fontSize: 14 }}>{w.currency}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>disponible <AnimatedNumber value={w.available} /></div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div className="mono" style={{ fontWeight: 700, color: 'var(--text-bright)' }}><AnimatedNumber value={w.balance} /></div>
                  <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>≈ <AnimatedNumber value={w.ref_value} /> EUR</div>
                </div>
              </motion.div>
            ))}
          </div>
        )}
      </motion.div>

      {/* Actions rapides — opérations de l'entreprise */}
      <motion.div className="card" style={{ padding: 20, marginTop: 20, position: 'relative', overflow: 'hidden' }}
        initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.25, duration: 0.55, ease: EASE }}>
        <div className="page-label" style={{ marginBottom: 12 }}>Vos opérations</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
          {[
            { to: '/payments', icon: '↗', label: 'Nouveau paiement' },
            { to: '/history', icon: '🕘', label: 'Historique' },
            { to: '/approvals', icon: '✓', label: 'Approbations' },
            { to: '/beneficiaries', icon: '👥', label: 'Bénéficiaires' },
            { to: '/reconciliation', icon: '⇌', label: 'Rapprochement' },
            { to: '/team', icon: '⊕', label: 'Équipe' },
          ].map((a) => (
            <motion.div key={a.to} whileHover={{ y: -4, scale: 1.02 }} whileTap={{ scale: 0.97 }}
              transition={{ type: 'spring', stiffness: 260, damping: 18 }}>
              <Link to={a.to} style={{ textDecoration: 'none' }}>
                <div className="card" style={{ padding: 18, textAlign: 'center', cursor: 'pointer', borderColor: 'var(--border-soft)' }}>
                  <div style={{ fontSize: 22, marginBottom: 6 }}>{a.icon}</div>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-bright)' }}>{a.label}</div>
                </div>
              </Link>
            </motion.div>
          ))}
        </div>
      </motion.div>
    </div>
    </SectionTransition>
  );
}
