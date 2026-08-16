import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { countries } from '../../data/countries';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import './AuthPages.css';
import { useI18n } from '../../context/I18nContext';
import { useAuth } from '../../context/AuthContext';
import { apiLogin } from '../../api/client';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { EASE } from '../../components/anim/Premium';

const authEnter = {
  hidden: { opacity: 0, y: 26, scale: 0.99 },
  visible: (i: number = 0) => ({ opacity: 1, y: 0, scale: 1, transition: { duration: 0.5, ease: EASE, delay: i * 0.07 } }),
};

interface LoginPageProps {
  onSwitchToRegister: () => void;
  onBackHome: () => void;
}

export function LoginPage({ onSwitchToRegister, onBackHome }: LoginPageProps) {
  const [method, setMethod] = useState<'email' | 'phone'>('email');
  const [email, setEmail] = useState('');
  const [phoneCode, setPhoneCode] = useState('+33');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const { t } = useI18n();
  const { refreshSession } = useAuth();

  const trustArguments = [
    {
      icon: <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>,
      title: t('login_trust_1_title'),
      text: t('login_trust_1_text'),
    },
    {
      icon: <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>,
      title: t('login_trust_2_title'),
      text: t('login_trust_2_text'),
    },
    {
      icon: <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>,
      title: t('login_trust_3_title'),
      text: t('login_trust_3_text'),
    },
  ];

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    const identifier = method === 'email' ? email.trim() : `${phoneCode}${phone.trim()}`;
    if (!identifier || !password) { setError(t('login_err_required')); return; }
    if (method === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier)) { setError(t('login_err_email')); return; }
    if (method === 'phone' && phone.trim().length < 6) { setError(t('login_err_phone')); return; }
    setLoading(true);
    try {
      // Appel direct à l'API backend — le token est stocké côté client par apiLogin
      const resp = await apiLogin(identifier, password);
      if (!resp.success) {
        setError(resp.error ?? t('login_err_required'));
        return;
      }
      // Revalide la session via /api/me pour que le contexte React mette à jour le user
      await refreshSession();
      // Navigation SPA vers le dashboard (pas de reload)
      navigate('/dashboard', { replace: true });
    } catch {
      // Filet de sécurité : ne jamais laisser le formulaire bloqué en « envoi ».
      setError(t('login_err_required'));
    } finally {
      setLoading(false);
    }
  }

  const strength = password.length === 0 ? 0 : password.length < 8 ? 1 : password.length < 14 ? 2 : 3;
  const strengthLabels = ['', t('login_strength_short'), t('login_strength_ok'), t('login_strength_strong')] as const;
  const strengthColors = ['transparent', 'var(--danger)', 'var(--warning)', 'var(--success)'] as const;

  return (
    <div className="auth-page">
      <ParticlesBackground />
      <div className="auth-card">
        <div className="auth-glow" aria-hidden="true" />
        <div className="auth-card-inner">
          {/* Formulaire */}
          <div className="auth-form-side">
            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={0} className="auth-topbar">
              <button className="auth-back" onClick={onBackHome}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                {t('auth_back')}
              </button>
              <LanguageSwitcher />
            </motion.div>

            <motion.h1 variants={authEnter} initial="hidden" animate="visible" custom={1} className="auth-title">{t('login_title')}</motion.h1>
            <motion.p variants={authEnter} initial="hidden" animate="visible" custom={2} className="auth-subtitle">{t('login_subtitle')}</motion.p>

            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={3} className="identifier-toggle" role="tablist" aria-label={t('login_method_label')}>
              <button type="button" className={method === 'email' ? 'active' : ''} onClick={() => setMethod('email')}>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                {t('login_email')}
              </button>
              <button type="button" className={method === 'phone' ? 'active' : ''} onClick={() => setMethod('phone')}>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                {t('login_phone')}
              </button>
            </motion.div>

            <motion.form variants={authEnter} initial="hidden" animate="visible" custom={4} className="auth-form" onSubmit={handleSubmit} noValidate>
              <AnimatePresence mode="wait">
                {method === 'email' ? (
                  <motion.div key="email" initial={{ opacity: 0, x: -14 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: 14 }} transition={{ duration: 0.25, ease: EASE }}>
                    <label htmlFor="email" className="form-label">{t('login_email_label')}</label>
                    <input id="email" type="email" className="form-control" placeholder={t('login_email_placeholder')} value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" />
                  </motion.div>
                ) : (
                  <motion.div key="phone" initial={{ opacity: 0, x: -14 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: 14 }} transition={{ duration: 0.25, ease: EASE }}>
                    <label htmlFor="phone" className="form-label">{t('login_phone_label')}</label>
                    <div className="phone-prefix">
                      <select className="form-control phone-code" value={phoneCode} onChange={(e) => setPhoneCode(e.target.value)} aria-label={t('login_phone_code_label')}>
                        {countries.map(c => <option key={c.code} value={c.dial}>{c.dial} {c.code}</option>)}
                      </select>
                      <input id="phone" type="tel" className="form-control phone-number" placeholder={t('login_phone_placeholder')} value={phone} onChange={(e) => setPhone(e.target.value)} autoFocus autoComplete="tel" />
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>

              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <label htmlFor="password" className="form-label" style={{ marginBottom: 6 }}>{t('login_password')}</label>
                  <button type="button" onClick={() => navigate('/forgot-password')} style={{ fontSize: '0.75rem', color: 'var(--text-faint)', marginBottom: 6, background: 'none', border: 'none', cursor: 'pointer', fontFamily: 'inherit', padding: 0 }}>{t('login_forgot')}</button>
                </div>
                <div className="password-wrapper">
                  <input id="password" type={visible ? 'text' : 'password'} className="form-control" style={{ paddingRight: '2.75rem' }} placeholder={t('login_password_placeholder')} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" />
                  <button type="button" className="password-toggle" onClick={() => setVisible(v => !v)} aria-label={visible ? t('login_hide') : t('login_show')}>
                    {visible ? <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg> : <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>}
                  </button>
                </div>
              </div>

              {password.length > 0 && (
                <div style={{ marginBottom: '0.25rem' }}>
                  <div style={{ display: 'flex', gap: 4, marginBottom: 6 }}>
                    {[1, 2, 3].map(n => <div key={n} style={{ height: 3, flex: 1, borderRadius: 2, background: strength >= n ? strengthColors[strength] : 'rgba(255,255,255,0.09)', transition: 'background 0.2s ease' }} />)}
                  </div>
                  <div style={{ fontSize: '0.75rem', color: strength === 0 ? 'var(--text-faint)' : strengthColors[strength] }}>
                    {strength === 0 ? t('login_min_chars') : `${strengthLabels[strength]} · ${password.length} ${password.length > 1 ? t('login_chars_plural') : t('login_chars_singular')}`}
                  </div>
                </div>
              )}

              {error && <div className="auth-error">{error}</div>}

              <motion.button
                type="submit" className="btn btn-glow btn-lg" disabled={loading} style={{ flex: 1 }}
                whileHover={{ scale: 1.02, y: -1 }} whileTap={{ scale: 0.98 }}
                transition={{ type: 'spring', stiffness: 300, damping: 17 }}
              >
                {loading ? <><span className="spinner" /> {t('login_sending')}</> : t('login_submit')}
              </motion.button>
            </motion.form>

            <motion.p variants={authEnter} initial="hidden" animate="visible" custom={5} className="auth-footer">
              {t('login_no_account')} <button className="auth-link-button" onClick={onSwitchToRegister}>{t('login_create')}</button>
            </motion.p>
          </div>

          {/* Panneau de confiance */}
          <aside className="auth-panel-side">
            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={1} className="trust-panel-badge">
              <span className="pulse-dot success" />
              {t('login_panel_badge')}
            </motion.div>
            <motion.h3 variants={authEnter} initial="hidden" animate="visible" custom={2} className="trust-panel-title">
              <span style={{ color: 'var(--text-heading)' }}>{t('login_panel_title_1')}</span>
              <br />
              <span className="gradient-text">{t('login_panel_title_2')}</span>
            </motion.h3>
            <motion.div className="trust-panel-items" style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}
              initial="hidden" animate="visible" variants={{ hidden: {}, visible: { transition: { staggerChildren: 0.12 } } }}>
              {trustArguments.map(a => (
                <motion.div
                  className="trust-panel-item" key={a.title}
                  variants={authEnter} custom={1}
                  whileHover={{ x: 6, transition: { type: 'spring', stiffness: 260, damping: 20 } }}
                >
                  <motion.div className="trust-panel-icon" whileHover={{ scale: 1.12 }} transition={{ type: 'spring', stiffness: 300, damping: 15 }}>{a.icon}</motion.div>
                  <div>
                    <h4>{a.title}</h4>
                    <p>{a.text}</p>
                  </div>
                </motion.div>
              ))}
            </motion.div>
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
