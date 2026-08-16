import { useEffect, useRef, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useDashT } from '../../data/dashboard-i18n';
import Avatar from '../Avatar';

type Mode = 'personal' | 'business';

interface NavbarProps {
  mode: Mode;
  onModeChange: (m: Mode) => void;
}

interface NavItem {
  to: string;
  icon: string;
  label: string;
  end?: boolean;
}

/* ── Gestion du compte ── */
const navAccountPersonal: NavItem[] = [
  { to: '/dashboard', icon: '◈', label: 'nav.dashboard', end: true },
  { to: '/wallet', icon: '◉', label: 'nav.wallet' },
  { to: '/history', icon: '↻', label: 'nav.history' },
];

const navAccountBusiness: NavItem[] = [
  { to: '/dashboard', icon: '◈', label: 'nav.dashboard', end: true },
  { to: '/treasury', icon: '◉', label: 'nav.treasury' },
  { to: '/payments', icon: '↗', label: 'nav.payments' },
  { to: '/approvals', icon: '✓', label: 'nav.approvals' },
  { to: '/beneficiaries', icon: '👥', label: 'nav.beneficiaries' },
  { to: '/reconciliation', icon: '⇌', label: 'nav.reconciliation' },
  { to: '/team', icon: '⊕', label: 'nav.team' },
  { to: '/reporting', icon: '≡', label: 'nav.reporting' },
];

/* ── Actions financières (uniquement ce qui existe réellement) ── */
const navAction: NavItem[] = [{ to: '/send', icon: '↗', label: 'nav.send' }];

/* ── Système ── */
const navSystem: NavItem[] = [
  { to: '/kyc', icon: '⊗', label: 'nav.kyc' },
  { to: '/agents', icon: '⚙', label: 'nav.agents' },
];

/**
 * Navbar NEXUS — remplace la Sidebar.
 *
 * Barre horizontale fixe en haut : logo, navigation principale, action
 * financière (Envoyer) et navigation système. Sur mobile, un bouton « ☰ »
 * ouvre un tiroir de navigation complet (clavier accessible : Echap pour
 * fermer, focus sur le premier lien à l'ouverture).
 */
export default function Navbar({ mode, onModeChange }: NavbarProps) {
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const t = useDashT();
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const toggleRef = useRef<HTMLButtonElement>(null);

  const isPersonalOnly = user?.account_type === 'personal';
  const effectiveMode = isPersonalOnly ? 'personal' : mode;
  const navMain = effectiveMode === 'personal' ? navAccountPersonal : navAccountBusiness;

  // Fermeture du tiroir : clic extérieur ou touche Echap.
  useEffect(() => {
    if (!menuOpen) return;
    const onDown = (e: MouseEvent) => {
      if (
        menuRef.current &&
        !menuRef.current.contains(e.target as Node) &&
        toggleRef.current &&
        !toggleRef.current.contains(e.target as Node)
      ) {
        setMenuOpen(false);
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMenuOpen(false);
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
      const first = menuRef.current?.querySelector<HTMLElement>('a, button');
      first?.focus();
    }
  }, [menuOpen]);

  const handleLogout = async () => {
    // logout() (AuthContext) révogue la session et redirige vers /login.
    await logout();
  };

  const handleModeChange = (m: Mode) => {
    // Un compte personal n'a pas le droit d'accéder au mode Business.
    if (isPersonalOnly && m === 'business') {
      return;
    }
    onModeChange(m);
    setMenuOpen(false);
    navigate('/dashboard');
  };

  const renderLink = (item: NavItem, options?: { action?: boolean; fullWidth?: boolean }) => (
    <NavLink
      key={`${item.to}-${item.label}`}
      to={item.to}
      end={item.end}
      onClick={() => setMenuOpen(false)}
      aria-label={item.label}
      title={item.label}
      className={({ isActive }) =>
        [
          'nb-link',
          options?.fullWidth ? 'nb-drawer-link' : '',
          options?.action ? 'nb-action' : '',
          isActive ? (effectiveMode === 'business' ? 'nb-link-active-gold' : 'nb-link-active') : '',
        ]
          .filter(Boolean)
          .join(' ')
      }
    >
      <span className="nb-icon" aria-hidden>
        {item.icon}
      </span>
      <span className="nb-label">{t(item.label)}</span>
    </NavLink>
  );

  return (
    <header className="navbar">
      <div className="navbar-inner">
        {/* Logo / identité */}
        <NavLink to="/dashboard" className="navbar-logo" aria-label="NEXUS — Tableau de bord">
          <div className="logo-box">N</div>
          <div className="logo-txt">
            NEXUS
            <br />
            CORP
          </div>
        </NavLink>

        {/* Navigation desktop / tablet */}
        <nav className="nb-groups" aria-label="Navigation principale">
          <div className="nb-group">{navMain.map((i) => renderLink(i))}</div>
          <div className="nb-divider" aria-hidden />
          <div className="nb-group">{navAction.map((i) => renderLink(i, { action: true }))}</div>
          <div className="nb-divider" aria-hidden />
          <div className="nb-group">{navSystem.map((i) => renderLink(i))}</div>
        </nav>

        <div className="navbar-right">
          {isPersonalOnly ? (
            <div className="pill p-c">COMPTE PERSONNEL</div>
          ) : (
            <div className="account-tabs" role="group" aria-label="Espace du compte">
              <button
                className={`account-tab ${effectiveMode === 'personal' ? 'active-personal' : ''}`}
                onClick={() => handleModeChange('personal')}
                aria-pressed={effectiveMode === 'personal'}
              >
                P
              </button>
              <button
                className={`account-tab ${effectiveMode === 'business' ? 'active-business' : ''}`}
                onClick={() => handleModeChange('business')}
                aria-pressed={effectiveMode === 'business'}
              >
                B
              </button>
            </div>
          )}

          {/* Bouton menu mobile */}
          <button
            ref={toggleRef}
            className="nb-hamburger"
            onClick={() => setMenuOpen((v) => !v)}
            aria-label={menuOpen ? 'Fermer le menu' : 'Ouvrir le menu'}
            aria-expanded={menuOpen}
            aria-controls="nb-drawer"
          >
            {menuOpen ? '✕' : '☰'}
          </button>
        </div>
      </div>

      {/* Tiroir mobile */}
      {menuOpen && (
        <div className="nb-drawer" id="nb-drawer" ref={menuRef} role="dialog" aria-label="Menu de navigation">
          <div className="nav-section-label">Navigation</div>
          <div className="nb-drawer-group">{navMain.map((i) => renderLink(i, { fullWidth: true }))}</div>

          <div className="nav-section-label" style={{ marginTop: 12 }}>Actions</div>
          <div className="nb-drawer-group">{navAction.map((i) => renderLink(i, { action: true, fullWidth: true }))}</div>

          <div className="nav-section-label" style={{ marginTop: 12 }}>Système</div>
          <div className="nb-drawer-group">{navSystem.map((i) => renderLink(i, { fullWidth: true }))}</div>

          {!isPersonalOnly && (
            <>
              <div className="nav-section-label" style={{ marginTop: 12 }}>Espace</div>
              <div className="account-tabs" role="group" aria-label="Espace du compte">
                <button
                  className={`account-tab ${effectiveMode === 'personal' ? 'active-personal' : ''}`}
                  onClick={() => handleModeChange('personal')}
                  aria-pressed={effectiveMode === 'personal'}
                >
                  P
                </button>
                <button
                  className={`account-tab ${effectiveMode === 'business' ? 'active-business' : ''}`}
                  onClick={() => handleModeChange('business')}
                  aria-pressed={effectiveMode === 'business'}
                >
                  B
                </button>
              </div>
            </>
          )}

          <div className="nb-drawer-footer">
            <div className="nb-drawer-user">
              <Avatar avatar={user?.avatar} accountType={effectiveMode} size={30} className="nb-user-avatar" />
              <div>
                <div className="nb-user-name">{user?.name || 'Utilisateur'}</div>
                <div className="nb-user-verified">● Compte vérifié</div>
              </div>
            </div>
            <button className="nb-logout" onClick={handleLogout}>
              ⏻ Déconnexion
            </button>
          </div>
        </div>
      )}
    </header>
  );
}
