import React, { useEffect, useRef, useState } from 'react';
import { motion, AnimatePresence, useInView, useMotionValue, useSpring } from 'framer-motion';

/* ════════════════════════════════════════════════════════════════════════
 * PREMIUM — animations React ultra haut de gamme
 * Base : framer-motion 13 (springs, stagger, layout) + gsap pour les effets.
 * ════════════════════════════════════════════════════════════════════════ */

export const EASE = [0.16, 1, 0.3, 1] as const;
export const SPRING_SOFT = { type: 'spring', stiffness: 90, damping: 18, mass: 1 } as const;

/* ── Compteur animé (KPI / montants) ───────────────────────────────────── */
export function AnimatedNumber({
  value, duration = 1.4, prefix = '', suffix = '', decimals = 0,
  format = (v: number) => v.toLocaleString('fr-FR'),
}: {
  value: number; duration?: number; prefix?: string; suffix?: string; decimals?: number;
  format?: (v: number) => string;
}) {
  const ref = useRef<HTMLSpanElement>(null);
  const inView = useInView(ref, { once: true, margin: '-40px' });
  const [display, setDisplay] = useState(0);
  const motionValue = useMotionValue(0);
  const spring = useSpring(motionValue, { stiffness: 55, damping: 22, mass: 1.1 });

  useEffect(() => {
    if (!inView) return;
    motionValue.set(0);
    const start = performance.now();
    let raf = 0;
    const tick = (t: number) => {
      const p = Math.min((t - start) / (duration * 1000), 1);
      const eased = 1 - Math.pow(1 - p, 4); // easeOutQuart
      motionValue.set(value * eased);
      if (p < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [inView, value, duration, motionValue]);

  useEffect(() => spring.on('change', (v) => setDisplay(v)), [spring]);

  return (
    <span ref={ref} style={{ display: 'inline-block', fontVariantNumeric: 'tabular-nums' }}>
      {prefix}{format(decimals > 0 ? Number(display.toFixed(decimals)) : Math.round(display))}{suffix}
    </span>
  );
}

/* ── Révélation staggered (conteneurs) ────────────────────────────────── */
export const staggerContainer = (stagger = 0.07, delay = 0) => ({
  hidden: {},
  show: { transition: { staggerChildren: stagger, delayChildren: delay } },
});
export const fadeUp = {
  hidden: { opacity: 0, y: 22, scale: 0.99 },
  show: { opacity: 1, y: 0, scale: 1, transition: { duration: 0.55, ease: EASE } },
};

export function RevealGroup({ children, className, stagger = 0.07, delay = 0 }: {
  children: React.ReactNode; className?: string; stagger?: number; delay?: number;
}) {
  return (
    <motion.div
      className={className}
      variants={staggerContainer(stagger, delay)}
      initial="hidden"
      animate="show"
      style={{ width: '100%' }}
    >
      {children}
    </motion.div>
  );
}

/* ── Carte premium avec micro-interaction hover (lift + glow) ─────────── */
export function HoverCard({ children, className, style, glow = '#3B82F6', index = 0 }: {
  children: React.ReactNode; className?: string; style?: React.CSSProperties; glow?: string; index?: number;
}) {
  const [hovered, setHovered] = useState(false);
  return (
    <motion.div
      className={className}
      variants={fadeUp}
      style={style}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      whileHover={{ y: -6, scale: 1.015, transition: { type: 'spring', stiffness: 260, damping: 18 } }}
      whileTap={{ scale: 0.985 }}
      transition={{ delay: index * 0.03 }}
    >
      <div className={`premium-hover${hovered ? ' is-hover' : ''}`} style={{ ['--glow' as string]: glow }} />
      {children}
    </motion.div>
  );
}

/* ── Transition de section fluide (remplace le rendu brut) ────────────── */
export function SectionTransition({ children, id }: { children: React.ReactNode; id: string | number }) {
  return (
    <AnimatePresence mode="wait">
      <motion.div
        key={id}
        initial={{ opacity: 0, y: 16, scale: 0.995 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        exit={{ opacity: 0, y: -10, scale: 0.995 }}
        transition={{ duration: 0.32, ease: EASE }}
      >
        {children}
      </motion.div>
    </AnimatePresence>
  );
}

/* ── Texte premium avec entrée lettre par lettre (titre) ──────────────── */
export function AnimatedTitle({ text, className }: { text: string; className?: string }) {
  const letters = Array.from(text);
  return (
    <motion.span
      className={className}
      initial="hidden"
      animate="show"
      transition={{ staggerChildren: 0.02 }}
      aria-label={text}
    >
      {letters.map((ch, i) => (
        <motion.span
          key={i}
          variants={{
            hidden: { opacity: 0, y: 12, filter: 'blur(6px)' },
            show: { opacity: 1, y: 0, filter: 'blur(0px)', transition: { duration: 0.5, ease: EASE } },
          }}
          style={{ display: 'inline-block', whiteSpace: 'pre' }}
        >
          {ch}
        </motion.span>
      ))}
    </motion.span>
  );
}

/* ── Barre de progression animée ──────────────────────────────────────── */
export function AnimatedBar({ value, color = 'var(--cyan)', delay = 0, height = 6 }: {
  value: number; color?: string; delay?: number; height?: number;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const inView = useInView(ref, { once: true, margin: '-20px' });
  const pct = Math.max(0, Math.min(100, value));
  return (
    <div ref={ref} style={{ height, background: 'var(--panel2)', borderRadius: 6, overflow: 'hidden', width: '100%' }}>
      <motion.div
        initial={{ width: '0%' }}
        animate={inView ? { width: `${pct}%` } : {}}
        transition={{ duration: 1.1, ease: EASE, delay }}
        style={{ height: '100%', borderRadius: 6, background: `linear-gradient(90deg, transparent, ${color})` }}
      />
    </div>
  );
}

/* ── Pulsation / éclat ambiant (indicateur "live") ────────────────────── */
export function LivePulse({ color = 'var(--green)' }: { color?: string }) {
  return (
    <span className="live-pulse" style={{ ['--pulse' as string]: color }}>
      <span className="live-dot" />
    </span>
  );
}
