import { useEffect, useRef, useState } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useDashT } from '../../data/dashboard-i18n';
import Avatar from '../Avatar';

type Mode = 'personal' | 'business';

interface SidebarProps {
  mode: Mode;
}

const navPersonal = [
  { to: '/dashboard', icon: '◈', label: 'nav.dashboard' },
  { to: '/wallet', icon: '◉', label: 'nav.wallet' },
  { to: '/send', icon: '↗', label: 'nav.send' },
  { to: '/receive', icon: '↙', label: 'nav.receive' },
  { to: '/convert', icon: '⇄', label: 'nav.convert' },
  { to: '/history', icon: '≡', label: 'nav.history' },
];

const navBusiness = [
  { to: '/dashboard', icon: '◈', label: 'nav.dashboard' },
  { to: '/payments', icon: '↗', label: 'nav.payments' },
  { to: '/history', icon: '🕘', label: 'nav.history' },
  { to: '/approvals', icon: '✓', label: 'nav.approvals' },
  { to: '/beneficiaries', icon: '👥', label: 'nav.beneficiaries' },
  { to: '/reconciliation', icon: '⇌', label: 'nav.reconciliation' },
  { to: '/team', icon: '⊕', label: 'nav.team' },
];

// NB: /providers est une fonctionnalité du Back Office, absente des dashboards personal/business.
const navCommon = [
  { to: '/notifications', icon: '🔔', label: 'nav.notifications' },
  { to: '/agents', icon: '⚙', label: 'nav.agents' },
  { to: '/settings', icon: '⚙', label: 'nav.settings' },
];

/**
 * Sidebar NEXUS — navigation verticale à gauche sur desktop.
 * Sur mobile, elle se replie en tiroir ouvert par le bouton « ☰ »
 * (clavier accessible : Echap pour fermer, focus sur le premier lien).
 */
export default function Sidebar({ mode }: SidebarProps) {
  const { user, logout } = useAuth();
  const t = useDashT();
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
    // logout() (AuthContext) révogue la session et redirige vers /login.
    await logout();
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

        {/* Mode du compte — fixe selon le type de compte (pas de bascule P/B) */}
        <div className="sidebar-mode">
          <div
            className={`pill ${isPersonalOnly ? 'p-c' : 'p-g'}`}
            style={{ width: '100%', justifyContent: 'center', padding: '6px 0', fontSize: 9 }}
          >
            {isPersonalOnly ? 'COMPTE PERSONNEL' : 'COMPTE BUSINESS'}
          </div>
        </div>

        <nav className="sidebar-nav" aria-label="Navigation principale">
          <div className="nav-section-label">{effectiveMode === 'personal' ? t('nav.personal') : t('nav.business')}</div>
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
              <span>{t(item.label)}</span>
            </NavLink>
          ))}

          <div className="nav-section-label" style={{ marginTop: 8 }}>{t('nav.system')}</div>
          {navCommon.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end
              onClick={closeMenu}
              className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
            >
              <span className="nav-icon" aria-hidden>{item.icon}</span>
              <span>{t(item.label)}</span>
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-footer">
          <div className="sidebar-user">
            <Avatar avatar={user?.avatar} accountType={user?.account_type} size={34} className="nb-user-avatar" />
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
