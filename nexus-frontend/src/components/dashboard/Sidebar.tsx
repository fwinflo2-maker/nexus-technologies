import { useEffect, useRef, useState } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useDashT } from '../../data/dashboard-i18n';
import { isAccountVerified } from '../../lib/accountStatus';
import Avatar from '../Avatar';

type Mode = 'personal' | 'business' | 'admin';

interface SidebarProps {
  mode: Mode;
}

const navPersonal = [
  { to: '/dashboard', icon: '◈', label: 'nav.dashboard' },
  { to: '/wallet', icon: '◉', label: 'nav.wallet' },
  { to: '/cards', icon: '💳', label: 'nav.cards' },
  { to: '/send', icon: '↗', label: 'nav.send' },
  { to: '/receive', icon: '↙', label: 'nav.receive' },
  { to: '/convert', icon: '⇄', label: 'nav.convert' },
  { to: '/history', icon: '≡', label: 'nav.history' },
];

const navBusiness = [
  { to: '/dashboard', icon: '◈', label: 'nav.dashboard' },
  { to: '/wallet', icon: '◉', label: 'nav.wallet' },
  { to: '/cards', icon: '💳', label: 'nav.cards' },
  { to: '/treasury', icon: '▣', label: 'nav.treasury' },
  { to: '/send', icon: '↗', label: 'nav.send' },
  { to: '/receive', icon: '↙', label: 'nav.receive' },
  { to: '/convert', icon: '⇄', label: 'nav.convert' },
  { to: '/payments', icon: '↗', label: 'nav.payments' },
  { to: '/history', icon: '🕘', label: 'nav.history' },
  { to: '/approvals', icon: '✓', label: 'nav.approvals' },
  { to: '/beneficiaries', icon: '👥', label: 'nav.beneficiaries' },
  { to: '/reconciliation', icon: '⇌', label: 'nav.reconciliation' },
  { to: '/reporting', icon: '≡', label: 'nav.reporting' },
  { to: '/team', icon: '⊕', label: 'nav.team' },
  { to: '/kyc', icon: '✓', label: 'nav.kyc' },
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

  const isSuperAdmin = user?.platform_role === 'superadmin';
  const isPersonalOnly = user?.account_type === 'personal';
  // Super Admin : espace client sous identité « Super Admin », jamais
  // « Compte personnel » — il opère son propre wallet sans restriction.
  const effectiveMode = isSuperAdmin ? 'admin' : (isPersonalOnly ? 'personal' : mode);
  const nav = effectiveMode === 'business' ? navBusiness : navPersonal;

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
    await logout('/login');
  };



  return (
    <>
      {/* Barre mobile : logo + bouton ☰ */}
      <header className="sidebar-mobilebar">
        <NavLink to="/dashboard" className="navbar-logo" aria-label={t('sidebar.logo.aria')} onClick={closeMenu}>
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
          aria-label={menuOpen ? t('sidebar.menu.close') : t('sidebar.menu.open')}
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

        {/* Mode du compte — fixe selon le type de compte (pas de bascule P/B).
            Super Admin : identité propre, jamais « Compte personnel ». */}
        <div className="sidebar-mode">
          {isSuperAdmin ? (
            <div
              className="pill p-b"
              style={{ width: '100%', justifyContent: 'center', padding: '6px 0', fontSize: 9 }}
            >
              👑 {t('sidebar.account.admin')}
            </div>
          ) : (
            <div
              className={`pill ${isPersonalOnly ? 'p-c' : 'p-g'}`}
              style={{ width: '100%', justifyContent: 'center', padding: '6px 0', fontSize: 9 }}
            >
              {isPersonalOnly ? t('sidebar.account.personal') : t('sidebar.account.business')}
            </div>
          )}
        </div>

        <nav className="sidebar-nav" aria-label={t('sidebar.nav.aria')}>
          <div className="nav-section-label">{effectiveMode === 'personal' ? t('nav.personal') : effectiveMode === 'admin' ? t('nav.admin') : t('nav.business')}</div>
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
              <div className="nb-user-name">{user?.name || t('common.userFallback')}</div>
              <div className={isAccountVerified(user) ? 'nb-user-verified' : 'nb-user-unverified'}>
                ● {isAccountVerified(user) ? t('common.verified') : t('common.verificationRequired')}
              </div>
            </div>
          </div>
          <button
            className="nb-logout"
            onClick={handleLogout}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}
          >
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            {t('common.logout')}
          </button>
        </div>
      </aside>
    </>
  );
}
