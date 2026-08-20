import { useAuth } from '../../context/AuthContext';
import { useTheme } from '../../context/ThemeContext';
import { useI18n } from '../../context/I18nContext';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import { isAccountVerified } from '../../lib/accountStatus';
import { LanguageSwitcher } from '../LanguageSwitcher';
import NotificationBell from './NotificationBell';
import Avatar from '../Avatar';

type Mode = 'personal' | 'business' | 'admin';

/** Topbar dashboard — notifications, thème, langue, profil. Déconnexion = Sidebar uniquement. */
export default function Topbar({ mode, title, subtitle }: { mode: Mode; title: string; subtitle?: string }) {
  const now = new Date();
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);
  const timeStr = now.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
  const { user } = useAuth();
  const { theme, toggleTheme } = useTheme();

  return (
    <header className="topbar">
      <div className="topbar-left">
        <div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', letterSpacing: '0.1em', textTransform: 'uppercase' }}>
            Nexus {mode === 'admin' ? t('topbar.mode.admin') : t(mode === 'personal' ? 'topbar.mode.personal' : 'topbar.mode.business')}
          </div>
          <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: '-0.3px', marginTop: 1 }}>
            {title}
          </div>
          {subtitle && <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 1 }}>{subtitle}</div>}
        </div>
      </div>

      <div className="topbar-right">
        <NotificationBell />

        <button
          onClick={toggleTheme}
          title={theme === 'dark' ? t('topbar.theme.light') : t('topbar.theme.dark')}
          style={{
            padding: '6px 10px',
            borderRadius: 8,
            border: '1px solid var(--border)',
            background: 'var(--panel2)',
            color: 'var(--text-bright)',
            fontSize: 14,
            cursor: 'pointer',
            transition: 'all 0.2s',
          }}
          onMouseEnter={(e) => { e.currentTarget.style.borderColor = 'var(--cyan)'; e.currentTarget.style.background = 'rgba(0,200,255,0.05)'; }}
          onMouseLeave={(e) => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.background = 'var(--panel2)'; }}
        >
          {theme === 'dark' ? '☀️' : '🌙'}
        </button>

        <LanguageSwitcher variant="dashboard" />

        <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
          <Avatar avatar={user?.avatar} accountType={mode === 'admin' ? 'business' : mode} size={30} />
          <div>
            <div style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-bright)' }}>
              {user?.name || t('common.userFallback')}
            </div>
            {mode === 'admin' ? (
              <div style={{ fontSize: 9, color: '#7FB4FF', fontFamily: 'var(--font-mono)' }}>
                ● {t('topbar.mode.admin')}
              </div>
            ) : (
              <div style={{ fontSize: 9, color: isAccountVerified(user) ? (user?.account_type === 'business' ? 'var(--gold)' : 'var(--green)') : 'var(--gold)', fontFamily: 'var(--font-mono)' }}>
                ● {isAccountVerified(user) ? t('common.verified') : t('common.verificationRequired')}
              </div>
            )}
          </div>
        </div>

        <div className="topbar-clock" style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--text-dim)' }}>
          {timeStr}
        </div>
      </div>
    </header>
  );
}
