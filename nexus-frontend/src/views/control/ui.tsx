/**
 * NEXUS CONTROL CENTER — primitives du design system.
 *
 * Un plan de contrôle d'infrastructure : dense, sobre, lisible. Aucune
 * décoration qui pourrait faire passer une inconnue pour une certitude.
 *
 * Règle de couleur (§23) :
 *   vert   = fait vérifié          rouge  = échec constaté
 *   ambre  = déclaré, non prouvé   gris   = inconnu / non testé
 * Le gris n'est jamais un échec : c'est l'absence de preuve.
 */
import type { CSSProperties, ReactNode } from 'react';

export const C = {
  bg: '#0B0D10',
  panel: 'rgba(20,23,28,0.72)',
  panelSolid: '#14171C',
  border: 'rgba(120,150,190,0.14)',
  borderStrong: 'rgba(120,150,190,0.26)',
  text: '#F2F4F7',
  dim: '#8A94A0',
  faint: '#5A6572',
  accent: '#3B82F6',
  ok: '#10B981',
  warn: '#3B82F6',
  danger: '#EF4444',
  unknown: '#6B7C95',
} as const;

export const MONO = "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace";

/** Carte de section. */
export function Panel({ title, subtitle, right, children, style }: {
  title?: ReactNode; subtitle?: ReactNode; right?: ReactNode; children: ReactNode; style?: CSSProperties;
}) {
  return (
    <section style={{
      background: C.panel,
      border: `1px solid ${C.border}`,
      borderRadius: 14,
      padding: 18,
      backdropFilter: 'blur(8px)',
      ...style,
    }}>
      {(title || right) && (
        <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: subtitle ? 6 : 14 }}>
          <div>
            {title && <h2 style={{ margin: 0, fontSize: 15, fontWeight: 650, letterSpacing: 0.2, color: C.text }}>{title}</h2>}
            {subtitle && <p style={{ margin: '6px 0 0', fontSize: 12.5, lineHeight: 1.5, color: C.dim, maxWidth: 780 }}>{subtitle}</p>}
          </div>
          {right}
        </header>
      )}
      {subtitle && <div style={{ height: 10 }} />}
      {children}
    </section>
  );
}

/** Indicateur chiffré. */
export function Metric({ label, value, tone = 'neutral', hint }: {
  label: string; value: ReactNode; tone?: 'neutral' | 'ok' | 'warn' | 'danger' | 'unknown'; hint?: string;
}) {
  const color = tone === 'ok' ? C.ok : tone === 'warn' ? C.warn : tone === 'danger' ? C.danger : tone === 'unknown' ? C.unknown : C.text;
  return (
    <div style={{
      background: 'rgba(255,255,255,0.02)',
      border: `1px solid ${C.border}`,
      borderRadius: 12,
      padding: '14px 16px',
      minWidth: 0,
    }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.7, color: C.dim, marginBottom: 8 }}>{label}</div>
      <div style={{ fontSize: 26, fontWeight: 700, color, fontFamily: MONO, lineHeight: 1 }}>{value}</div>
      {hint && <div style={{ fontSize: 11, color: C.dim, marginTop: 8, lineHeight: 1.4 }}>{hint}</div>}
    </div>
  );
}

export type BadgeTone = 'ok' | 'warn' | 'danger' | 'unknown' | 'info';

/** Pastille d'état. */
export function Badge({ tone, children, title }: { tone: BadgeTone; children: ReactNode; title?: string }) {
  const map: Record<BadgeTone, { fg: string; bg: string }> = {
    ok: { fg: C.ok, bg: 'rgba(34,201,138,0.12)' },
    warn: { fg: C.warn, bg: 'rgba(234,182,72,0.12)' },
    danger: { fg: C.danger, bg: 'rgba(239,77,106,0.12)' },
    unknown: { fg: C.unknown, bg: 'rgba(107,124,149,0.14)' },
    info: { fg: C.accent, bg: 'rgba(0,200,255,0.10)' },
  };
  const { fg, bg } = map[tone];
  return (
    <span title={title} style={{
      display: 'inline-block',
      padding: '3px 9px',
      borderRadius: 999,
      fontSize: 11,
      fontWeight: 600,
      letterSpacing: 0.3,
      color: fg,
      background: bg,
      border: `1px solid ${fg}33`,
      whiteSpace: 'nowrap',
    }}>{children}</span>
  );
}

/** Note explicative — sert à dire ce que l'écran ne prouve PAS. */
export function Note({ children, tone = 'info' }: { children: ReactNode; tone?: 'info' | 'warn' }) {
  const fg = tone === 'warn' ? C.warn : C.accent;
  return (
    <p style={{
      margin: '0 0 14px',
      padding: '10px 13px',
      borderInlineStart: `2px solid ${fg}`,
      background: `${fg}0d`,
      borderRadius: 6,
      fontSize: 12.5,
      lineHeight: 1.55,
      color: C.dim,
    }}>{children}</p>
  );
}

/** Tableau dense. */
export function Table({ head, children }: { head: ReactNode[]; children: ReactNode }) {
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
        <thead>
          <tr>
            {head.map((h, i) => (
              <th key={i} style={{
                textAlign: 'start',
                padding: '9px 12px',
                fontSize: 10.5,
                textTransform: 'uppercase',
                letterSpacing: 0.7,
                color: C.dim,
                fontWeight: 600,
                borderBottom: `1px solid ${C.borderStrong}`,
                whiteSpace: 'nowrap',
              }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

export function Td({ children, mono, style }: { children: ReactNode; mono?: boolean; style?: CSSProperties }) {
  return (
    <td style={{
      padding: '10px 12px',
      borderBottom: `1px solid ${C.border}`,
      color: C.text,
      fontFamily: mono ? MONO : undefined,
      verticalAlign: 'middle',
      ...style,
    }}>{children}</td>
  );
}

/** État de chargement / d'erreur / vide — jamais un écran muet. */
export function State({ kind, label, onRetry, retryLabel }: {
  kind: 'loading' | 'error' | 'empty'; label: string; onRetry?: () => void; retryLabel?: string;
}) {
  return (
    <div style={{
      padding: '38px 20px',
      textAlign: 'center',
      color: kind === 'error' ? C.danger : C.dim,
      fontSize: 13,
    }}>
      <div>{label}</div>
      {onRetry && (
        <button onClick={onRetry} style={{
          marginTop: 14,
          padding: '7px 16px',
          borderRadius: 8,
          border: `1px solid ${C.borderStrong}`,
          background: 'transparent',
          color: C.text,
          fontSize: 12.5,
          cursor: 'pointer',
        }}>{retryLabel ?? 'Retry'}</button>
      )}
    </div>
  );
}

/** Grille responsive d'indicateurs. */
export function Grid({ min = 190, children, style }: { min?: number; children: ReactNode; style?: CSSProperties }) {
  return (
    <div style={{
      display: 'grid',
      gridTemplateColumns: `repeat(auto-fit, minmax(${min}px, 1fr))`,
      gap: 12,
      ...style,
    }}>{children}</div>
  );
}

/** Traduit un statut documentaire en pastille (§22). */
export function docTone(status: string): BadgeTone {
  if (status === 'verified') return 'ok';
  if (status === 'declared') return 'warn';
  return 'unknown';
}
