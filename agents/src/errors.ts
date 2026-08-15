/**
 * Erreurs des agents.
 *
 * Un agent non branché doit ÉCHOUER de façon explicite, jamais renvoyer un
 * résultat plausible. Le pendant côté PHP est
 * `ProviderOperationNotImplemented` : même doctrine, même refus de simuler.
 */

/** Le service d'agents n'est pas relié au moteur réel : refus de statuer. */
export class AgentNotConfiguredError extends Error {
  /** Code métier renvoyé au client HTTP. */
  public readonly code = 'AGENT_NOT_CONFIGURED';

  /** 501 : la fonctionnalité n'est pas implémentée, ce n'est pas une panne. */
  public readonly statusCode = 501;

  constructor(
    public readonly agent: string,
    message: string,
  ) {
    super(message);
    this.name = 'AgentNotConfiguredError';
  }
}
