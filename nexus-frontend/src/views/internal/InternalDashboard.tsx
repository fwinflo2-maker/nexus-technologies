import type { ReactNode } from 'react';

/** Métadonnées d'un dashboard interne Nexus. */
export interface InternalDashboardMeta {
  id: string;
  label: string;
  icon: string;
  description: string;
}

/** Coque commune d'un dashboard interne spécialisé (RBAC). */
export function InternalDashboard({ meta, children }: { meta: InternalDashboardMeta; children: ReactNode }) {
  return (
    <div className="page">
      <div className="page-header animate-up" style={{ marginBottom: 24 }}>
        <div className="page-label">NEXUS INTERNAL · {meta.icon}</div>
        <div className="page-title">{meta.label}</div>
        <p style={{ marginTop: 8, fontSize: 13, color: 'var(--text-mid)', maxWidth: 720, lineHeight: 1.6 }}>
          {meta.description}
        </p>
      </div>
      {children}
    </div>
  );
}

/** Carte de section pour les dashboards internes. */
export function IntCard({ title, icon, children, tone = 'c' }: {
  title: string; icon?: string; children: ReactNode; tone?: 'c' | 'g' | 'gr' | 'v';
}) {
  const cls = { c: 'card-hi-c', g: 'card-hi-g', gr: 'card-hi-gr', v: 'card-hi-v' }[tone];
  return (
    <div className={`card ${cls}`} style={{ padding: 18, display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
        {icon && <span style={{ fontSize: 16 }}>{icon}</span>}
        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>{title}</span>
      </div>
      {children}
    </div>
  );
}

/** Ligne clé/valeur dans une carte interne. */
export function IntRow({ k, v, tone }: { k: string; v: ReactNode; tone?: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '6px 0', borderBottom: '1px solid var(--border-soft)', fontSize: 12.5 }}>
      <span style={{ color: 'var(--text-dim)' }}>{k}</span>
      <span style={{ color: tone || 'var(--text-bright)', fontWeight: 600, textAlign: 'right' }}>{v}</span>
    </div>
  );
}
