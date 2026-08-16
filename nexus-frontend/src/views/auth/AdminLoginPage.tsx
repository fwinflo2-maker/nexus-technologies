import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { apiLogin } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { EASE } from '../../components/anim/Premium';
import './AdminLoginPage.css';

/**
 * Page de connexion SUPER ADMIN — ultra premium (glassmorphism).
 * Accessible via le logo NEXUS (3 clics) sur la landing. Réservée au rôle
 * superadmin : un autre compte est redirigé vers son dashboard client.
 */
export default function AdminLoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const { refreshSession } = useAuth();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    if (!email.trim() || !password) { setError('Identifiant et mot de passe requis.'); return; }
    setLoading(true);
    try {
      const resp = await apiLogin(email.trim(), password);
      if (!resp.success) { setError(resp.error ?? 'Échec de l\'authentification.'); return; }
      await refreshSession();
      // Seul le superadmin reste ici ; sinon retour au dashboard client.
      navigate(resp.data?.user?.platform_role === 'superadmin' ? '/admin' : '/dashboard', { replace: true });
    } catch {
      setError('Service temporairement indisponible.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="admin-login-page">
      {/* Animation de particules en background */}
      <ParticlesBackground density={50} color="#60A5FA" opacity={0.4} />
      
      {/* Halos ambiants */}
      <div className="al-orb al-orb-1" aria-hidden="true" />
      <div className="al-orb al-orb-2" aria-hidden="true" />
      <div className="al-grid" aria-hidden="true" />

      <motion.div
        className="al-glass"
        initial={{ opacity: 0, y: 30, scale: 0.97 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        transition={{ duration: 0.6, ease: EASE }}
      >
        {/* En-tête */}
        <motion.div className="al-head" initial={{ opacity: 0, y: -12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15, duration: 0.5, ease: EASE }}>
          <div className="al-logo">
            <svg width="34" height="34" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#algrad)" strokeWidth="2" fill="none"/><path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#algrad)"/><defs><linearGradient id="algrad" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#3B82F6"/><stop offset="1" stopColor="#60A5FA"/></linearGradient></defs></svg>
          </div>
          <div className="al-title-block">
            <div className="al-eyebrow">NEXUS CORE · ADMINISTRATION</div>
            <h1 className="al-title">Console Super Admin</h1>
          </div>
          <div className="al-secure"><span className="al-secure-dot" /> Accès sécurisé</div>
        </motion.div>

        {/* Formulaire */}
        <motion.form onSubmit={handleSubmit} noValidate initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.3, duration: 0.5 }}>
          <motion.div className="al-field" initial={{ opacity: 0, x: -16 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.4, duration: 0.4, ease: EASE }}>
            <label className="al-label" htmlFor="al-email">Identifiant admin</label>
            <input
              id="al-email" type="email" className="al-input" placeholder="admin@nexus-tech.io"
              value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" spellCheck={false}
            />
          </motion.div>

          <motion.div className="al-field" initial={{ opacity: 0, x: -16 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.5, duration: 0.4, ease: EASE }}>
            <label className="al-label" htmlFor="al-pass">Mot de passe</label>
            <div className="al-pass-wrap">
              <input
                id="al-pass" type={visible ? 'text' : 'password'} className="al-input" placeholder="••••••••••"
                value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password"
              />
              <button type="button" className="al-eye" onClick={() => setVisible(v => !v)} aria-label="Afficher/masquer">
                {visible ? '🙈' : '👁️'}
              </button>
            </div>
          </motion.div>

          {error && <motion.div className="al-error" initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }}>{error}</motion.div>}

          <motion.button
            type="submit" className="al-submit" disabled={loading}
            initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.6, duration: 0.4, ease: EASE }}
            whileHover={{ scale: 1.02, y: -1 }} whileTap={{ scale: 0.97 }}
          >
            {loading ? <><span className="al-spinner" /> Authentification…</> : 'Accéder à la console'}
          </motion.button>
        </motion.form>

        {/* Pied */}
        <motion.div className="al-foot" initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.7, duration: 0.5 }}>
          <button className="al-back" onClick={() => navigate('/')}>← Retour à l'accueil</button>
          <span className="al-hint">Réservé à l'administration Nexus</span>
        </motion.div>
      </motion.div>
    </div>
  );
}
