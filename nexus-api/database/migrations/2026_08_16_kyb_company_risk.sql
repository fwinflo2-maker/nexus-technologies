-- =============================================================================
-- NEXUS — KYB : niveau de risque (approche basée sur le risque, FATF Rec. 10/22)
-- 2026_08_16
--
-- Les grandes fintechs (Sumsub, Wise, Stripe) proportionnent leur due diligence
-- au risque : low / medium / high. Ce niveau est une PROJECTION déterministe
-- (KycRiskScorer) des attributs déjà collectés (pays de résidence, secteur),
-- persistée avant le démarrage d'une vérification KYB. Il sert au Policy Engine
-- et à la priorisation des revues ; il ne remplace ni l'évaluation Sumsub ni
-- les contrôles du Policy Engine.
--
-- `risk_level` ne concerne que les comptes Business : NULL pour un compte
-- personnel (le champ est calculé uniquement au lancement d'un KYB).
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN `risk_level` enum('low','medium','high') DEFAULT NULL
      COMMENT 'Niveau de risque KYB (approche basée sur le risque) — Business uniquement'
      AFTER `kyb_verified_at`;
