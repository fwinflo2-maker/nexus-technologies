import { useCallback, useEffect, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { apiControlAccess, apiStaffChats, type InternalAccess } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { ROLE_CATALOG } from '../admin/AdminEmployees';
import StaffDashboard from './StaffDashboard';
import InternalChat from './InternalChat';
import GearsBackground from '../../components/dashboard/GearsBackground';
import TechLoader from '../../components/anim/TechLoader';
import './StaffHome.css';

const DASHBOARD_LABELS: Record<string, string> = {
  executive: 'Direction',
  operations: 'Opérations',
  finance: 'Finance & trésorerie',
  compliance: 'Compliance',
  risk: 'Risque & fraude',
  providers: 'Providers',
  support: 'Support client',
  technical: 'Technique',
  business: 'Business',
};

const SURFACE_LABELS: Array<{ key: keyof InternalAccess['surfaces']; label: string }> = [
  { key: 'overview', label: 'Vue d’ensemble' },
  { key: 'providers', label: 'Providers' },
  { key: 'clients', label: 'Comptes clients' },
  { key: 'audit', label: 'Audit' },
  { key: 'kyc', label: 'KYC / KYB' },
  { key: 'maintenance', label: 'Maintenance' },
  { key: 'credentials', label: 'Credentials' },
];

type StaffView = 'console' | 'chat' | 'access';

export default function StaffHome() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [access, setAccess] = useState<InternalAccess | null>(null);
  const [failed, setFailed] = useState(false);
  const [view, setView] = useState<StaffView>('console');
  const [unreadTotal, setUnreadTotal] = useState(0);
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 30_000);
    return () => window.clearInterval(id);
  }, []);

  useEffect(() => {
    let alive = true;
    let delay = 8000;
    let timer = 0;
    const poll = async () => {
      const res = await apiStaffChats();
      if (!alive) return;
      if (res.success && res.data) {
        setUnreadTotal(res.data.items.reduce((a, c) => a + c.unread, 0));
        delay = 8000;
      } else {
        delay = Math.min(delay * 2, 60_000);
      }
      timer = window.setTimeout(poll, delay);
    };
    void poll();
    return () => { alive = false; window.clearTimeout(timer); };
  }, []);

  const loadAccess = useCallback(async () => {
    for (let i = 0; i < 3; i++) {
      const res = await apiControlAccess();
      if (res.success && res.data) {
        setAccess(res.data);
        setFailed(false);
        return;
      }
      await new Promise((r) => setTimeout(r, 800));
    }
    setFailed(true);
  }, []);

  useEffect(() => { void loadAccess(); }, [loadAccess]);

  if (user && (user.platform_role ?? 'user') === 'user') {
    return <Navigate to="/dashboard" replace />;
  }

  const role = user?.platform_role ?? 'user';
  const roleMeta = ROLE_CATALOG.find((r) => r.value === role);
  const dashboard = access?.dashboard;
  const surfaces = access?.surfaces;
  const timeStr = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  const firstName = user?.full_name.split(' ')[0] ?? user?.name ?? '';
  const consoleLabel = dashboard ? (DASHBOARD_LABELS[dashboard] ?? dashboard) : 'Console métier';

  const nav: Array<{ id: StaffView; label: string; badge?: number }> = [
    { id: 'console', label: 'Console métier' },
    { id: 'chat', label: 'Messagerie', badge: unreadTotal },
    { id: 'access', label: 'Accès RBAC' },
  ];

  return (
    <div className="nexus-dash layout staff-shell">
      <div className="dash-ambient-bg" aria-hidden="true" />
      <GearsBackground />
      <div className="bg-grid" />
      <div className="ambient-vignette" aria-hidden="true" />

      <aside className="staff-sidebar">
        <div className="staff-brand">
          <div className="staff-logo">N</div>
          <div>
            <div className="staff-brand-name">NEXUS</div>
            <div className="staff-brand-sub">Espace employé</div>
          </div>
        </div>

        <div className="staff-identity">
          <div className="staff-avatar">{user?.full_name.trim().charAt(0).toUpperCase() ?? '?'}</div>
          <div className="staff-identity-text">
            <div className="staff-identity-name">{user?.full_name}</div>
            <div className="staff-identity-role">{roleMeta?.label ?? role}</div>
          </div>
        </div>

        <nav className="staff-nav" aria-label="Espace employé">
          {nav.map((item) => (
            <button
              key={item.id}
              type="button"
              className={`staff-nav-item${view === item.id ? ' active' : ''}`}
              onClick={() => setView(item.id)}
            >
              <span>{item.label}</span>
              {item.badge ? <span className="staff-badge">{item.badge}</span> : null}
            </button>
          ))}
        </nav>

        <div className="staff-sidebar-foot">
          {user?.platform_role === 'superadmin' && (
            <>
              <Link to="/admin" className="staff-side-link">Console Super Admin</Link>
              <Link to="/send" className="staff-side-link">Espace client</Link>
            </>
          )}
          <button
            type="button"
            className="staff-side-link staff-logout"
            onClick={() => { void logout(); navigate('/staff-login', { replace: true }); }}
          >
            Déconnexion
          </button>
        </div>
      </aside>

      <div className="staff-main">
        <header className="staff-topbar">
          <div>
            <div className="staff-topbar-kicker">NEXUS · {roleMeta?.dept ?? 'Interne'}</div>
            <h1 className="staff-topbar-title">
              {view === 'console' && `Bonjour, ${firstName}`}
              {view === 'chat' && 'Messagerie interne'}
              {view === 'access' && 'Vos accès'}
            </h1>
            <p className="staff-topbar-sub">
              {view === 'console' && consoleLabel}
              {view === 'chat' && 'Conversations entre équipes Nexus'}
              {view === 'access' && 'Surfaces autorisées par votre rôle'}
            </p>
          </div>
          <div className="staff-topbar-meta">
            <span className="staff-clock">{timeStr}</span>
            <span className="staff-mail">{user?.email}</span>
          </div>
        </header>

        <div className="staff-body">
          {view === 'console' && <StaffDashboard />}

          {view === 'chat' && <InternalChat myId={Number(user?.id ?? 0)} />}

          {view === 'access' && (
            <div className="staff-access card">
              {!access && !failed && (
                <div className="staff-center"><TechLoader label="Chargement de vos accès" /></div>
              )}
              {failed && (
                <div className="staff-access-fail">
                  <span>Impossible de charger vos accès pour le moment.</span>
                  <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setFailed(false); void loadAccess(); }}>
                    Réessayer
                  </button>
                </div>
              )}
              {access && (
                <div className="staff-access-grid">
                  {SURFACE_LABELS.map((s) => {
                    const allowed = surfaces ? Boolean(surfaces[s.key]) : false;
                    return (
                      <div key={s.key} className={`staff-access-item${allowed ? ' on' : ''}`}>
                        <span>{s.label}</span>
                        <span className="staff-access-flag">{allowed ? 'Autorisé' : 'Restreint'}</span>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
