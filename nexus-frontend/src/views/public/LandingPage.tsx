import { useState, useRef, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion, useReducedMotion } from 'framer-motion';
import { useI18n } from '../../context/I18nContext';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import { TorusField } from '../../components/TorusField';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { TechOrbits } from '../../components/TechOrbits';
import { DigitalBrushes } from '../../components/DigitalBrushes';
import { EASE } from '../../components/anim/Premium';
import { GsapReveal } from '../../components/anim/GsapReveal';
import './LandingPage.css';

const stagger = { hidden: {}, visible: { transition: { staggerChildren: 0.11 } } };
const rise = {
  hidden: { opacity: 0, y: 22 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.6, ease: EASE } },
};

interface LandingPageProps {
  onLogin: () => void;
  onAdminLogin: () => void;
  onRegister: (type?: 'personal' | 'business') => void;
}

export function LandingPage({ onLogin, onAdminLogin, onRegister }: LandingPageProps) {
  const { t } = useI18n();
  const reduceMotion = useReducedMotion();
  const [adminClicks, setAdminClicks] = useState(0);
  const clickResetRef = useRef<number>(0);

  useEffect(() => {
    if (adminClicks === 0) return;
    clickResetRef.current = window.setTimeout(() => setAdminClicks(0), 1500);
    return () => window.clearTimeout(clickResetRef.current);
  }, [adminClicks]);

  function handleLogoClick() {
    const next = adminClicks + 1;
    if (next >= 3) {
      setAdminClicks(0);
      onAdminLogin();
    } else {
      setAdminClicks(next);
    }
  }

  const steps = [
    { num: '01', title: t('landing_step1_title'), text: t('landing_step1_text') },
    { num: '02', title: t('landing_step2_title'), text: t('landing_step2_text') },
    { num: '03', title: t('landing_step3_title'), text: t('landing_step3_text') },
  ];

  const pains = [
    { title: t('landing_pain1_title'), text: t('landing_pain1_text') },
    { title: t('landing_pain2_title'), text: t('landing_pain2_text') },
    { title: t('landing_pain3_title'), text: t('landing_pain3_text') },
  ];

  const trust = [
    { title: t('landing_trust_1_title'), text: t('landing_trust_1_text') },
    { title: t('landing_trust_2_title'), text: t('landing_trust_2_text') },
    { title: t('landing_trust_3_title'), text: t('landing_trust_3_text') },
  ];

  const btnMotion = reduceMotion
    ? {}
    : {
        whileHover: { y: -2, scale: 1.02 },
        whileTap: { scale: 0.97 },
        transition: { type: 'spring' as const, stiffness: 320, damping: 20 },
      };

  return (
    <div className="landing-page">
      <a href="#main-content" className="lp-skip-link">{t('seo_skip_to_content')}</a>
      <ParticlesBackground density={28} color="#4F6EF7" opacity={0.28} className="lp-particles" links={false} />
      <div className="lp-scanline" aria-hidden="true" />
      <nav className="lp-nav" aria-label="Primary">
        <div className="lp-nav-inner">
          <div
            className="lp-brand"
            role="button"
            tabIndex={0}
            aria-label="Nexus"
            onClick={handleLogoClick}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') handleLogoClick(); }}
          >
            <svg width="26" height="26" viewBox="0 0 32 32" fill="none" aria-hidden="true">
              <path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="#4F6EF7" strokeWidth="2" fill="none" />
              <path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="#4F6EF7" />
            </svg>
            <span className="lp-brand-text">NEXUS</span>
          </div>
          <div className="lp-nav-links">
            <a href="#problemes" className="lp-nav-link">{t('landing_pains_label')}</a>
            <a href="#comment-ca-marche" className="lp-nav-link">{t('nav_how')}</a>
            <a href="#personal" className="lp-nav-link">{t('landing_cta_personal')}</a>
            <a href="#business" className="lp-nav-link">{t('landing_cta_business')}</a>
            <a href="#confiance" className="lp-nav-link">{t('landing_trust_label')}</a>
            <LanguageSwitcher />
            <button type="button" className="lp-btn lp-btn-primary lp-btn-nav" onClick={onLogin}>{t('nav_login')}</button>
          </div>
        </div>
      </nav>

      <main id="main-content">
      <section className="lp-hero" aria-labelledby="lp-hero-heading">
        <div className="lp-hero-torus" aria-hidden="true">
          <TorusField size={640} hue={222} />
        </div>
        {!reduceMotion && <TechOrbits />}
        <div className="lp-hero-grid" aria-hidden="true" />
        <motion.div
          className="lp-container lp-hero-content"
          initial={reduceMotion ? false : 'hidden'}
          animate="visible"
          variants={stagger}
        >
          <motion.p variants={rise} className="lp-brand-hero">NEXUS</motion.p>
          <motion.h1 id="lp-hero-heading" variants={rise} className="lp-hero-title">{t('landing_hero_title')}</motion.h1>
          <motion.p variants={rise} className="lp-hero-lead">{t('landing_hero_subtitle')}</motion.p>
          <motion.div variants={rise} className="lp-hero-actions">
            <motion.button type="button" className="lp-btn lp-btn-primary" onClick={() => onRegister('personal')} {...btnMotion}>
              {t('landing_cta_personal')}
            </motion.button>
            <motion.button type="button" className="lp-btn lp-btn-secondary" onClick={() => onRegister('business')} {...btnMotion}>
              {t('landing_cta_business')}
            </motion.button>
          </motion.div>
          <motion.a variants={rise} href="#problemes" className="lp-hero-scroll">
            {t('landing_cta_link')}
          </motion.a>
        </motion.div>
      </section>

      <div className="lp-below-hero">
        <DigitalBrushes className="lp-plexus" reducedMotion={!!reduceMotion} />

      <section id="problemes" className="lp-section lp-container">
        <GsapReveal effect="fadeUp">
          <div className="lp-section-head">
            <p className="lp-kicker">{t('landing_pains_label')}</p>
            <h2>{t('landing_pains_title')}</h2>
            <p className="lp-section-lead">{t('landing_pains_subtitle')}</p>
          </div>
        </GsapReveal>
        <motion.div
          className="lp-trust-grid"
          initial={reduceMotion ? false : 'hidden'}
          whileInView="visible"
          viewport={{ once: true, margin: '-60px' }}
          variants={stagger}
        >
          {pains.map((item) => (
            <motion.article
              key={item.title}
              className="lp-trust-item lp-pain-item"
              variants={rise}
              whileHover={reduceMotion ? undefined : { y: -4, borderColor: 'rgba(79,110,247,0.45)' }}
            >
              <h3>{item.title}</h3>
              <p>{item.text}</p>
            </motion.article>
          ))}
        </motion.div>
      </section>

      <section id="comment-ca-marche" className="lp-section lp-container">
        <GsapReveal effect="fadeUp">
          <div className="lp-section-head">
            <p className="lp-kicker">{t('landing_steps_label')}</p>
            <h2>{t('landing_steps_title')}</h2>
            <p className="lp-section-lead">{t('landing_steps_subtitle')}</p>
          </div>
        </GsapReveal>
        <motion.ol
          className="lp-steps"
          initial={reduceMotion ? false : 'hidden'}
          whileInView="visible"
          viewport={{ once: true, margin: '-80px' }}
          variants={stagger}
        >
          {steps.map((step) => (
            <motion.li
              key={step.num}
              className="lp-step"
              variants={rise}
              whileHover={reduceMotion ? undefined : { y: -4, transition: { type: 'spring', stiffness: 280, damping: 18 } }}
            >
              <span className="lp-step-num">{step.num}</span>
              <h3>{step.title}</h3>
              <p>{step.text}</p>
            </motion.li>
          ))}
        </motion.ol>
      </section>

      <section id="personal" className="lp-band">
        <GsapReveal effect="fadeUp">
          <div className="lp-container lp-split">
            <div>
              <p className="lp-kicker">{t('landing_personal_label')}</p>
              <h2>{t('landing_personal_title')}</h2>
              <p className="lp-section-lead">{t('landing_personal_text')}</p>
              <motion.button type="button" className="lp-btn lp-btn-primary" onClick={() => onRegister('personal')} {...btnMotion}>
                {t('landing_personal_cta')}
              </motion.button>
            </div>
            <p className="lp-mono-aside" aria-hidden="true">EUR → XAF · send · receive · convert</p>
          </div>
        </GsapReveal>
      </section>

      <section id="business" className="lp-band lp-band-alt">
        <GsapReveal effect="fadeUp">
          <div className="lp-container lp-split">
            <div>
              <p className="lp-kicker">{t('landing_business_label')}</p>
              <h2>{t('landing_business_title')}</h2>
              <p className="lp-section-lead">{t('landing_business_text')}</p>
              <motion.button type="button" className="lp-btn lp-btn-primary" onClick={() => onRegister('business')} {...btnMotion}>
                {t('landing_business_cta')}
              </motion.button>
            </div>
            <p className="lp-mono-aside" aria-hidden="true">payments · treasury · multi-provider</p>
          </div>
        </GsapReveal>
      </section>

      <section id="confiance" className="lp-section lp-container">
        <GsapReveal effect="fadeUp">
          <div className="lp-section-head">
            <p className="lp-kicker">{t('landing_trust_label')}</p>
            <h2>{t('landing_trust_title')}</h2>
          </div>
        </GsapReveal>
        <motion.div
          className="lp-trust-grid"
          initial={reduceMotion ? false : 'hidden'}
          whileInView="visible"
          viewport={{ once: true, margin: '-60px' }}
          variants={stagger}
        >
          {trust.map((item) => (
            <motion.article
              key={item.title}
              className="lp-trust-item"
              variants={rise}
              whileHover={reduceMotion ? undefined : { y: -4, borderColor: 'rgba(79,110,247,0.45)' }}
            >
              <h3>{item.title}</h3>
              <p>{item.text}</p>
            </motion.article>
          ))}
        </motion.div>
      </section>

      <GsapReveal effect="fadeUp" as="section" className="lp-final">
        <div className="lp-container lp-final-inner">
          <h2>{t('landing_cta_section_heading')}</h2>
          <p className="lp-section-lead">{t('landing_cta_section_text')}</p>
          <div className="lp-hero-actions">
            <motion.button type="button" className="lp-btn lp-btn-primary" onClick={() => onRegister('personal')} {...btnMotion}>
              {t('landing_cta_personal')}
            </motion.button>
            <motion.button type="button" className="lp-btn lp-btn-secondary" onClick={() => onRegister('business')} {...btnMotion}>
              {t('landing_cta_business')}
            </motion.button>
          </div>
        </div>
      </GsapReveal>

      <footer className="lp-footer">
        <div className="lp-footer-inner">
          <div className="lp-footer-brand">
            <svg width="18" height="18" viewBox="0 0 32 32" fill="none" aria-hidden="true">
              <path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="#4F6EF7" strokeWidth="2" fill="none" />
              <path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="#4F6EF7" />
            </svg>
            <span>NEXUS CORP TECHNOLOGIES</span>
          </div>
          <div className="lp-footer-links">
            <Link to="/docs" className="lp-footer-link">Documentation</Link>
            <Link to="/privacy" className="lp-footer-link">{t('footer_privacy')}</Link>
            <Link to="/terms" className="lp-footer-link">{t('footer_terms')}</Link>
            <Link to="/support" className="lp-footer-link">Support</Link>
          </div>
          <p className="lp-footer-copy">{t('footer_copy')}</p>
        </div>
      </footer>
      </div>
      </main>

      {/* Entrée discrète — login employés (pas de hint visible) */}
      <Link to="/staff-login" className="lp-staff-entry" aria-label="Espace employés">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <rect x="3.5" y="7" width="17" height="12.5" rx="2" stroke="currentColor" strokeWidth="1.4" />
          <path d="M8 7V5.8A2.8 2.8 0 0 1 10.8 3h2.4A2.8 2.8 0 0 1 16 5.8V7" stroke="currentColor" strokeWidth="1.4" />
          <circle cx="12" cy="13.2" r="1.35" fill="currentColor" />
          <path d="M9.2 17.2c.6-1.1 1.6-1.7 2.8-1.7s2.2.6 2.8 1.7" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" />
        </svg>
      </Link>
    </div>
  );
}
