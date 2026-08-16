import type { ReactNode } from 'react';

/**
 * État vide premium : icône + titre + sous-titre + action facultative.
 * Remplace les « No data » bruts.
 */
export default function EmptyState({
  icon = '◈',
  title,
  subtitle,
  action,
}: {
  icon?: ReactNode;
  title: string;
  subtitle?: string;
  action?: ReactNode;
}) {
  return (
    <div className="empty-state">
      <div className="empty-state-icon">{icon}</div>
      <div className="empty-state-title">{title}</div>
      {subtitle && <div className="empty-state-sub">{subtitle}</div>}
      {action && <div style={{ marginTop: 8 }}>{action}</div>}
    </div>
  );
}
