import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import './AuthPages.css';
import { useI18n } from '../../context/I18nContext';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { apiForgotPassword, apiResetPassword } from '../../api/client';

/**
 * Mot de passe oublié — flow RÉEL connecté au backend.
 *
 * Étape 1 : saisie de l'email → POST /api/auth/forgot-password. Le serveur
 * vérifie le compte et (en environnement de développement) retourne le jeton
 * de réinitialisation. En production, ce jeton partirait par e-mail.
 *
 * Étape 2 : saisie du nouveau mot de passe → POST /api/auth/reset-password,
 * qui consomme le jeton (stocké haché en base, usage unique, expiration 30 min).
 *
 * Anti-énumération : la réponse du serveur est identique que l'email existe
 * ou non ; l'UI n'affiche jamais « compte introuvable ».
 */
export default function ForgotPasswordPage() {
  const [step, setStep] = useState<'email' | 'newpass' | 'done'>('email');
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);
  const [devToken, setDevToken] = useState<string | null>(null);
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const navigate = useNavigate();
  const { t } = useI18n();

  async function handleRequest(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    if (!email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError(t('fp_err_email'));
      return;
    }
    setSending(true);
    try {
      const resp = await apiForgotPassword(email);
      if (!resp.success) {
        setError(resp.error ?? t('fp_err_email'));
        return;
      }
      // En dev, le backend renvoie le jeton pour permettre un reset de bout en bout.
      const token = resp.data?.reset_token ?? null;
      setDevToken(token);
      if (token) {
        // Le jeton est disponible (mode dev) : passer directement à l'étape 2.
        setStep('newpass');
      } else {
        // Sinon, on affiche l'état « e-mail envoyé » (production).
        setStep('done');
      }
    } catch {
      setError('Service temporairement indisponible. Veuillez réessayer.');
    } finally {
      setSending(false);
    }
  }

  async function handleReset(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    if (newPassword.length < 8) {
      setError('Le mot de passe doit contenir au moins 8 caractères.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setError('Les mots de passe ne correspondent pas.');
      return;
    }
    if (!devToken) {
      setError('Jeton de réinitialisation manquant.');
      return;
    }
    setSending(true);
    try {
      const resp = await apiResetPassword(devToken, newPassword, confirmPassword);
      if (!resp.success) {
        setError(resp.error ?? 'Erreur lors de la réinitialisation.');
        return;
      }
      setStep('done');
    } catch {
      setError('Service temporairement indisponible. Veuillez réessayer.');
    } finally {
      setSending(false);
    }
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

            {step === 'email' && (
              <>
                <h1 className="auth-title">{t('fp_title')}</h1>
                <p className="auth-subtitle">{t('fp_subtitle')}</p>
                <form className="auth-form" onSubmit={handleRequest} noValidate>
                  <div>
                    <label htmlFor="reset-email" className="form-label">{t('fp_email_label')}</label>
                    <input
                      id="reset-email" type="email" className="form-control"
                      placeholder={t('fp_email_placeholder')} value={email}
                      onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email"
                    />
                  </div>
                  {error && <div className="auth-error">{error}</div>}
                  <button type="submit" className="btn btn-glow btn-block btn-lg" disabled={sending}>
                    {sending ? <><span className="spinner" /> {t('fp_sending')}</> : t('fp_submit')}
                  </button>
                </form>
              </>
            )}

            {step === 'newpass' && (
              <>
                <h1 className="auth-title">Définir un nouveau mot de passe</h1>
                <p className="auth-subtitle">
                  Compte vérifié. Choisissez un nouveau mot de passe pour <b>{email}</b>.
                </p>
                <form className="auth-form" onSubmit={handleReset} noValidate>
                  <div>
                    <label htmlFor="np1" className="form-label">Nouveau mot de passe</label>
                    <input id="np1" type="password" className="form-control" placeholder="Min. 8 caractères"
                      value={newPassword} onChange={(e) => setNewPassword(e.target.value)} autoComplete="new-password" />
                  </div>
                  <div>
                    <label htmlFor="np2" className="form-label">Confirmer le mot de passe</label>
                    <input id="np2" type="password" className="form-control" placeholder="Confirmer"
                      value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} autoComplete="new-password" />
                  </div>
                  {error && <div className="auth-error">{error}</div>}
                  <button type="submit" className="btn btn-glow btn-block btn-lg" disabled={sending}>
                    {sending ? <><span className="spinner" /> Réinitialisation…</> : 'Réinitialiser le mot de passe'}
                  </button>
                </form>
              </>
            )}

            {step === 'done' && (
              <>
                <h1 className="auth-title">{t('fp_sent_title')}</h1>
                <p className="auth-subtitle">{t('fp_sent_text')}</p>
                <button className="btn btn-glow btn-block btn-lg" onClick={() => navigate('/login')}>
                  Retour à la connexion
                </button>
              </>
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
