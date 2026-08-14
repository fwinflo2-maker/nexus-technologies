import { useEffect, useRef, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

type Mode = 'personal' | 'business';

interface SidebarProps {
  mode: Mode;
  onModeChange: (m: Mode) => void;
}

const navPersonal = [
  { to: '/dashboard', icon: '◈', label: 'Tableau de bord' },
  { to: '/wallet', icon: '◉', label: 'Portefeuille' },
  { to: '/send', icon: '↗', label: 'Envoyer' },
  { to: '/receive', icon: '↙', label: 'Recevoir' },
  { to: '/convert', icon: '⇄', label: 'Convertir' },
  { to: '/history', icon: '≡', label: 'Historique' },
];

const navBusiness = [
  { to: '/dashboard', icon: '◈', label: 'Tableau de bord' },
  { to: '/treasury', icon: '◉', label: 'Trésorerie' },
  { to: '/payments', icon: '↗', label: 'Paiements' },
  { to: '/approvals', icon: '✓', label: 'Approbations' },
  { to: '/beneficiaries', icon: '👥', label: 'Bénéficiaires' },
  { to: '/reconciliation', icon: '⇌', label: 'Rapprochement' },
  { to: '/team', icon: '⊕', label: 'Équipe & Rôles' },
  { to: '/reporting', icon: '≡', label: 'Reporting' },
];

// NB: /providers est une fonctionnalité du Back Office, absente des dashboards personal/business.
const navCommon = [
  { to: '/notifications', icon: '🔔', label: 'Notifications' },
  { to: '/agents', icon: '⚙', label: 'Agents IA' },
  { to: '/settings', icon: '⚙', label: 'Paramètres' },
];

/**
 * Sidebar NEXUS — navigation verticale à gauche sur desktop.
 * Sur mobile, elle se replie en tiroir ouvert par le bouton « ☰ »
 * (clavier accessible : Echap pour fermer, focus sur le premier lien).
 */
export default function Sidebar({ mode, onModeChange }: SidebarProps) {
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const drawerRef = useRef<HTMLElement>(null);
  const toggleRef = useRef<HTMLButtonElement>(null);

  const isPersonalOnly = user?.account_type === 'personal';
  const effectiveMode = isPersonalOnly ? 'personal' : mode;
  const nav = effectiveMode === 'personal' ? navPersonal : navBusiness;

  const closeMenu = () => setMenuOpen(false);

  // Fermeture du tiroir mobile : clic extérieur ou touche Echap.
  useEffect(() => {
    if (!menuOpen) return;
    const onDown = (e: MouseEvent) => {
      if (
        drawerRef.current &&
        !drawerRef.current.contains(e.target as Node) &&
        toggleRef.current &&
        !toggleRef.current.contains(e.target as Node)
      ) {
        closeMenu();
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') closeMenu();
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [menuOpen]);

  // Accessibilité clavier : focus sur le premier lien à l'ouverture du tiroir.
  useEffect(() => {
    if (menuOpen) {
      const first = drawerRef.current?.querySelector<HTMLElement>('a, button');
      first?.focus();
    }
  }, [menuOpen]);

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  const handleModeChange = (m: Mode) => {
    // Un compte personal n'a pas le droit d'accéder au mode Business.
    if (isPersonalOnly && m === 'business') {
      return;
    }
    onModeChange(m);
    closeMenu();
    navigate('/dashboard');
  };

  return (
    <>
      {/* Barre mobile : logo + bouton ☰ */}
      <header className="sidebar-mobilebar">
        <NavLink to="/dashboard" className="navbar-logo" aria-label="NEXUS — Tableau de bord" onClick={closeMenu}>
          <div className="logo-box">N</div>
          <div className="logo-txt">
            NEXUS
            <br />
            CORP
          </div>
        </NavLink>
        <button
          ref={toggleRef}
          className="sidebar-hamburger"
          onClick={() => setMenuOpen((v) => !v)}
          aria-label={menuOpen ? 'Fermer le menu' : 'Ouvrir le menu'}
          aria-expanded={menuOpen}
          aria-controls="sidebar-drawer"
        >
          {menuOpen ? '✕' : '☰'}
        </button>
      </header>

      {/* Fond assombri (mobile uniquement) */}
      {menuOpen && <div className="sidebar-scrim" onClick={closeMenu} aria-hidden />}

      <aside id="sidebar-drawer" ref={drawerRef} className={`sidebar ${menuOpen ? 'open' : ''}`}>
        <div className="sidebar-logo">
          <div className="logo-box">N</div>
          <div className="logo-txt">
            NEXUS
            <br />
            CORP
          </div>
        </div>

        {/* Mode du compte */}
        <div className="sidebar-mode">
          {isPersonalOnly ? (
            <div className="pill p-c" style={{ width: '100%', justifyContent: 'center', padding: '6px 0', fontSize: 9 }}>
              COMPTE PERSONNEL
            </div>
          ) : (
            <>
              <div className="account-tabs">
                <button
                  className={`account-tab ${effectiveMode === 'personal' ? 'active-personal' : ''}`}
                  onClick={() => handleModeChange('personal')}
                >P</button>
                <button
                  className={`account-tab ${effectiveMode === 'business' ? 'active-business' : ''}`}
                  onClick={() => handleModeChange('business')}
                >B</button>
              </div>
              <div style={{ fontSize: 9, color: 'var(--text-dim)', textAlign: 'center', marginTop: 5, letterSpacing: '0.1em' }}>
                {effectiveMode === 'personal' ? 'PERSONNEL' : 'BUSINESS'}
              </div>
            </>
          )}
        </div>

        <nav className="sidebar-nav" aria-label="Navigation principale">
          <div className="nav-section-label">{effectiveMode === 'personal' ? 'Personnel' : 'Business'}</div>
          {nav.map((item) => (
            <NavLink
              key={item.to + item.label}
              to={item.to}
              end={item.to === '/dashboard'}
              onClick={closeMenu}
              className={({ isActive }) =>
                `nav-item ${isActive ? (effectiveMode === 'business' ? 'active-gold' : 'active') : ''}`
              }
            >
              <span className="nav-icon" aria-hidden>{item.icon}</span>
              <span>{item.label}</span>
            </NavLink>
          ))}

          <div className="nav-section-label" style={{ marginTop: 8 }}>Système</div>
          {navCommon.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end
              onClick={closeMenu}
              className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
            >
              <span className="nav-icon" aria-hidden>{item.icon}</span>
              <span>{item.label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-footer">
          <div className="sidebar-user">
            <div className="nb-user-avatar">{effectiveMode === 'personal' ? '👤' : '🏢'}</div>
            <div>
              <div className="nb-user-name">{user?.name || 'Utilisateur'}</div>
              <div className="nb-user-verified">● Compte vérifié</div>
            </div>
          </div>
          <button
            className="nb-logout"
            onClick={handleLogout}
          >
            ⏻ Déconnexion
          </button>
        </div>
      </aside>
    </>
  );
}
