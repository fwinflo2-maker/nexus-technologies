import { type ReactNode } from 'react';
import { motion } from 'framer-motion';
import { useI18n } from '../../context/I18nContext';
import { TorusField } from '../../components/TorusField';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { EASE, AnimatedNumber } from '../../components/anim/Premium';
import './LandingPage.css';

const stagger = { hidden: {}, visible: { transition: { staggerChildren: 0.12 } } };
const cardVariant = {
  hidden: { opacity: 0, y: 26, scale: 0.98 },
  visible: { opacity: 1, y: 0, scale: 1, transition: { duration: 0.55, ease: EASE } },
};

interface LandingPageProps {
  onLogin: () => void;
  onRegister: () => void;
}

const svgIcons: Record<string, ReactNode> = {
  send: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>,
  search: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>,
  check: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>,
  layers: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>,
  shield: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>,
  zap: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>,
};

export function LandingPage({ onLogin, onRegister }: LandingPageProps) {
  const { t } = useI18n();

  const steps = [
    { num: '01', icon: 'send', title: t('landing_step1_title'), text: t('landing_step1_text') },
    { num: '02', icon: 'search', title: t('landing_step2_title'), text: t('landing_step2_text') },
    { num: '03', icon: 'check', title: t('landing_step3_title'), text: t('landing_step3_text') },
  ];

  const benefits = [
    { icon: 'layers', title: t('landing_feat1_title'), text: t('landing_feat1_text') },
    { icon: 'shield', title: t('landing_feat2_title'), text: t('landing_feat2_text') },
    { icon: 'zap', title: t('landing_feat3_title'), text: t('landing_feat3_text') },
  ];

  return (
    <div className="landing-page">
      <ParticlesBackground />
      <nav className="site-nav">
        <div className="site-nav-inner">
          <div className="nav-brand">
            <svg width="28" height="28" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#lg-nav)" strokeWidth="2" fill="none"/><path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#lg-nav)"/><defs><linearGradient id="lg-nav" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#7C3AED"/><stop offset="1" stopColor="#a855f7"/></linearGradient></defs></svg>
            <span className="brand-text">NEXUS</span>
          </div>
          <div className="nav-links">
            <a href="#comment-ca-marche" className="nav-link-text">{t('nav_how')}</a>
            <a href="#fonctionnalites" className="nav-link-text">{t('nav_features')}</a>
            <LanguageSwitcher />
            <button className="btn btn-ghost btn-sm" onClick={onLogin}>{t('nav_login')}</button>
            <button className="btn btn-primary btn-sm" onClick={onRegister}>{t('nav_start')}</button>
          </div>
        </div>
      </nav>

      {/* ── Hero ──────────────────────────────────────────────── */}
      <section className="hero-section">
        <div className="hero-grid" aria-hidden="true" />
        <div className="hero-orb hero-orb-main" aria-hidden="true" />
        <div className="hero-orb hero-orb-side" aria-hidden="true" />
        <div className="hero-torus" aria-hidden="true"><TorusField /></div>

        <motion.div
          className="container hero-content"
          initial="hidden"
          animate="visible"
          variants={stagger}
        >
          <motion.div variants={cardVariant} className="eyebrow"><span className="pulse-dot success" /> {t('landing_badge')}</motion.div>
          <motion.h1 variants={cardVariant} className="hero-title">
            {t('landing_hero_title')}
          </motion.h1>
          <motion.p variants={cardVariant} className="hero-lead">
            {t('landing_hero_subtitle')}
          </motion.p>
          <motion.div variants={cardVariant} className="hero-actions">
            <motion.button
              className="btn btn-glow btn-lg" onClick={onRegister}
              whileHover={{ scale: 1.05, y: -2 }}
              whileTap={{ scale: 0.97 }}
              transition={{ type: 'spring', stiffness: 300, damping: 17 }}
            >
              {t('landing_cta_main')}
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </motion.button>
            <motion.a
              href="#comment-ca-marche" className="btn btn-ghost btn-lg"
              whileHover={{ scale: 1.04 }}
              whileTap={{ scale: 0.97 }}
              transition={{ type: 'spring', stiffness: 300, damping: 17 }}
            >{t('landing_cta_link')} <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={{ marginLeft: 4 }}><path d="M6 9l6 6 6-6"/></svg></motion.a>
          </motion.div>
          <motion.div variants={cardVariant} className="trust-row">
            <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg> {t('landing_trust_1')}</span>
            <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> {t('landing_trust_2')}</span>
            <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg> {t('landing_trust_3')}</span>
          </motion.div>
        </motion.div>
      </section>

      {/* ── Stats ─────────────────────────────────────────────── */}
      <motion.section
        className="container stats-section" aria-label="Chiffres clés"
        initial="hidden" whileInView="visible" viewport={{ once: true, margin: '-60px' }} variants={stagger}
      >
        <motion.div className="stats-card glass-card" variants={cardVariant}>
          <div className="stat-item"><strong><AnimatedNumber value={180} suffix="+" /></strong><span>{t('landing_stat_1')}</span></div>
          <div className="stat-item"><strong><AnimatedNumber value={50} suffix="+" /></strong><span>{t('landing_stat_2')}</span></div>
          <div className="stat-item"><strong><AnimatedNumber value={99.2} decimals={1} suffix="%" /></strong><span>{t('landing_stat_3')}</span></div>
        </motion.div>
      </motion.section>

      {/* ── Comment ça marche ─────────────────────────────────── */}
      <section id="comment-ca-marche" className="section-block container">
        <div className="section-heading">
          <span className="section-kicker">{t('landing_steps_label')}</span>
          <h2>{t('landing_steps_title')}</h2>
          <p>{t('landing_steps_subtitle')}</p>
        </div>
        <motion.div
          className="steps-grid stagger"
          initial="hidden" whileInView="visible" viewport={{ once: true, margin: '-60px' }} variants={stagger}
        >
          {steps.map((step, index) => (
            <motion.div className="step-card glass-card" key={step.num} variants={cardVariant}
              whileHover={{ y: -6, scale: 1.02, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div className="step-top">
                <span className="step-number">{step.num}</span>
                <div className="step-icon">{svgIcons[step.icon]}</div>
              </div>
              {index < steps.length - 1 && <span className="step-connector" aria-hidden="true">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
              </span>}
              <h3>{step.title}</h3>
              <p>{step.text}</p>
            </motion.div>
          ))}
        </motion.div>
      </section>

      {/* ── Fonctionnalités ───────────────────────────────────── */}
      <section id="fonctionnalites" className="section-block container benefits-section">
        <div className="section-heading">
          <span className="section-kicker">{t('landing_features_label')}</span>
          <h2>{t('landing_features_title')}</h2>
        </div>
        <motion.div
          className="benefits-grid stagger"
          initial="hidden" whileInView="visible" viewport={{ once: true, margin: '-60px' }} variants={stagger}
        >
          {benefits.map((b) => (
            <motion.article className="benefit-card" key={b.title} variants={cardVariant}
              whileHover={{ y: -6, scale: 1.02, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <motion.div className="benefit-icon"
                whileHover={{ rotate: 8, scale: 1.1 }}
                transition={{ type: 'spring', stiffness: 300, damping: 15 }}>
                {svgIcons[b.icon]}
              </motion.div>
              <h3>{b.title}</h3>
              <p>{b.text}</p>
            </motion.article>
          ))}
        </motion.div>
      </section>

      {/* ── Architecture ──────────────────────────────────────── */}
      <section id="architecture" className="arch-section">
        <div className="container">
          <div className="arch-heading">
            <span className="section-kicker">{t('landing_arch_label')}</span>
            <h2>{t('landing_arch_title')}</h2>
            <p>{t('landing_arch_subtitle')}</p>
          </div>

          <motion.div
            className="arch-grid"
            initial="hidden" whileInView="visible" viewport={{ once: true, margin: '-60px' }} variants={stagger}
          >
            {/* Card centrale — Pipeline */}
            <motion.div className="arch-card arch-card-center" variants={cardVariant}
              whileHover={{ y: -5, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div>
                <h3>{t('landing_arch1_title')}</h3>
                <p>{t('landing_arch1_text')}</p>
              </div>
              <motion.div className="arch-flow"
                animate={{ opacity: [0.4, 1, 0.4] }}
                transition={{ duration: 3, repeat: Infinity, ease: 'easeInOut' }}>
                <div className="arch-flow-node">Intent</div>
                <span className="arch-flow-arrow">→</span>
                <div className="arch-flow-node">Routing</div>
                <span className="arch-flow-arrow">→</span>
                <div className="arch-flow-node">Execution</div>
                <span className="arch-flow-arrow">→</span>
                <div className="arch-flow-node">Ledger</div>
              </motion.div>
            </motion.div>

            {/* Card 1 — Routing */}
            <motion.div className="arch-card" variants={cardVariant}
              whileHover={{ y: -5, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div className="arch-card-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M12 19h4.5a3.5 3.5 0 0 0 0-7h-9a3.5 3.5 0 0 1 0-7H12"/></svg>
              </div>
              <h3>{t('landing_arch2_title')}</h3>
              <p>{t('landing_arch2_text')}</p>
              <div className="arch-card-tags">
                <span className="arch-tag">Multi-provider</span>
                <span className="arch-tag">Scoring</span>
                <span className="arch-tag">Optimisé</span>
              </div>
            </motion.div>

            {/* Card 2 — Conformité */}
            <motion.div className="arch-card" variants={cardVariant}
              whileHover={{ y: -5, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div className="arch-card-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <h3>{t('landing_arch3_title')}</h3>
              <p>{t('landing_arch3_text')}</p>
              <div className="arch-card-tags">
                <span className="arch-tag">KYC/KYB</span>
                <span className="arch-tag">AML</span>
                <span className="arch-tag">Sanctions</span>
              </div>
            </motion.div>

            {/* Card 3 — Providers */}
            <motion.div className="arch-card" variants={cardVariant}
              whileHover={{ y: -5, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div className="arch-card-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/></svg>
              </div>
              <h3>{t('landing_arch4_title')}</h3>
              <p>{t('landing_arch4_text')}</p>
              <div className="arch-card-tags">
                <span className="arch-tag">Banking</span>
                <span className="arch-tag">PSP</span>
                <span className="arch-tag">FX</span>
                <span className="arch-tag">MoMo</span>
              </div>
            </motion.div>

            {/* Card 4 — Exécution */}
            <motion.div className="arch-card" variants={cardVariant}
              whileHover={{ y: -5, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div className="arch-card-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
              </div>
              <h3>{t('landing_arch5_title')}</h3>
              <p>{t('landing_arch5_text')}</p>
              <div className="arch-card-tags">
                <span className="arch-tag">Idempotent</span>
                <span className="arch-tag">Ledger</span>
                <span className="arch-tag">Settlement</span>
              </div>
            </motion.div>

            {/* Card 5 — Intelligence */}
            <motion.div className="arch-card" variants={cardVariant}
              whileHover={{ y: -5, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div className="arch-card-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M20.66 8A10 10 0 0 0 14 2v6h6.66z"/></svg>
              </div>
              <h3>{t('landing_arch6_title')}</h3>
              <p>{t('landing_arch6_text')}</p>
              <div className="arch-card-tags">
                <span className="arch-tag">IA agents</span>
                <span className="arch-tag">Deterministe</span>
                <span className="arch-tag">Audit</span>
              </div>
            </motion.div>

            {/* Card 6 — Ledger */}
            <motion.div className="arch-card" variants={cardVariant}
              whileHover={{ y: -5, transition: { type: 'spring', stiffness: 250, damping: 18 } }}>
              <div className="arch-card-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
              </div>
              <h3>{t('landing_arch7_title')}</h3>
              <p>{t('landing_arch7_text')}</p>
              <div className="arch-card-tags">
                <span className="arch-tag">Automatique</span>
                <span className="arch-tag">Escalade</span>
                <span className="arch-tag">Preuves</span>
              </div>
            </motion.div>
          </motion.div>
        </div>
      </section>

      {/* ── CTA final ────────────────────────────────────────── */}
      <motion.section
        className="container final-cta"
        initial="hidden" whileInView="visible" viewport={{ once: true, margin: '-60px' }} variants={stagger}
      >
        <motion.div className="glass-card final-cta-card" variants={cardVariant}>
          <div>
            <span className="section-kicker">{t('landing_cta_section_title')}</span>
            <h2>{t('landing_cta_section_heading')}</h2>
            <p>{t('landing_cta_section_text')}</p>
          </div>
          <motion.button
            className="btn btn-glow btn-lg" onClick={onRegister}
            whileHover={{ scale: 1.05, y: -2 }}
            whileTap={{ scale: 0.97 }}
            transition={{ type: 'spring', stiffness: 300, damping: 17 }}
          >
            {t('landing_cta_button')}
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </motion.button>
        </motion.div>
      </motion.section>

      {/* ── Footer ────────────────────────────────────────────── */}
      <footer className="site-footer">
        <div className="footer-inner">
          <div className="footer-brand">
            <svg width="20" height="20" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#lg-f)" strokeWidth="2" fill="none"/><path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#lg-f)"/><defs><linearGradient id="lg-f" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#7C3AED"/><stop offset="1" stopColor="#a855f7"/></linearGradient></defs></svg>
            <span>NEXUS CORP TECHNOLOGIES</span>
          </div>
          <div className="footer-links">
            <span>Documentation</span>
            <span>{t('footer_privacy')}</span>
            <span>{t('footer_terms')}</span>
            <span>Support</span>
          </div>
          <p className="footer-copy">{t('footer_copy')}</p>
        </div>
      </footer>
    </div>
  );
}
