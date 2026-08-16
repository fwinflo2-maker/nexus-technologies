import { ComplianceResult } from '../types';
import { AgentNotConfiguredError } from '../errors';

/**
 * Compliance & Risk Agent — SQUELETTE NON BRANCHÉ.
 *
 * POURQUOI CET AGENT NE REND PLUS DE VERDICT
 * ──────────────────────────────────────────
 * Cette classe retournait un verdict de conformité fabriqué :
 *
 *     const checks = { kyc: true, aml: true, sanctions: true, … };
 *     … reason: 'Tous les contrôles sont passés'
 *
 * Les cinq contrôles étaient codés à `true` sans qu'aucun ne soit exécuté :
 * pas de consultation KYC, pas de liste de sanctions, pas de moteur AML. Un
 * agent qui déclare « AML passé » sans avoir interrogé quoi que ce soit est
 * exactement le faux succès que la règle d'honnêteté (§37) interdit — et
 * c'est le même défaut que celui corrigé côté API dans PolicyEngine.
 *
 * La conformité réelle est déjà implémentée et testée dans le backend PHP
 * (`nexus-api/src/Services/PolicyEngine.php` + `SanctionsScreening.php`) :
 * statut du compte, plafonds KYC, filtrage des sanctions avec état explicite,
 * disponibilité du wallet. Dupliquer une seconde logique de conformité ici,
 * non testée et non branchée au ledger, ne créerait qu'une divergence.
 *
 * Tant que cet agent n'est pas relié au moteur réel, il REFUSE de statuer.
 */
export class ComplianceAgent {
  private readonly name = 'Compliance & Risk Agent';
  private readonly systemPrompt = `Tu es l'agent Compliance & Risk de NEXUS.
Ta mission est d'évaluer chaque intention financière selon les règles KYC/KYB et les réglementations par pays.
Tu décides si l'opération est APPROVED, DECLINED ou REVIEW_REQUIRED.
Tu ne contournes JAMAIS les règles de conformité.`;

  /**
   * @throws AgentNotConfiguredError toujours : aucun moteur de conformité
   *         n'est branché. Ne jamais remplacer par un verdict par défaut —
   *         un `APPROVED` fabriqué ici autoriserait un mouvement d'argent.
   */
  async evaluate(_intent: unknown): Promise<ComplianceResult> {
    throw new AgentNotConfiguredError(
      this.name,
      'Aucun moteur de conformité n\'est branché sur cet agent. La conformité ' +
        'est assurée par nexus-api (PolicyEngine + SanctionsScreening) ; cet ' +
        'agent ne doit pas produire de second verdict non vérifié.',
    );
  }

  getSystemPrompt(): string {
    return this.systemPrompt;
  }

  getName(): string {
    return this.name;
  }
}
