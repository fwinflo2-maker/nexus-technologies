import { AgentResponse } from '../types';
import { AgentNotConfiguredError } from '../errors';

/**
 * Execution & Ledger Agent — SQUELETTE NON BRANCHÉ.
 *
 * POURQUOI CET AGENT N'EXÉCUTE PLUS RIEN
 * ──────────────────────────────────────
 * `execute()` fabriquait un mouvement d'argent complet et le déclarait
 * réussi :
 *
 *     transactionId : généré localement (Date.now + Math.random)
 *     idempotencyKey: dérivée de ce même identifiant
 *     ledgerEntry   : objet en mémoire, fees: 4.5 en dur, status PROCESSING
 *     retour        : { success: true, … }
 *
 * Aucune de ces données n'atteignait la base : pas d'écriture au ledger, pas
 * de hold, pas de clé d'idempotence enregistrée, pas de machine à états. Le
 * client recevait `success: true` pour une transaction qui n'existait nulle
 * part. `getStatus()` renchérissait en retournant `PROCESSING` pour n'importe
 * quel identifiant, y compris inconnu — un oracle qui invente un état.
 *
 * C'est la faute la plus grave possible sur un système financier : affirmer
 * qu'un transfert est en cours alors que rien n'a eu lieu.
 *
 * L'exécution réelle existe déjà côté API, avec saga, idempotence, holds et
 * comptabilité en partie double :
 *   nexus-api/src/Services/ExecutionEngine.php
 *   nexus-api/src/Services/LedgerService.php
 *   nexus-api/src/Services/IdempotencyService.php
 *
 * Tant que cet agent n'appelle pas ce moteur, il REFUSE d'exécuter.
 */
export class ExecutionAgent {
  private readonly name = 'Execution & Ledger Agent';
  private readonly systemPrompt = `Tu es l'agent Execution & Ledger de NEXUS.
Tu es responsable de l'orchestration des flux de paiement, du suivi de la machine à états
(de CREATED à COMPLETED), de l'idempotence et des écritures dans le Ledger.`;

  /** États de la machine réelle, conservés à titre documentaire. */
  private readonly states = [
    'CREATED', 'QUOTED', 'AUTHORIZED', 'FUNDING',
    'PROCESSING', 'PENDING', 'COMPLETED',
    'FAILED', 'CANCELLED', 'EXPIRED', 'REVERSED', 'REFUNDED', 'MANUAL_REVIEW',
  ];

  /**
   * @throws AgentNotConfiguredError toujours : aucun ledger n'est branché.
   *         Ne jamais remplacer par un succès — ce serait annoncer un
   *         mouvement d'argent qui n'a pas eu lieu.
   */
  async execute(_routeId: string, _intent: unknown): Promise<AgentResponse<never>> {
    throw new AgentNotConfiguredError(
      this.name,
      'Aucun ledger n\'est branché sur cet agent : il ne peut pas exécuter de ' +
        'transfert. L\'exécution réelle passe par nexus-api (ExecutionEngine, ' +
        'LedgerService, IdempotencyService).',
    );
  }

  /**
   * @throws AgentNotConfiguredError toujours : sans accès à la base, l'état
   *         d'une transaction ne peut pas être connu. Retourner un état
   *         plausible serait un oracle mensonger.
   */
  async getStatus(_transactionId: string): Promise<AgentResponse<never>> {
    throw new AgentNotConfiguredError(
      this.name,
      'L\'état d\'une transaction ne peut pas être lu depuis cet agent : ' +
        'aucune source de vérité n\'y est branchée.',
    );
  }

  /** États de la machine à états réelle (documentation). */
  getStates(): readonly string[] {
    return this.states;
  }

  getSystemPrompt(): string {
    return this.systemPrompt;
  }

  getName(): string {
    return this.name;
  }
}
