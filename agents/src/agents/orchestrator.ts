import { ComplianceAgent } from './compliance-agent';
import { RoutingAgent } from './routing-agent';
import { ExecutionAgent } from './execution-agent';
import { TransferIntent, RouteOption, ComplianceResult, AgentResponse } from '../types';
import { AgentNotConfiguredError } from '../errors';

export class NexusOrchestrator {
  private complianceAgent: ComplianceAgent;
  private routingAgent: RoutingAgent;
  private executionAgent: ExecutionAgent;

  constructor() {
    this.complianceAgent = new ComplianceAgent();
    this.routingAgent = new RoutingAgent();
    this.executionAgent = new ExecutionAgent();
  }

  async processIntent(intent: TransferIntent): Promise<AgentResponse<{
    compliance: ComplianceResult;
    routes: RouteOption[];
    execution?: any;
  }>> {
    const startTime = Date.now();

    try {
      const compliance = await this.complianceAgent.evaluate(intent);

      if (compliance.decision === 'DECLINED') {
        return {
          success: false,
          data: { compliance, routes: [] },
          error: compliance.reason,
          agent: 'Nexus Intelligence Orchestrator',
          timestamp: new Date().toISOString(),
        };
      }

      const routes = await this.routingAgent.computeRoutes(intent);

      const eligibleRoutes = routes.filter(r => r.status === 'ELIGIBLE');

      return {
        success: true,
        data: {
          compliance,
          routes: eligibleRoutes,
        },
        agent: 'Nexus Intelligence Orchestrator',
        timestamp: new Date().toISOString(),
      };
    } catch (error) {
      // Un agent non branché doit remonter tel quel jusqu'au transport, qui
      // le traduit en 501. L'aplatir ici en `success: false` générique ferait
      // passer « fonctionnalité absente » pour « erreur ponctuelle » — le
      // client croirait qu'un nouvel essai peut réussir.
      if (error instanceof AgentNotConfiguredError) {
        throw error;
      }

      return {
        success: false,
        error: error instanceof Error ? error.message : 'Erreur inconnue',
        agent: 'Nexus Intelligence Orchestrator',
        timestamp: new Date().toISOString(),
      };
    }
  }

  async executeRoute(routeId: string, intent: TransferIntent) {
    return this.executionAgent.execute(routeId, intent);
  }

  getAgents() {
    return {
      compliance: this.complianceAgent.getName(),
      routing: this.routingAgent.getName(),
      execution: this.executionAgent.getName(),
      orchestrator: 'Nexus Intelligence Orchestrator',
    };
  }
}
