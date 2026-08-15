import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useTheme } from '../../context/ThemeContext';
import { LanguageSwitcher } from '../LanguageSwitcher';
import NotificationBell from './NotificationBell';
import Avatar from '../Avatar';

type Mode = 'personal' | 'business';

export default function Topbar({ mode, title, subtitle }: { mode: Mode; title: string; subtitle?: string }) {
  const now = new Date();
  const timeStr = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const { theme, toggleTheme } = useTheme();

  return (
    <header className="topbar">
      <div className="topbar-left">
        <div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', letterSpacing: '0.1em', textTransform: 'uppercase' }}>
            Nexus {mode === 'personal' ? 'Personnel' : 'Business'}
          </div>
          <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: '-0.3px', marginTop: 1 }}>
            {title}
          </div>
          {subtitle && <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 1 }}>{subtitle}</div>}
        </div>
      </div>

      <div className="topbar-right">
        <div className="pill p-c topbar-pill">EUR → XAF Pilote</div>

        {/* Centre de notifications */}
        <NotificationBell />

        {/* Theme Toggle */}
        <button
          onClick={toggleTheme}
          title={theme === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre'}
          style={{ 
            padding: '6px 10px', 
            borderRadius: 8, 
            border: '1px solid var(--border)', 
            background: 'var(--panel2)', 
            color: 'var(--text-bright)', 
            fontSize: 14, 
            cursor: 'pointer',
            transition: 'all 0.2s'
          }}
          onMouseEnter={(e) => { e.currentTarget.style.borderColor = 'var(--cyan)'; e.currentTarget.style.background = 'rgba(0,200,255,0.05)'; }}
          onMouseLeave={(e) => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.background = 'var(--panel2)'; }}
        >
          {theme === 'dark' ? '☀️' : '🌙'}
        </button>

        <LanguageSwitcher variant="dashboard" />

        <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
          <Avatar avatar={user?.avatar} accountType={mode} size={30} />
          <div>
            <div style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-bright)' }}>
              {user?.name || 'Utilisateur'}
            </div>
            <div style={{ fontSize: 9, color: user?.account_type === 'business' ? 'var(--gold)' : 'var(--green)', fontFamily: 'var(--font-mono)' }}>
              ● Compte vérifié
            </div>
          </div>
        </div>

        <button
          onClick={async () => { await logout(); navigate('/login', { replace: true }); }}
          style={{ padding: '6px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-dim)', fontSize: 11, fontWeight: 600, cursor: 'pointer', transition: 'all 0.2s', display: 'flex', alignItems: 'center', gap: 6 }}
          onMouseEnter={(e) => { e.currentTarget.style.borderColor = 'var(--red)'; e.currentTarget.style.color = 'var(--red)'; e.currentTarget.style.background = 'rgba(255, 69, 96, 0.05)'; }}
          onMouseLeave={(e) => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.color = 'var(--text-dim)'; e.currentTarget.style.background = 'var(--panel2)'; }}
        >
          <span style={{ fontSize: 14, lineHeight: 1 }}>⏻</span> Déconnexion
        </button>

        <div className="topbar-clock" style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--text-dim)' }}>
          {timeStr}
        </div>
      </div>
    </header>
  );
}
