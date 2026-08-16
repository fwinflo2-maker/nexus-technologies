import { Router } from 'express';
import { NexusOrchestrator } from '../agents/orchestrator';
import { AgentNotConfiguredError } from '../errors';

const router = Router();
const orchestrator = new NexusOrchestrator();

/**
 * Traduit une erreur d'agent en réponse HTTP honnête.
 *
 * Un agent non branché renvoie 501 NOT IMPLEMENTED, jamais 200 avec un
 * résultat fabriqué et jamais 500 (ce n'est pas une panne : c'est une
 * fonctionnalité absente, et le client doit pouvoir faire la différence).
 */
function fail(res: import('express').Response, error: unknown, agent: string): void {
  if (error instanceof AgentNotConfiguredError) {
    res.status(error.statusCode).json({
      success: false,
      error: error.message,
      code: error.code,
      agent: error.agent,
      timestamp: new Date().toISOString(),
    });
    return;
  }

  res.status(500).json({
    success: false,
    error: error instanceof Error ? error.message : 'Erreur serveur',
    code: 'AGENT_ERROR',
    agent,
    timestamp: new Date().toISOString(),
  });
}

router.post('/intent', async (req, res) => {
  try {
    const result = await orchestrator.processIntent(req.body);
    res.json(result);
  } catch (error) {
    fail(res, error, 'API Gateway');
  }
});

router.post('/execute', async (req, res) => {
  try {
    const { routeId, intent } = req.body;
    const result = await orchestrator.executeRoute(routeId, intent);
    res.json(result);
  } catch (error) {
    fail(res, error, 'API Gateway');
  }
});

/**
 * Inventaire des agents et de leur état réel.
 *
 * `status` valait « operational » en dur, alors qu'aucun agent n'est branché
 * sur un moteur : l'inventaire mentait comme le reste. Il annonce désormais
 * l'état effectif.
 */
router.get('/agents', (_req, res) => {
  res.json({
    agents: orchestrator.getAgents(),
    status: 'not_configured',
    detail:
      'Squelettes non branchés : aucun agent n\'exécute d\'opération ' +
      'financière. La conformité, le routing et l\'exécution réels sont ' +
      'assurés par nexus-api.',
    timestamp: new Date().toISOString(),
  });
});

export default router;
