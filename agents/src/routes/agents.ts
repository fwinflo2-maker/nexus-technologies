import { Router } from 'express';
import { NexusOrchestrator } from '../agents/orchestrator';

const router = Router();
const orchestrator = new NexusOrchestrator();

router.post('/intent', async (req, res) => {
  try {
    const intent = req.body;
    const result = await orchestrator.processIntent(intent);
    res.json(result);
  } catch (error) {
    res.status(500).json({
      success: false,
      error: error instanceof Error ? error.message : 'Erreur serveur',
      agent: 'API Gateway',
      timestamp: new Date().toISOString(),
    });
  }
});

router.post('/execute', async (req, res) => {
  try {
    const { routeId, intent } = req.body;
    const result = await orchestrator.executeRoute(routeId, intent);
    res.json(result);
  } catch (error) {
    res.status(500).json({
      success: false,
      error: error instanceof Error ? error.message : 'Erreur serveur',
      agent: 'API Gateway',
      timestamp: new Date().toISOString(),
    });
  }
});

router.get('/agents', (_req, res) => {
  res.json({
    agents: orchestrator.getAgents(),
    status: 'operational',
    timestamp: new Date().toISOString(),
  });
});

export default router;
