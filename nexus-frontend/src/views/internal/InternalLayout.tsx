import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useI18n } from '../../context/I18nContext';
import { useCtrlT } from '../../data/control-i18n';
import { apiControlAccess, type InternalAccess } from '../../api/client';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { INTERNAL_DASHBOARDS } from './InternalDashboards';
import Avatar from '../../components/Avatar';

/**
 * Coque des dashboards internes Nexus (RBAC).
 * Affiche la navigation en fonction des surfaces que le backend autorise.
 */
export default function InternalLayout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const { lang } = useI18n();
  const [access, setAccess] = useState<InternalAccess | null>(null);

  useEffect(() => {
    void apiControlAccess().then((r) => { if (r.success && r.data) setAccess(r.data); });
  }, []);

  const isRtl = lang === 'ar';
  const t = useCtrlT();
  const dashboard = access?.dashboard || user?.platform_role || '';

  // Surfaces visibles selon le rôle (garde côté client ; le serveur reste l'autorité).
  const navItems: Array<{ to: string; key: string; icon: string; show: boolean }> = [
    { to: '/internal', key: 'ctrl.nav.overview', icon: '👑', show: true },
    { to: '/internal/clients', key: 'ctrl.nav.clients', icon: '👥', show: access?.surfaces.clients ?? false },
    { to: '/control/providers', key: 'ctrl.nav.providers', icon: '🔌', show: access?.surfaces.providers ?? false },
    { to: '/internal/audit', key: 'ctrl.nav.audit', icon: '📜', show: access?.surfaces.audit ?? false },
    { to: '/internal/kyc', key: 'ctrl.nav.kyc', icon: '🛡️', show: access?.surfaces.kyc ?? false },
  ];

  return (
    <div dir={isRtl ? 'rtl' : 'ltr'} style={{ minHeight: '100vh', position: 'relative', background: '#070b12', color: '#e8f2f8', fontFamily: 'Inter, system-ui, sans-serif' }}>
      <div className="dash-ambient-bg" aria-hidden="true" style={{ position: 'fixed', inset: 0, pointerEvents: 'none', zIndex: 0 }} />
      <ParticlesBackground density={50} color="#00C8FF" opacity={0.45} className="dash-particles" />
      <div className="ambient-vignette" aria-hidden="true" style={{ position: 'fixed', inset: 0, pointerEvents: 'none', zIndex: 0 }} />

      <div style={{ position: 'relative', zIndex: 1, display: 'grid', gridTemplateColumns: 'minmax(200px, 240px) 1fr', minHeight: '100vh' }}>
        {/* Sidebar interne */}
        <aside style={{ borderRight: '1px solid rgba(120,150,190,0.16)', padding: '22px 14px', background: 'rgba(8,12,20,0.7)' }}>
          <div style={{ padding: '0 8px 18px' }}>
            <div style={{ fontSize: 15, fontWeight: 750, letterSpacing: 0.4 }}>NEXUS</div>
            <div style={{ fontSize: 11, color: '#00c8ff', letterSpacing: 1.4, textTransform: 'uppercase', marginTop: 3 }}>{t('ctrl.title')}</div>
            <div style={{ fontSize: 11, color: '#6a8ea8', marginTop: 8 }}>Dashboard : {INTERNAL_DASHBOARDS[dashboard]?.label ?? '—'}</div>
          </div>

          <nav style={{ display: 'grid', gap: 2 }}>
            {navItems.filter((n) => n.show).map((n) => (
              <Link key={n.to} to={n.to}
                style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px', borderRadius: 8, fontSize: 13, color: '#b8d0e0', textDecoration: 'none' }}>
                <span>{n.icon}</span>
                <span>{t(n.key)}</span>
              </Link>
            ))}
          </nav>

          <div style={{ marginTop: 26, padding: '0 8px' }}>
            <Link to="/dashboard" style={{ fontSize: 12, color: '#6a8ea8', textDecoration: 'none' }}>← {t('ctrl.back')}</Link>
          </div>
        </aside>

        {/* Contenu */}
        <main style={{ padding: '24px 26px 60px', minWidth: 0 }}>
          {/* Topbar interne */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, marginBottom: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
              <Avatar avatar={user?.avatar} accountType="business" size={34} />
              <div>
                <div style={{ fontWeight: 700, fontSize: 14 }}>{user?.name || 'Nexus'}</div>
                <div style={{ fontSize: 11, color: '#00c8ff' }}>{dashboard} · {user?.platform_role}</div>
              </div>
            </div>
            <button onClick={async () => { await logout(); }} style={{ padding: '6px 12px', borderRadius: 8, border: '1px solid rgba(120,150,190,0.3)', background: 'transparent', color: '#6a8ea8', fontSize: 11, cursor: 'pointer' }}>
              ⏻ Déconnexion
            </button>
          </div>
          {children}
        </main>
      </div>
    </div>
  );
}
