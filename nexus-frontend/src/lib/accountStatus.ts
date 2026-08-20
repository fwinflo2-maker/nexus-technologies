import type { ApiUser } from '../api/client';

/**
 * État « vérifié » réel d'un compte, partagé par les badges (sidebar,
 * topbar) et le dashboard.
 *
 * Cohérent avec le backend (PolicyEngine::limitsFor) :
 *   - particulier  : vérifié si KYC documentaire (standard / advanced) ;
 *   - entreprise   : vérifiée si KYB verified.
 *
 * none / basic ne sont PAS vérifiés : afficher « Compte vérifié » pour
 * ces niveaux contredirait les limites mensuelles réellement appliquées.
 */
export function isAccountVerified(
  user: Pick<ApiUser, 'account_type' | 'kyc_level' | 'kyb_status'> | null | undefined,
): boolean {
  if (!user) return false;
  if (user.account_type === 'business') {
    return (user.kyb_status ?? 'none') === 'verified';
  }
  return ['standard', 'advanced'].includes(user.kyc_level ?? '');
}
