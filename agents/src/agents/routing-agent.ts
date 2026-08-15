import { RouteOption } from '../types';
import { AgentNotConfiguredError } from '../errors';

/**
 * Routing Agent — SQUELETTE NON BRANCHÉ.
 *
 * POURQUOI CET AGENT NE CALCULE PLUS DE ROUTES
 * ────────────────────────────────────────────
 * `computeRoutes()` produisait des devis entièrement inventés :
 *
 *   - trois providers fictifs (« Nexus Direct / Express / Economy ») qui
 *     n'existent pas dans le ProviderCatalog et n'ont aucun adaptateur ;
 *   - un taux de change codé en dur (`intent.amount * 650`), sans passer par
 *     le cache FX ni par aucun provider de taux ;
 *   - `compliance_status: 'APPROVED'` écrit en dur sur chaque route ;
 *   - `status: 'ELIGIBLE'` systématique, sans vérifier la moindre couverture
 *     de corridor.
 *
 * Un devis affiche un montant reçu et des frais : c'est une promesse
 * financière. Fabriquer ces chiffres à partir d'un taux en dur, puis les
 * marquer « conformes » et « éligibles », est un faux succès (§37).
 *
 * Le routing réel existe côté API, adossé au catalogue de providers, au cache
 * FX et au moteur de conformité :
 *   nexus-api/src/Services/CapabilityEngine.php
 *   nexus-api/src/Services/QuoteEngine.php
 *   nexus-api/src/Services/RoutingEngine.php
 *
 * Tant que cet agent n'interroge pas ces moteurs, il REFUSE de coter.
 */
export class RoutingAgent {
  private readonly name = 'Routing Agent';
  private readonly systemPrompt = `Tu es l'agent Routing de NEXUS.
Tu connais l'état des différents providers. Face à une intention, tu calcules toutes les routes admissibles,
appliques l'algorithme d'optimisation (frais, fiabilité, délais) et expliques tes recommandations de manière déterministe.`;

  /**
   * @throws AgentNotConfiguredError toujours : aucun catalogue de providers
   *         ni source de taux n'est branché. Ne jamais remplacer par des
   *         routes par défaut — un devis inventé engage Nexus sur un montant.
   */
  async computeRoutes(_intent: unknown): Promise<RouteOption[]> {
    throw new AgentNotConfiguredError(
      this.name,
      'Aucun catalogue de providers ni source de taux n\'est branché sur cet ' +
        'agent : il ne peut pas produire de devis. Le routing réel est assuré ' +
        'par nexus-api (CapabilityEngine, QuoteEngine, RoutingEngine).',
    );
  }

  getSystemPrompt(): string {
    return this.systemPrompt;
  }

  getName(): string {
    return this.name;
  }
}
