-- =============================================================================
-- NEXUS — KYB distinct pour comptes Business (Sumsub subject_type=company)
-- 2026_08_16
--
-- La vérification d'entreprise (KYB) est un ÉTAT distinct du KYC individuel :
-- une entreprise implique la vérification de la société, de ses représentants
-- et de ses bénéficiaires effectifs. On projette donc sur `users` un niveau
-- `kyb_status` propre, alimenté UNIQUEMENT par un webhook Sumsub signé portant
-- subject_type='company' (voir KycService::promoteUserKycLevel / demote).
--
-- Le Policy Engine bloque les paiements d'un compte Business tant que
-- `kyb_status != 'verified'`. Aucun autre statut n'autorise les opérations (§37).
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN `kyb_status` enum('none','in_progress','pending','verified','resubmission_requested','rejected','on_hold')
      NOT NULL DEFAULT 'none'
      COMMENT 'Vérification d''entreprise (KYB, Sumsub subject_type=company)'
      AFTER `kyc_level`,
  ADD COLUMN `kyb_verified_at` datetime DEFAULT NULL
      AFTER `kyb_status`;
