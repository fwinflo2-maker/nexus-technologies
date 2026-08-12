import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import './AuthPages.css';
import { useI18n } from '../../context/I18nContext';
import { ParticlesBackground } from '../../components/ParticlesBackground';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const { t } = useI18n();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    if (!email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError(t('fp_err_email'));
      return;
    }
    setLoading(true);
    await new Promise(r => setTimeout(r, 1000));
    setLoading(false);
    setSent(true);
  }

  return (
    <div className="auth-page">
      <ParticlesBackground />
      <div className="auth-card">
        <div className="auth-glow" aria-hidden="true" />
        <div className="auth-card-inner">
          <div className="auth-form-side">
            <div className="auth-topbar">
              <button className="auth-back" onClick={() => navigate('/login')}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                {t('fp_back_login')}
              </button>
              <LanguageSwitcher />
            </div>

            {!sent ? (
              <>
                <h1 className="auth-title">{t('fp_title')}</h1>
                <p className="auth-subtitle">
                  {t('fp_subtitle')}
                </p>

                <form className="auth-form" onSubmit={handleSubmit} noValidate>
                  <div>
                    <label htmlFor="reset-email" className="form-label">{t('fp_email_label')}</label>
                    <input
                      id="reset-email"
                      type="email"
                      className="form-control"
                      placeholder={t('fp_email_placeholder')}
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      autoFocus
                      autoComplete="email"
                    />
                  </div>

                  {error && <div className="auth-error">{error}</div>}

                  <button type="submit" className="btn btn-glow btn-block btn-lg" disabled={loading}>
                    {loading ? <><span className="spinner" /> {t('fp_sending')}</> : t('fp_submit')}
                  </button>
                </form>
              </>
            ) : (
              <div style={{ textAlign: 'center', padding: '2rem 0' }}>
                <div style={{ fontSize: '3rem', marginBottom: '1rem' }}>✉️</div>
                <h1 className="auth-title">{t('fp_sent_title')}</h1>
                <p className="auth-subtitle">
                  {t('fp_sent_text').replace('{email}', email)}
                </p>
                <button
                  className="btn btn-ghost btn-lg"
                  style={{ marginTop: '1.5rem' }}
                  onClick={() => navigate('/login')}
                >
                  {t('fp_back_login')}
                </button>
              </div>
            )}
          </div>

          <aside className="auth-panel-side">
            <div className="trust-panel-badge">
              <span className="pulse-dot success" />
              {t('fp_panel_badge')}
            </div>
            <h3 className="trust-panel-title">
              <span style={{ color: 'var(--text-heading)' }}>{t('fp_panel_title_1')}</span>
              <br />
              <span className="gradient-text">{t('fp_panel_title_2')}</span>
            </h3>
            <p style={{ fontSize: '0.875rem', color: 'var(--text-muted)', lineHeight: 1.6 }}>
              {t('fp_panel_text')}
            </p>
            <div className="trust-panel-items" style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem', marginTop: '1rem' }}>
              <div className="trust-panel-item">
                <div className="trust-panel-icon">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div>
                  <h4>{t('fp_trust_1_title')}</h4>
                  <p>{t('fp_trust_1_text')}</p>
                </div>
              </div>
              <div className="trust-panel-item">
                <div className="trust-panel-icon">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                </div>
                <div>
                  <h4>{t('fp_trust_2_title')}</h4>
                  <p>{t('fp_trust_2_text')}</p>
                </div>
              </div>
            </div>
          </aside>
        </div>

        <p className="auth-note">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          {t('auth_note')}
        </p>
      </div>
    </div>
  );
}
