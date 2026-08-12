import type { NotificationType } from '../api/client';

/**
 * Métadonnées d'affichage par type de notification.
 *
 * Chaque type dispose d'une icône et d'une couleur dédiées (critère
 * d'acceptation « Icône et couleur par type »), en réutilisant uniquement les
 * tokens et classes du design system dashboard (.nexus-dash) :
 *  - transfert → cyan    (↗ envoi) ;
 *  - quote     → violet  (⇌ conversion FX) ;
 *  - kyc       → or      (🛡️ conformité, comme la bannière KYC du dashboard) ;
 *  - securite  → rouge   (🔒) ;
 *  - business  → vert    (🏢) ;
 *  - systeme   → cyan²   (⚙ systèmes).
 */
export const NOTIFICATION_META: Record<
  NotificationType,
  { icon: string; color: string; pill: string; ib: string; label: string }
> = {
  transfert: { icon: '↗', color: 'var(--cyan)', pill: 'p-c', ib: 'ib-c', label: 'Transfert' },
  quote:     { icon: '⇌', color: 'var(--violet)', pill: 'p-v', ib: 'ib-v', label: 'Quote' },
  kyc:       { icon: '🛡️', color: 'var(--gold)', pill: 'p-g', ib: 'ib-g', label: 'KYC' },
  securite:  { icon: '🔒', color: 'var(--red)', pill: 'p-r', ib: 'ib-c', label: 'Sécurité' },
  business:  { icon: '🏢', color: 'var(--green)', pill: 'p-gr', ib: 'ib-gr', label: 'Business' },
  systeme:   { icon: '⚙', color: 'var(--cyan2)', pill: 'p-c', ib: 'ib-c', label: 'Système' },
};

/** Types filtrables dans le centre de notifications, dans l'ordre d'affichage. */
export const NOTIFICATION_TYPES: NotificationType[] = [
  'transfert',
  'quote',
  'kyc',
  'securite',
  'business',
  'systeme',
];

/** Retourne les métadonnées d'un type, avec un repli sûr pour un type inconnu. */
export function notificationMeta(type: NotificationType): { icon: string; color: string; pill: string; ib: string; label: string } {
  return NOTIFICATION_META[type] ?? NOTIFICATION_META.systeme;
}
