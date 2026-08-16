import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

/* ════════════════════════════════════════════════════════════════════════
 * GSAP — reveals & effets avancés (ScrollTrigger)
 * Utilisé pour des entrées scénographiques plus riches que les reveals
 * basiques de framer-motion (clip, flou, skew, parallax d'opacité).
 * ════════════════════════════════════════════════════════════════════════ */

type Effect = 'fadeUp' | 'clipReveal' | 'blurIn' | 'skewIn' | 'zoomIn' | 'slideRight' | 'slideLeft';

/** Reveal au scroll avec effet GSAP avancé. */
export function GsapReveal({ children, effect = 'fadeUp', delay = 0, duration = 0.9, className, style, as = 'div' }: {
  children: ReactNode; effect?: Effect; delay?: number; duration?: number; className?: string;
  style?: React.CSSProperties; as?: 'div' | 'section' | 'article';
}) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = ref.current; if (!el) return;
    const from: gsap.TweenVars = { opacity: 0, duration, delay, ease: 'power3.out' };
    switch (effect) {
      case 'fadeUp': Object.assign(from, { y: 46 }); break;
      case 'clipReveal': Object.assign(from, { clipPath: 'inset(0 100% 0 0)' }); break;
      case 'blurIn': Object.assign(from, { filter: 'blur(12px)', y: 16 }); break;
      case 'skewIn': Object.assign(from, { skewY: 3, y: 40 }); break;
      case 'zoomIn': Object.assign(from, { scale: 0.9, y: 20 }); break;
      case 'slideRight': Object.assign(from, { x: 60 }); break;
      case 'slideLeft': Object.assign(from, { x: -60 }); break;
    }
    const tween = gsap.fromTo(el, from, { opacity: 1, x: 0, y: 0, scale: 1, skewY: 0, filter: 'blur(0px)', clipPath: 'inset(0 0% 0 0)' });
    ScrollTrigger.create({ trigger: el, start: 'top 88%', once: true, onEnter: () => tween.play() });
    tween.pause();
    return () => { tween.kill(); ScrollTrigger.getAll().forEach((t) => t.trigger === el && t.kill()); };
  }, [effect, delay, duration]);

  const Tag = as as any;
  return <Tag ref={ref} className={className} style={style}>{children}</Tag>;
}

/** GSAP — parallax au scroll (background/éléments déplacés à des vitesses différentes). */
export function GsapParallax({ children, speed = 0.18, className, style }: {
  children: ReactNode; speed?: number; className?: string; style?: React.CSSProperties;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = ref.current; if (!el) return;
    const tween = gsap.fromTo(el, { yPercent: -speed * 100 }, { yPercent: speed * 100, ease: 'none' });
    ScrollTrigger.create({ trigger: el, start: 'top bottom', end: 'bottom top', scrub: true, animation: tween });
    return () => { tween.kill(); ScrollTrigger.getAll().forEach((t) => t.trigger === el && t.kill()); };
  }, [speed]);
  return <div ref={ref} className={className} style={style}>{children}</div>;
}
