import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, MotionConfig, useMotionValue, useSpring } from 'framer-motion';
import { apiLogin } from '../../api/client';
import { useAuth, homePathForRole } from '../../context/AuthContext';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { TiltCard, AnimatedTitle, EASE } from '../../components/anim/Premium';
import './AdminLoginPage.css';

/**
 * Portail de connexion des employés Nexus Technologies.
 *
 * Point d'entrée dédié au personnel interne (platform_role ≠ user) : les
 * employés créés par le Super Admin s'authentifient ici, puis rejoignent
 * leur espace de travail. Le super admin reste dirigé vers la Console Admin.
 * Le style reprend la charte i-tech de la page admin (classes .al-*).
 */

/** Ligne terminal — effet machine à écrire au chargement. */
function TypeLine({ text }: { text: string }) {
  const [n, setN] = useState(0);
  useEffect(() => {
    let i = 0;
    let id = 0;
    const start = window.setTimeout(() => {
      id = window.setInterval(() => {
        i += 1;
        setN(i);
        if (i >= text.length) window.clearInterval(id);
      }, 42);
    }, 700);
    return () => { window.clearTimeout(start); window.clearInterval(id); };
  }, [text]);
  return (
    <div className="al-term" aria-hidden="true">
      <span className="al-term-prompt">&gt;</span>
      <span>{text.slice(0, n)}</span>
      <span className="al-term-cursor" />
    </div>
  );
}

const MOTES = [
  { left: '8%', top: '22%', size: 5, dur: 6, delay: 0 },
  { left: '16%', top: '68%', size: 4, dur: 7, delay: 1.2 },
  { left: '28%', top: '14%', size: 6, dur: 8, delay: 0.6 },
  { left: '72%', top: '20%', size: 5, dur: 6.5, delay: 2 },
  { left: '84%', top: '58%', size: 4, dur: 7.5, delay: 0.4 },
  { left: '64%', top: '78%', size: 6, dur: 9, delay: 1.6 },
];

export default function EmployeeLoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const { refreshSession } = useAuth();

  // Bouton magnétique (comme la console admin).
  const btnRef = useRef<HTMLButtonElement>(null);
  const btnX = useMotionValue(0);
  const btnY = useMotionValue(0);
  const sBtnX = useSpring(btnX, { stiffness: 260, damping: 16 });
  const sBtnY = useSpring(btnY, { stiffness: 260, damping: 16 });
  function onBtnMove(e: React.MouseEvent) {
    const el = btnRef.current; if (!el) return;
    const r = el.getBoundingClientRect();
    btnX.set((e.clientX - (r.left + r.width / 2)) * 0.18);
    btnY.set((e.clientY - (r.top + r.height / 2)) * 0.22);
  }
  function onBtnLeave() { btnX.set(0); btnY.set(0); }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    if (!email.trim() || !password) { setError('Identifiant et mot de passe requis.'); return; }
    setLoading(true);
    try {
      const resp = await apiLogin(email.trim(), password);
      if (!resp.success) { setError(resp.error ?? "Échec de l'authentification."); return; }
      await refreshSession();
      const role = resp.data?.user?.platform_role;
      // Super admin → Console Admin ; personnel interne → Espace Employé ;
      // compte client → dashboard client habituel.
      navigate(homePathForRole(role), { replace: true });
    } catch {
      setError('Service temporairement indisponible.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <MotionConfig reducedMotion="user">
      <div className="admin-login-page">
        <ParticlesBackground density={40} color="#3B82F6" opacity={0.35} />

        <div className="al-orb al-orb-1" aria-hidden="true" />
        <div className="al-orb al-orb-2" aria-hidden="true" />
        <div className="al-grid" aria-hidden="true" />
        <div className="al-dataline" aria-hidden="true" />

        {MOTES.map((m, i) => (
          <motion.span
            key={i}
            className="al-mote"
            style={{ left: m.left, top: m.top, width: m.size, height: m.size }}
            animate={{ y: [0, -16, 0], opacity: [0.15, 0.85, 0.15] }}
            transition={{ duration: m.dur, repeat: Infinity, delay: m.delay, ease: 'easeInOut' }}
            aria-hidden="true"
          />
        ))}

        <motion.div
          className="al-reveal"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: 0.35, ease: EASE }}
        >
          <div className="al-tilt" style={{ perspective: 1200 }}>
            <TiltCard className="tilt-card" max={7}>
              <motion.div
                className="al-glass"
                initial={{ opacity: 0, y: 30, scale: 0.97 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                transition={{ duration: 0.6, ease: EASE }}
              >
                <span className="al-scan" aria-hidden="true" />

                <motion.div className="al-head" initial={{ opacity: 0, y: -12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15, duration: 0.5, ease: EASE }}>
                  <div className="al-logo">
                    <svg width="34" height="34" viewBox="0 0 32 32" fill="none">
                      <path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#stgrad)" strokeWidth="2" fill="none" />
                      <path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#stgrad)" />
                      <defs><linearGradient id="stgrad" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#3B82F6" /><stop offset="1" stopColor="#60A5FA" /></linearGradient></defs>
                    </svg>
                  </div>
                  <div className="al-title-block">
                    <div className="al-eyebrow">NEXUS · PORTAIL EMPLOYÉS</div>
                    <h1 className="al-title"><AnimatedTitle text="Espace Employé" /></h1>
                  </div>
                  <div className="al-secure"><span className="al-secure-dot" /> INTERNE</div>
                </motion.div>

                <TypeLine text="nexus auth --role staff" />

                <motion.form onSubmit={handleSubmit} noValidate initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.3, duration: 0.5 }}>
                  <motion.div className="al-field" initial={{ opacity: 0, x: -16 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.4, duration: 0.4, ease: EASE }}>
                    <label className="al-label" htmlFor="st-email">Identifiant employé</label>
                    <input
                      id="st-email" type="email" className="al-input" placeholder="prenom.nom@nexus-tech.io"
                      value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" spellCheck={false}
                    />
                  </motion.div>

                  <motion.div className="al-field" initial={{ opacity: 0, x: -16 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.5, duration: 0.4, ease: EASE }}>
                    <label className="al-label" htmlFor="st-pass">Mot de passe</label>
                    <div className="al-pass-wrap">
                      <input
                        id="st-pass" type={visible ? 'text' : 'password'} className="al-input" placeholder="••••••••••"
                        value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password"
                      />
                      <button type="button" className="al-eye" onClick={() => setVisible((v) => !v)} aria-label="Afficher/masquer">
                        {visible ? '🙈' : '👁️'}
                      </button>
                    </div>
                  </motion.div>

                  {error && <motion.div className="al-error" initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }}>{error}</motion.div>}

                  <motion.button
                    ref={btnRef}
                    type="submit" className="al-submit shine-sweep" disabled={loading}
                    style={{ x: sBtnX, y: sBtnY }}
                    initial={{ opacity: 0, scale: 0.97 }} animate={{ opacity: 1, scale: 1 }} transition={{ delay: 0.6, duration: 0.4, ease: EASE }}
                    whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.97 }}
                    onMouseMove={onBtnMove} onMouseLeave={onBtnLeave}
                  >
                    {loading ? <><span className="al-spinner" /> Authentification…</> : 'Accéder à mon espace'}
                  </motion.button>
                </motion.form>

                <motion.div className="al-foot" initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.7, duration: 0.5 }}>
                  <button className="al-back" onClick={() => navigate('/')}>← Retour à l'accueil</button>
                  <span className="al-hint">Réservé au personnel Nexus Technologies</span>
                </motion.div>
              </motion.div>
            </TiltCard>
          </div>
        </motion.div>
      </div>
    </MotionConfig>
  );
}
