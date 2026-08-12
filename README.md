<<<<<<< HEAD
# NEXUS CORP TECHNOLOGIES

Plateforme financière intelligente multi-rails avec frontend React ultra-premium et armée d'agents IA autonomes.

## Architecture

```
NEXUS/
├── nexus-frontend/          # Frontend React + Vite + TypeScript
│   ├── src/
│   │   ├── components/      # Composants réutilisables (Layout, Card, TorusField)
│   │   ├── views/           # Vues Personal & Business
│   │   ├── styles/          # Design system ultra-premium
│   │   └── types/           # Types TypeScript
│   └── package.json
│
└── agents/                  # Backend Agents Antigravity (Node.js/Express)
    ├── src/
    │   ├── agents/          # Agents spécialisés
    │   │   ├── compliance-agent.ts    # Compliance & Risk
    │   │   ├── routing-agent.ts       # Routing Engine
    │   │   ├── execution-agent.ts     # Execution & Ledger
    │   │   └── orchestrator.ts        # Orchestrateur Principal
    │   └── routes/          # API Routes
    └── package.json
```

## Fonctionnalités Implémentées

### Frontend (React + Vite + TypeScript)
- **Design System Ultra-Premium** : Dark mode, glassmorphism, animations fluides
- **Animation 3D TorusField** : Composant Canvas/WebGL pour l'arrière-plan immersif
- **Nexus Personal** : Simulateur de transfert international avec comparaison de routes
- **Nexus Business** : Dashboard avec stats, approbations, équipe et activité
- **Navigation** : Basculer entre Personal et Business
- **Responsive** : Adaptation mobile et desktop

### Agents Antigravity (SDK Conceptuel)
- **Compliance & Risk Agent** : Évalue KYC/KYB, AML, sanctions, limites, juridictions
- **Routing Agent** : Calcule les routes admissibles, scoring déterministe
- **Execution & Ledger Agent** : Orchestration, machine à états, idempotence
- **Nexus Intelligence Orchestrator** : Supervise et consolide les agents

### API Backend
- `POST /api/intent` - Évalue une intention de transfert
- `POST /api/execute` - Exécute une route sélectionnée
- `GET /api/agents` - Liste des agents disponibles
- `GET /health` - Health check

## Démarrage Rapide

### Prérequis
- Node.js 18+
- npm ou yarn

### 1. Démarrer le Backend Agents

```bash
cd agents
npm install
npm run dev
```

Le serveur API démarre sur `http://localhost:3001`

### 2. Démarrer le Frontend

```bash
cd nexus-frontend
npm install
npm run dev
```

L'application démarre sur `http://localhost:5173`

## Utilisation

1. **Ouvrir** `http://localhost:5173`
2. **Sélectionner** Personal ou Business via la navigation
3. **Personal** : Renseigner un transfert (montant, devise, pays) et cliquer sur "Trouver les routes avec NEXUS Intelligence"
4. **Business** : Consulter le dashboard, les approbations en attente, l'équipe

## Stack Technique

### Frontend
- React 18 + TypeScript
- Vite
- React Router DOM
- Canvas 2D (TorusField 3D)
- CSS Variables + Glassmorphism

### Backend
- Node.js + Express
- TypeScript
- Architecture multi-agents
- API REST

## Conformité & Sécurité

- **Provider Agnostic** : L'architecture reste indépendante des providers
- **Déterministe** : Les moteurs critiques sont testables et versionnés
- **Auditable** : Chaque décision est tracée
- **IA Guardrails** : Les agents ne prennent jamais de décision financière seule

## Prochaines Étapes

- [ ] Intégration Sumsub (KYC/KYB)
- [ ] Provider Registry avec adaptateurs
- [ ] Webhooks et événements
- [ ] Tests E2E
- [ ] Déploiement CI/CD
- [ ] Nexus Connect API

## Documentation

- `docs/NEXUS-TECHNOLOGIES.md` - Vision & Spécification du Produit (v5.3)
- `docs/NEXUS-Document-Technique.md` - Architecture Technique (v1.0)
- `docs/NEXUS-Architecture-Technique-v1.0.html` - Rendu HTML de l'architecture
- `README.dev.md` - Guide de développement (lancement, ports, config)

## Statut

**Phase 0 - Foundation** : Structure technique démontrée, prête pour intégration providers.

---

*NEXUS CORP TECHNOLOGIES - août 2026*
=======
# nexus-technologies
>>>>>>> 032547c697d134aef46121b163a73b48bf2e0985
