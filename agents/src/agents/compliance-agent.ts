import { ComplianceResult } from '../types';

export class ComplianceAgent {
  private readonly name = 'Compliance & Risk Agent';
  private readonly systemPrompt = `Tu es l'agent Compliance & Risk de NEXUS.
Ta mission est d'évaluer chaque intention financière selon les règles KYC/KYB et les réglementations par pays.
Tu décides si l'opération est APPROVED, DECLINED ou REVIEW_REQUIRED.
Tu ne contournes JAMAIS les règles de conformité.`;

  async evaluate(intent: any): Promise<ComplianceResult> {
    const checks = {
      kyc: true,
      aml: true,
      sanctions: true,
      limits: true,
      jurisdiction: true,
    };

    const reasons: string[] = [];

    if (intent.amount > 10000) {
      checks.limits = false;
      reasons.push('Montant supérieur à la limite standard (10,000 EUR)');
    }

    if (intent.destination_country === 'XX') {
      checks.jurisdiction = false;
      reasons.push('Pays de destination non autorisé');
    }

    const allPassed = Object.values(checks).every(Boolean);

    let decision: ComplianceResult['decision'];
    if (allPassed) {
      decision = 'APPROVED';
    } else if (checks.kyc && checks.aml && checks.sanctions) {
      decision = 'REVIEW_REQUIRED';
    } else {
      decision = 'DECLINED';
    }

    return {
      decision,
      reason: reasons.join('; ') || 'Tous les contrôles sont passés',
      checks,
    };
  }

  getSystemPrompt(): string {
    return this.systemPrompt;
  }

  getName(): string {
    return this.name;
  }
}
