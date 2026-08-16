import { useAuth } from '../../context/AuthContext';
import { Link, useNavigate } from 'react-router-dom';
import Avatar from '../../components/Avatar';
import { ParticlesBackground } from '../../components/ParticlesBackground';

/** Items de navigation du Super Admin (sidebar). */
export const ADMIN_NAV = [
  { id: 'overview', label: 'Vue d’ensemble', icon: '👑' },
  { id: 'send', label: 'Envoyer', icon: '↗️' },
  { id: 'wallet', label: 'Portefeuille', icon: '💼' },
  { id: 'clients', label: 'Gestion des clients', icon: '👥' },
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
  { id: 'account', label: 'Mon compte', icon: '👤' },
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
  const navigate = useNavigate();
  const now = new Date();
  const timeStr = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

  const handleLogout = async () => {
    await logout();
    navigate('/admin-login');
  };

  return (
    <div className="nexus-dash layout" style={{ minHeight: '100vh' }}>
      {/* Fond animé avec particules */}
      <ParticlesBackground density={35} color="#3B82F6" opacity={0.25} />
      <div className="dash-ambient-bg" aria-hidden="true" />
      <div className="bg-grid" />
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
          <Link to="/dashboard" style={{ fontSize: 12, color: 'var(--text-dim)', textDecoration: 'none', display: 'block', padding: '6px 12px' }}>
            ← Dashboard client
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
            <button onClick={() => { void handleLogout(); }} className="btn btn-ghost" style={{ fontSize: 11, padding: '6px 12px' }}>⏻</button>
          </div>
        </header>

        <div style={{ padding: '24px 26px', flex: 1 }}>{children}</div>
      </div>
    </div>
  );
}
