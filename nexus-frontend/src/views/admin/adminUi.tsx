import React from 'react';

export const fmtNum = (n: number) => n.toLocaleString('fr-FR');
export const fmtMoney = (n: number, cur = '') => `${fmtNum(Math.round(n))}${cur ? ' ' + cur : ''}`;
export const fmtDate = (s: string) =>
  s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

/** Ligne clé/valeur pour les panneaux récapitulatifs. */
export function Row({ k, v, tone }: { k: string; v: React.ReactNode; tone?: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '6px 0', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
      <span style={{ color: 'var(--text-dim)' }}>{k}</span>
      <span style={{ color: tone || 'var(--text-bright)', fontWeight: 600, textAlign: 'right' }}>{v}</span>
    </div>
  );
}

/** Badge de statut avec couleur par sémantique. */
export function Badge({ status, label }: { status: string; label?: string }) {
  const s = (label ?? status).toLowerCase();
  const color = s === 'completed' || s === 'verified' || s === 'active' || s === 'operational' || s === 'approved'
    ? 'var(--green)'
    : s === 'failed' || s === 'rejected' || s === 'suspended' || s === 'down' || s === 'error'
      ? 'var(--red)'
      : s === 'processing' || s === 'pending' || s === 'in_progress' || s === 'on_hold'
        ? 'var(--gold)'
        : 'var(--cyan2)';
  return (
    <span style={{
      fontSize: 11, fontWeight: 700, color, background: 'rgba(255,255,255,0.03)',
      border: `1px solid ${color}55`, padding: '2px 9px', borderRadius: 20, textTransform: 'uppercase', letterSpacing: 0.4,
    }}>{label ?? status}</span>
  );
}

export function Stat({ label, value, sub, tone }: { label: string; value: React.ReactNode; sub?: string; tone?: string }) {
  return (
    <div className="card" style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 2 }}>
      <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', letterSpacing: 0.4, textTransform: 'uppercase' }}>{label}</span>
      <span style={{ fontSize: 22, fontWeight: 800, color: tone || 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{value}</span>
      {sub && <span style={{ fontSize: 11, color: 'var(--text-dim)' }}>{sub}</span>}
    </div>
  );
}

export function Panel({ title, icon, right, children, pad = 14 }: {
  title: string; icon?: string; right?: React.ReactNode; children: React.ReactNode; pad?: number;
}) {
  return (
    <div className="card" style={{ padding: pad, display: 'flex', flexDirection: 'column', gap: 10 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {icon && <span style={{ fontSize: 14 }}>{icon}</span>}
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>{title}</span>
        </div>
        {right}
      </div>
      {children}
    </div>
  );
}

export function Table({ head, rows, empty = 'Aucune donnée.' }: {
  head: string[]; rows: Array<Array<React.ReactNode>>; empty?: string;
}) {
  if (rows.length === 0) return <div style={{ padding: 24, textAlign: 'center', fontSize: 12.5, color: 'var(--text-dim)' }}>{empty}</div>;
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
        <thead>
          <tr>{head.map((h, i) => (
            <th key={i} style={{ textAlign: 'left', padding: '8px 10px', color: 'var(--text-dim)', fontWeight: 600, fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.4, borderBottom: '1px solid var(--border-soft)' }}>{h}</th>
          ))}</tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} style={{ borderBottom: '1px solid var(--border-soft)' }}>
              {r.map((c, j) => (
                <td key={`${i}-${j}`} style={{ padding: '9px 10px', color: 'var(--text-main)', verticalAlign: 'top' }}>{c}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
