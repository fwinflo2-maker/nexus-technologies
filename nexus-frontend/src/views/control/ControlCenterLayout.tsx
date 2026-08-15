/**
 * §2 / §9 / §27 / §36 — Coque du NEXUS CONTROL CENTER.
 *
 * Navigation latérale, sélecteur d'environnement global, garde d'accès.
 *
 * La garde côté client n'est PAS une sécurité : le backend refuse déjà en 403
 * tout appel non habilité. Elle évite simplement d'afficher une coquille vide.
 */
import type { ReactNode } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useI18n } from '../../context/I18nContext';
import { useCtrlT } from '../../data/control-i18n';
import { C, Note } from './ui';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import ControlOverviewPage from './ControlOverviewPage';
import ProviderNetworkPage from './ProviderNetworkPage';
import ProviderDetailPage from './ProviderDetailPage';
import PublicKeyCenterPage from './PublicKeyCenterPage';
import ControlAuditPage from './ControlAuditPage';

interface NavItem { to: string; key: string; }

const NAV: NavItem[] = [
  { to: '/control', key: 'ctrl.nav.overview' },
  { to: '/control/providers', key: 'ctrl.nav.providers' },
  { to: '/control/keys', key: 'ctrl.nav.keys' },
  { to: '/control/audit', key: 'ctrl.nav.audit' },
];

export default function ControlCenterLayout() {
  const t = useCtrlT();
  const { lang } = useI18n();
  const { user } = useAuth();
  const isRtl = lang === 'ar';

  // Le serveur reste l'autorité : ceci n'évite qu'un écran vide.
  if (user && user.account_type !== 'business') {
    return (
      <div style={{ padding: 40, background: C.bg, minHeight: '100vh' }}>
        <Note tone="warn">{t('ctrl.st.forbidden')}</Note>
      </div>
    );
  }

  return (
    <div dir={isRtl ? 'rtl' : 'ltr'} style={{ minHeight: '100vh', position: 'relative', background: C.bg }}>
      {/* Fond bleu animé + particules (même ambiance que les dashboards) */}
      <div className="dash-ambient-bg" aria-hidden="true" style={{ position: 'fixed', inset: 0, pointerEvents: 'none', zIndex: 0 }} />
      <div className="ambient" aria-hidden="true" style={{ position: 'fixed', inset: 0, pointerEvents: 'none', zIndex: 0, overflow: 'hidden' }}>
        <div className="orb" style={{ width: 520, height: 520, top: '-12%', left: '2%', background: 'radial-gradient(circle, rgba(0,140,255,0.14), transparent 70%)' }} />
        <div className="orb" style={{ width: 420, height: 420, bottom: '2%', right: '6%', background: 'radial-gradient(circle, rgba(0,200,255,0.10), transparent 70%)' }} />
        <div className="orb" style={{ width: 300, height: 300, top: '42%', left: '48%', background: 'radial-gradient(circle, rgba(120,120,255,0.06), transparent 70%)' }} />
      </div>
      <ParticlesBackground density={55} color="#00C8FF" opacity={0.5} className="dash-particles" />
      <div className="ambient-vignette" aria-hidden="true" style={{ position: 'fixed', inset: 0, pointerEvents: 'none', zIndex: 0 }} />

      <div
      style={{
        position: 'relative',
        zIndex: 1,
        display: 'grid',
        gridTemplateColumns: 'minmax(180px, 218px) 1fr',
        minHeight: '100vh',
        color: C.text,
        fontFamily: 'Inter, system-ui, -apple-system, sans-serif',
      }}
    >
      <aside style={{
        borderInlineEnd: `1px solid ${C.border}`,
        padding: '22px 14px',
        background: 'rgba(8,12,20,0.7)',
      }}>
        <div style={{ padding: '0 8px 20px' }}>
          <div style={{ fontSize: 15, fontWeight: 750, letterSpacing: 0.4 }}>NEXUS</div>
          <div style={{ fontSize: 11, color: C.accent, letterSpacing: 1.4, textTransform: 'uppercase', marginTop: 3 }}>
            {t('ctrl.title')}
          </div>
          <div style={{ fontSize: 11, color: C.dim, marginTop: 8, lineHeight: 1.4 }}>{t('ctrl.subtitle')}</div>
        </div>

        <nav style={{ display: 'grid', gap: 2 }}>
          {NAV.map((n) => (
            <NavLink
              key={n.to}
              to={n.to}
              end={n.to === '/control'}
              style={({ isActive }) => ({
                display: 'block',
                padding: '9px 12px',
                borderRadius: 8,
                fontSize: 13,
                textDecoration: 'none',
                color: isActive ? C.accent : C.dim,
                background: isActive ? 'rgba(0,200,255,0.08)' : 'transparent',
                fontWeight: isActive ? 600 : 500,
              })}
            >
              {t(n.key)}
            </NavLink>
          ))}
        </nav>

        <div style={{ marginTop: 26, padding: '0 8px' }}>
          <NavLink to="/dashboard" style={{ fontSize: 12, color: C.dim, textDecoration: 'none' }}>
            ← {t('ctrl.back')}
          </NavLink>
        </div>
      </aside>

      <main style={{ padding: '24px 26px 60px', minWidth: 0 }}>
        <Routes>
          <Route path="/" element={<Frame><ControlOverviewPage /></Frame>} />
          <Route path="/providers" element={<Frame><ProviderNetworkPage /></Frame>} />
          <Route path="/provider/:slug" element={<Frame><ProviderDetailPage /></Frame>} />
          <Route path="/keys" element={<Frame><PublicKeyCenterPage /></Frame>} />
          <Route path="/audit" element={<Frame><ControlAuditPage /></Frame>} />
          <Route path="*" element={<Navigate to="/control" replace />} />
        </Routes>
      </main>
      </div>
    </div>
  );
}

function Frame({ children }: { children: ReactNode }) {
  return <div style={{ maxWidth: 1180 }}>{children}</div>;
}
