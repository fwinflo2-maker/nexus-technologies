import { useEffect, useRef } from 'react';
import './DigitalBrushes.css';

interface DigitalBrushesProps {
  className?: string;
  /** Primary node/line color (landing signal blue). */
  color?: string;
  /** Soft cyan highlight for nearer / brighter nodes. */
  accent?: string;
  /** When true, draw one static frame and skip the rAF loop. */
  reducedMotion?: boolean;
}

type Node = {
  bx: number;
  by: number;
  z: number;
  phase: number;
  speed: number;
  size: number;
};

/**
 * Plexus / network backdrop — nodes + distance-linked lines with gentle
 * undulation. Scoped for landing sections below the hero (not torus/orbits).
 */
export function DigitalBrushes({
  className = '',
  color = '#4F6EF7',
  accent = '#7EB6D9',
  reducedMotion = false,
}: DigitalBrushesProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const dprCap = Math.min(window.devicePixelRatio || 1, 1.25);
    let width = 0;
    let height = 0;
    let raf = 0;
    let resizeTimer = 0;
    let scrollIdleTimer = 0;
    let t0 = performance.now();
    let nodes: Node[] = [];
    let linkDist = 110;
    let inView = true;
    let scrolling = false;
    let lastPaint = 0;
    const minFrameMs = 1000 / 28;

    const parseRgb = (hex: string): [number, number, number] => {
      const h = hex.replace('#', '');
      const n = h.length === 3
        ? h.split('').map((c) => parseInt(c + c, 16))
        : [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
      return [n[0] ?? 79, n[1] ?? 110, n[2] ?? 247];
    };

    const [cr, cg, cb] = parseRgb(color);
    const [ar, ag, ab] = parseRgb(accent);

    const seedNodes = () => {
      const area = width * height;
      // Density scales with area; hard cap keeps scroll smooth.
      const target = Math.min(72, Math.max(32, Math.floor(area / 22000)));
      const cols = Math.max(5, Math.round(Math.sqrt(target * (width / Math.max(height, 1)))));
      const rows = Math.max(4, Math.ceil(target / cols));
      const cellW = width / cols;
      const cellH = height / rows;
      linkDist = Math.min(130, Math.max(72, Math.min(cellW, cellH) * 1.7));

      nodes = [];
      for (let r = 0; r < rows; r += 1) {
        for (let c = 0; c < cols; c += 1) {
          const jitterX = (Math.random() - 0.5) * cellW * 0.72;
          const jitterY = (Math.random() - 0.5) * cellH * 0.72;
          nodes.push({
            bx: (c + 0.5) * cellW + jitterX,
            by: (r + 0.5) * cellH + jitterY,
            z: Math.random(),
            phase: Math.random() * Math.PI * 2,
            speed: 0.35 + Math.random() * 0.55,
            size: 0.7 + Math.random() * 1.6,
          });
        }
      }
    };

    const resize = () => {
      const parent = canvas.parentElement;
      width = parent?.clientWidth || window.innerWidth;
      height = parent?.clientHeight || window.innerHeight;
      if (width < 2 || height < 2) return;

      canvas.width = Math.floor(width * dprCap);
      canvas.height = Math.floor(height * dprCap);
      canvas.style.width = `${width}px`;
      canvas.style.height = `${height}px`;
      ctx.setTransform(dprCap, 0, 0, dprCap, 0, 0);
      seedNodes();
    };

    const project = (n: Node, time: number) => {
      const wave =
        Math.sin(time * n.speed + n.phase + n.bx * 0.0045) * 18 +
        Math.cos(time * n.speed * 0.72 + n.phase * 1.3 + n.by * 0.0038) * 14;
      const driftX = Math.sin(time * 0.22 + n.phase) * 10;
      const driftY = Math.cos(time * 0.18 + n.phase * 0.9) * 8;
      const depth = 0.55 + n.z * 0.45 + wave * 0.008;
      return {
        x: n.bx + driftX + wave * 0.15,
        y: n.by + driftY + wave,
        depth: Math.max(0.35, Math.min(1.15, depth)),
        glow: 0.35 + n.z * 0.45 + Math.max(0, wave) * 0.012,
      };
    };

    const render = (now: number) => {
      if (width < 2 || height < 2 || nodes.length === 0) return;
      ctx.clearRect(0, 0, width, height);
      const time = reducedMotion ? 0 : (now - t0) * 0.001;

      const pts = nodes.map((n) => project(n, time));
      const maxD2 = linkDist * linkDist;
      const cell = linkDist;
      const cols = Math.max(1, Math.ceil(width / cell));
      const buckets = new Map<number, number[]>();

      for (let i = 0; i < pts.length; i += 1) {
        const p = pts[i];
        const cx = Math.floor(p.x / cell);
        const cy = Math.floor(p.y / cell);
        const key = cy * cols + cx;
        const bucket = buckets.get(key);
        if (bucket) bucket.push(i);
        else buckets.set(key, [i]);
      }

      // Lines first (behind nodes) — neighbor cells only.
      ctx.lineCap = 'round';
      for (let i = 0; i < pts.length; i += 1) {
        const a = pts[i];
        const cx = Math.floor(a.x / cell);
        const cy = Math.floor(a.y / cell);
        for (let oy = -1; oy <= 1; oy += 1) {
          for (let ox = -1; ox <= 1; ox += 1) {
            const neighbors = buckets.get((cy + oy) * cols + (cx + ox));
            if (!neighbors) continue;
            for (const j of neighbors) {
              if (j <= i) continue;
              const b = pts[j];
              const dx = a.x - b.x;
              const dy = a.y - b.y;
              const d2 = dx * dx + dy * dy;
              if (d2 > maxD2) continue;
              const t = 1 - Math.sqrt(d2) / linkDist;
              const midDepth = (a.depth + b.depth) * 0.5;
              const alpha = t * t * 0.28 * midDepth;
              if (alpha < 0.02) continue;
              ctx.strokeStyle = `rgba(${cr},${cg},${cb},${alpha})`;
              ctx.lineWidth = 0.55 + t * 0.9 * midDepth;
              ctx.beginPath();
              ctx.moveTo(a.x, a.y);
              ctx.lineTo(b.x, b.y);
              ctx.stroke();
            }
          }
        }
      }

      // Nodes only — skip radial bloom gradients (very costly while scrolling).
      for (let i = 0; i < pts.length; i += 1) {
        const p = pts[i];
        const n = nodes[i];
        const r = n.size * (0.7 + p.depth * 0.55);
        const useAccent = p.glow > 0.72;
        const [rr, gg, bb] = useAccent ? [ar, ag, ab] : [cr, cg, cb];
        const alpha = 0.22 + p.glow * 0.45;

        ctx.fillStyle = `rgba(${rr},${gg},${bb},${Math.min(0.95, alpha + 0.25)})`;
        ctx.beginPath();
        ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
        ctx.fill();
      }
    };

    const stop = () => {
      cancelAnimationFrame(raf);
      raf = 0;
    };

    const loop = (now: number) => {
      if (!inView || scrolling || document.hidden) {
        raf = 0;
        return;
      }
      if (now - lastPaint >= minFrameMs) {
        lastPaint = now;
        render(now);
      }
      raf = requestAnimationFrame(loop);
    };

    const start = () => {
      if (raf || !inView || scrolling || document.hidden || reducedMotion) return;
      raf = requestAnimationFrame(loop);
    };

    const onResize = () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => {
        resize();
        if (reducedMotion) {
          render(performance.now());
        }
      }, 80);
    };

    resize();

    if (reducedMotion) {
      render(performance.now());
      window.addEventListener('resize', onResize);
      return () => {
        window.clearTimeout(resizeTimer);
        window.removeEventListener('resize', onResize);
      };
    }

    const onVisibilityChange = () => {
      stop();
      if (!document.hidden) start();
    };

    const onScroll = () => {
      scrolling = true;
      stop();
      window.clearTimeout(scrollIdleTimer);
      scrollIdleTimer = window.setTimeout(() => {
        scrolling = false;
        start();
      }, 140);
    };

    const io = typeof IntersectionObserver !== 'undefined'
      ? new IntersectionObserver(
        ([entry]) => {
          inView = entry?.isIntersecting ?? true;
          if (inView) start();
          else stop();
        },
        { rootMargin: '80px', threshold: 0 },
      )
      : null;
    io?.observe(canvas);

    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('resize', onResize);
    window.addEventListener('scroll', onScroll, { passive: true });
    start();

    return () => {
      stop();
      window.clearTimeout(resizeTimer);
      window.clearTimeout(scrollIdleTimer);
      io?.disconnect();
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('resize', onResize);
      window.removeEventListener('scroll', onScroll);
    };
  }, [color, accent, reducedMotion]);

  return (
    <div className={`digital-brushes ${className}`.trim()} aria-hidden="true">
      <canvas ref={canvasRef} />
    </div>
  );
}
