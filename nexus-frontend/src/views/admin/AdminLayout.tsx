import { useAuth } from '../../context/AuthContext';
import { Link } from 'react-router-dom';
import Avatar from '../../components/Avatar';
import { ParticlesBackground } from '../../components/ParticlesBackground';

/** Items de navigation du Super Admin (sidebar). */
export const ADMIN_NAV = [
  { id: 'overview', label: 'Vue d’ensemble', icon: '👑' },
  { id: 'send', label: 'Envoyer', icon: '↗️' },
  { id: 'wallet', label: 'Portefeuille', icon: '💼' },
  { id: 'accounts', label: 'Comptes', icon: '👥' },
  { id: 'transactions', label: 'Transactions', icon: '🔄' },
  { id: 'operations', label: 'Opérations', icon: '⚙️' },
  { id: 'treasury', label: 'Trésorerie', icon: '💰' },
  { id: 'compliance', label: 'Compliance / KYC', icon: '🛡️' },
  { id: 'risk', label: 'Risque / Fraude', icon: '📊' },
  { id: 'providers', label: 'Providers', icon: '🔌' },
  { id: 'support', label: 'Support', icon: '🧑‍💻' },
  { id: 'security', label: 'Sécurité', icon: '🔐' },
  { id: 'technical', label: 'Technique', icon: '🛠️' },
  { id: 'audit', label: 'Audit', icon: '📜' },
  { id: 'settings', label: 'Paramètres', icon: '⚙️' },
];

export default function AdminLayout({
  active,
  onNavigate,
  children,
}: {
  active: string;
  onNavigate: (id: string) => void;
  children: React.ReactNode;
}) {
  const { user, logout } = useAuth();
  const now = new Date();
  const timeStr = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

  return (
    <div className="nexus-dash layout" style={{ minHeight: '100vh' }}>
      {/* Fond */}
      <div className="dash-ambient-bg" aria-hidden="true" />
      <div className="bg-grid" />
      <ParticlesBackground density={45} color="#3B82F6" opacity={0.45} className="dash-particles" />
      <div className="ambient-vignette" aria-hidden="true" />

      {/* Sidebar */}
      <aside className="sidebar" style={{ width: 230 }}>
        <div className="sidebar-logo" style={{ padding: '18px 16px 16px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div className="logo-box" style={{ width: 34, height: 34, borderRadius: 10, background: 'linear-gradient(135deg,#2563EB,#3B82F6)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 800 }}>N</div>
            <div style={{ lineHeight: 1.1 }}>
              <div style={{ fontWeight: 800, color: 'var(--text-bright)', letterSpacing: 0.5 }}>NEXUS</div>
              <div style={{ fontSize: 10, color: '#3B82F6', letterSpacing: 1, textTransform: 'uppercase' }}>Super Admin</div>
            </div>
          </div>
        </div>

        <nav style={{ padding: '0 10px', display: 'flex', flexDirection: 'column', gap: 2 }}>
          {ADMIN_NAV.map((item) => (
            <button
              key={item.id}
              onClick={() => onNavigate(item.id)}
              style={{
                display: 'flex', alignItems: 'center', gap: 10, padding: '9px 12px', borderRadius: 8,
                fontSize: 13, cursor: 'pointer', textAlign: 'left',
                background: active === item.id ? 'rgba(59,130,246,0.12)' : 'transparent',
                color: active === item.id ? '#fff' : 'var(--text-mid)',
                border: active === item.id ? '1px solid rgba(59,130,246,0.3)' : '1px solid transparent',
                fontWeight: active === item.id ? 600 : 450,
              }}
            >
              <span>{item.icon}</span>
              <span>{item.label}</span>
            </button>
          ))}
        </nav>

        <div style={{ marginTop: 'auto', padding: 16 }}>
          <Link to="/wallet" style={{ fontSize: 12, color: 'var(--text-dim)', textDecoration: 'none', display: 'block', padding: '6px 12px' }}>
            ← Portefeuille
          </Link>
        </div>
      </aside>

      {/* Main */}
      <div className="main-content" style={{ marginLeft: 230, display: 'flex', flexDirection: 'column', minHeight: '100vh' }}>
        {/* Topbar */}
        <header className="topbar" style={{ position: 'sticky', top: 0, zIndex: 10, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 22px', borderBottom: '1px solid var(--border)', background: 'rgba(11,13,16,0.85)', backdropFilter: 'blur(8px)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-bright)' }}>Nexus · Super Admin</div>
            <span style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{timeStr}</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <Avatar avatar={user?.avatar} accountType="business" size={30} />
            <div style={{ lineHeight: 1.2 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-bright)' }}>{user?.name || 'Admin'}</div>
              <div style={{ fontSize: 10, color: '#3B82F6' }}>superadmin</div>
            </div>
            <button
              type="button"
              onClick={() => { void logout('/admin-login'); }}
              className="btn btn-ghost admin-logout-btn"
              aria-label="Déconnexion"
              title="Déconnexion"
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: 34,
                height: 34,
                padding: 0,
                borderRadius: 8,
                color: 'var(--text-dim)',
              }}
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <polyline points="16 17 21 12 16 7" />
                <line x1="21" y1="12" x2="9" y2="12" />
              </svg>
            </button>
          </div>
        </header>

        <div style={{ padding: '24px 26px', flex: 1 }}>{children}</div>
      </div>
    </div>
  );
}
