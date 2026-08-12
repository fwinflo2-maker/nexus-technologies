import { RouteOption } from '../types';

export class RoutingAgent {
  private readonly name = 'Routing Agent';
  private readonly systemPrompt = `Tu es l'agent Routing de NEXUS.
Tu connais l'état des différents providers. Face à une intention, tu calcules toutes les routes admissibles,
appliques l'algorithme d'optimisation (frais, fiabilité, délais) et expliques tes recommandations de manière déterministe.`;

  private readonly providers = [
    { id: 'nexus_direct', name: 'Nexus Direct', baseFee: 4.5, reliability: 0.97, speed: '10 minutes' },
    { id: 'nexus_express', name: 'Nexus Express', baseFee: 6.9, reliability: 0.94, speed: '3 minutes' },
    { id: 'nexus_economy', name: 'Nexus Economy', baseFee: 2.1, reliability: 0.99, speed: '2 heures' },
  ];

  async computeRoutes(intent: any): Promise<RouteOption[]> {
    const routes: RouteOption[] = [];

    for (const provider of this.providers) {
      const receivedAmount = Math.floor(intent.amount * 650 * (1 - provider.baseFee / intent.amount));
      const reliabilityBonus = provider.reliability > 0.95 ? 0.02 : 0;

      routes.push({
        route_id: `route_${provider.id}`,
        provider: provider.name,
        fees: provider.baseFee,
        estimated_delivery: provider.speed,
        reliability_score: provider.reliability + reliabilityBonus,
        received_amount: receivedAmount,
        status: 'ELIGIBLE',
        compliance_status: 'APPROVED',
        explanation: `Route ${provider.name}: frais ${provider.baseFee} EUR, délai ${provider.speed}, fiabilité ${((provider.reliability + reliabilityBonus) * 100).toFixed(0)}%`,
      });
    }

    routes.sort((a, b) => b.received_amount - a.received_amount);

    return routes;
  }

  getSystemPrompt(): string {
    return this.systemPrompt;
  }

  getName(): string {
    return this.name;
  }
}
