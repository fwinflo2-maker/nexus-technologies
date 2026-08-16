import { useEffect, useRef, useState } from 'react';

/**
 * Compteur animé (easing). Affiche `value` en le faisant défiler depuis sa
 * valeur précédente. Sert à donner vie aux soldes/volumes sans ré-animer en
 * boucle — une seule transition par changement de valeur.
 *
 * Respecte prefers-reduced-motion : affiche directement la valeur finale.
 */
export default function AnimatedCounter({
  value,
  duration = 900,
  format,
  className,
  locale = 'fr-FR',
}: {
  value: number;
  duration?: number;
  format?: (n: number) => string;
  className?: string;
  locale?: string;
}) {
  const [display, setDisplay] = useState(value);
  const fromRef = useRef(value);
  const rafRef = useRef<number | null>(null);

  useEffect(() => {
    const from = fromRef.current;
    if (from === value) return;
    const start = performance.now();

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) {
      fromRef.current = value;
      setDisplay(value);
      return;
    }

    const tick = (now: number) => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      const next = from + (value - from) * eased;
      setDisplay(next);
      if (p < 1) {
        rafRef.current = requestAnimationFrame(tick);
      } else {
        fromRef.current = value;
      }
    };
    rafRef.current = requestAnimationFrame(tick);

    return () => {
      if (rafRef.current) cancelAnimationFrame(rafRef.current);
    };
  }, [value, duration]);

  return (
    <span className={className}>
      {format ? format(display) : display.toLocaleString(locale)}
    </span>
  );
}
