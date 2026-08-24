import { useEffect, useRef } from 'react';

interface ParticlesBackgroundProps {
  density?: number;
  color?: string;
  className?: string;
  opacity?: number;
  /** Draw distance links between nearby particles (expensive O(n²)). Default true. */
  links?: boolean;
}

/**
 * Fond particules derrière le contenu (wrapper z-index: 0).
 * L'opacité est appliquée uniquement au dessin canvas — pas sur le wrapper CSS —
 * pour éviter le double-écrasement (ex. revolut 0.12 × prop) qui faisait
 * disparaître l'ambiance animée des dashboards / Envoyer.
 */
export function ParticlesBackground({
  density = 60,
  color = '#8B5CF6',
  className = '',
  opacity = 0.6,
  links = true,
}: ParticlesBackgroundProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d', { alpha: true });
    if (!ctx) return;

    let raf = 0;
    let width = 0;
    let height = 0;
    let resizeTimer = 0;
    let scrollIdleTimer = 0;
    let paused = false;
    let frame = 0;
    const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    interface Particle {
      x: number;
      y: number;
      r: number;
      vx: number;
      vy: number;
      alpha: number;
    }

    let particles: Particle[] = [];

    const measure = () => {
      const parent = canvas.parentElement;
      const pw = parent?.clientWidth ?? 0;
      const ph = parent?.clientHeight ?? 0;
      // Fixed layers can report 0 before first layout — never stick at empty size.
      width = pw > 2 ? pw : window.innerWidth;
      height = ph > 2 ? ph : window.innerHeight;
    };

    const init = () => {
      measure();
      if (width < 2 || height < 2) return;

      canvas.width = Math.floor(width * dpr);
      canvas.height = Math.floor(height * dpr);
      canvas.style.width = `${width}px`;
      canvas.style.height = `${height}px`;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      // Hard cap keeps link drawing cheap even on large displays.
      const count = Math.min(72, Math.floor((width * height) / 28000) + Math.min(density, 36));
      particles = Array.from({ length: count }, () => ({
        x: Math.random() * width,
        y: Math.random() * height,
        r: Math.random() * 1.5 + 0.4,
        vx: (Math.random() - 0.5) * 0.22,
        vy: (Math.random() - 0.5) * 0.22,
        alpha: Math.random() * 0.55 + 0.25,
      }));
    };

    const drawLinks = () => {
      const n = particles.length;
      const maxDist = 95;
      const maxDist2 = maxDist * maxDist;
      const cell = maxDist;
      const cols = Math.max(1, Math.ceil(width / cell));
      const buckets = new Map<number, number[]>();

      for (let i = 0; i < n; i++) {
        const p = particles[i];
        const key = Math.floor(p.y / cell) * cols + Math.floor(p.x / cell);
        const bucket = buckets.get(key);
        if (bucket) bucket.push(i);
        else buckets.set(key, [i]);
      }

      for (let i = 0; i < n; i++) {
        const a = particles[i];
        const cx = Math.floor(a.x / cell);
        const cy = Math.floor(a.y / cell);
        for (let oy = -1; oy <= 1; oy++) {
          for (let ox = -1; ox <= 1; ox++) {
            const neighbors = buckets.get((cy + oy) * cols + (cx + ox));
            if (!neighbors) continue;
            for (const j of neighbors) {
              if (j <= i) continue;
              const b = particles[j];
              const dx = a.x - b.x;
              const dy = a.y - b.y;
              const dist2 = dx * dx + dy * dy;
              if (dist2 >= maxDist2) continue;
              const dist = Math.sqrt(dist2);
              ctx.beginPath();
              ctx.moveTo(a.x, a.y);
              ctx.lineTo(b.x, b.y);
              ctx.strokeStyle = color;
              ctx.globalAlpha = (1 - dist / maxDist) * 0.18 * opacity;
              ctx.lineWidth = 0.55;
              ctx.stroke();
            }
          }
        }
      }
    };

    const draw = () => {
      if (paused || document.hidden) {
        raf = 0;
        return;
      }
      if (width < 2 || height < 2 || particles.length === 0) {
        measure();
        if (width >= 2 && height >= 2 && particles.length === 0) init();
        raf = reducedMotion ? 0 : requestAnimationFrame(draw);
        return;
      }

      frame += 1;
      // ~30fps while scrolling pressure is high (odd frames skipped when paused briefly)
      ctx.clearRect(0, 0, width, height);

      for (const p of particles) {
        p.x += p.vx;
        p.y += p.vy;

        if (p.x < -5) p.x = width + 5;
        if (p.x > width + 5) p.x = -5;
        if (p.y < -5) p.y = height + 5;
        if (p.y > height + 5) p.y = -5;

        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fillStyle = color;
        ctx.globalAlpha = p.alpha * opacity;
        ctx.fill();
      }

      // Links every other frame — halves stroke cost with little visual loss.
      if (links && frame % 2 === 0) {
        drawLinks();
      }

      ctx.globalAlpha = 1;
      raf = reducedMotion ? 0 : requestAnimationFrame(draw);
    };

    const start = () => {
      if (raf || paused || document.hidden) return;
      raf = requestAnimationFrame(draw);
    };

    const stop = () => {
      cancelAnimationFrame(raf);
      raf = 0;
    };

    init();
    start();

    const onResize = () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => {
        stop();
        init();
        start();
      }, 120);
    };

    // Free the main thread while the user is actively scrolling.
    const onScroll = () => {
      if (reducedMotion) return;
      paused = true;
      stop();
      window.clearTimeout(scrollIdleTimer);
      scrollIdleTimer = window.setTimeout(() => {
        paused = false;
        start();
      }, 140);
    };

    const onVisibility = () => {
      if (document.hidden) stop();
      else if (!paused) start();
    };

    window.addEventListener('resize', onResize);
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
      stop();
      window.clearTimeout(resizeTimer);
      window.clearTimeout(scrollIdleTimer);
      window.removeEventListener('resize', onResize);
      window.removeEventListener('scroll', onScroll);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [density, color, opacity, links]);

  return (
    <div className={`particles-layer ${className}`} aria-hidden="true">
      <canvas ref={canvasRef} className="particles-bg" />
    </div>
  );
}
