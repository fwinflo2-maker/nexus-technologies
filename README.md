# NEXUS CORP TECHNOLOGIES

**Nexus** est une **plateforme d'orchestration financière multi-providers** : l'utilisateur exprime une *intention*, et Nexus détermine puis exécute automatiquement la **meilleure route** disponible.

> **USER INTENT → NEXUS INTELLIGENCE → OPTIMAL ROUTE → EXECUTION → SETTLEMENT → LEDGER**

## Structure produit

```
                         NEXUS
                          │
             Financial Orchestration
                          │
          ┌───────────────┼───────────────┐
          │               │               │
       PERSONAL        BUSINESS        CONNECT
          │               │               │
       Consumer       Companies          API
          │               │               │
          └───────────────┼───────────────┘
                          │
                     NEXUS CORE
```

**Nexus Pro n'existe plus.** Les capacités avancées (routing, optimisation, treasury, intelligence financière) sont des **capacités du Nexus Core ou de Nexus Business**, et **Nexus Connect** constitue l'offre API/B2B.

## Nexus Core

- **Capability Engine** — ce que Nexus peut réellement faire
- **Quote Engine** — calcul des devis comparables (taux, frais, spread, ETA)
- **Routing Engine** — route optimale `SOURCE → RAILS → DESTINATION` (Optimized / Fastest / Cheapest / Max Received / Most Reliable)
- **Policy Engine** — règles & conformité
- **Funding Source Engine** — sources de financement vérifiées
- **Execution Engine** — exécution Saga / multi-étapes, idempotence, rollback
- **Self-Healing / Re-Routing** — bascule automatique sur route alternative
- **Ledger** — comptabilité en partie double, holds, réconciliation, audit

## Architecture du dépôt

```
NEXUS/
├── nexus-frontend/          # Frontend React + Vite + TypeScript (Personal & Business)
├── nexus-api/               # Backend PHP 8 + MySQL (API REST)
├── agents/                  # Agents Node.js/Express (conceptuel) : compliance, routing, execution
└── docs/                    # Vision & spécifications
```

## Documentation

- `docs/NEXUS-VISION.md` — **Vision globale v6 (source de vérité)**
- `docs/NEXUS-TECHNOLOGIES.md` — Spécification produit v5.3 (archivée, supersédée)
- `docs/NEXUS-Document-Technique.md` — Architecture technique (archivée)
- `README.dev.md` — Guide de développement (lancement, ports, config)

## Démarrage rapide

### 1. Backend PHP (XAMPP : Apache + MySQL)

```bash
cd nexus-api
composer install
# configurer .env puis pointer Apache sur nexus-api/public
```

### 2. Frontend

```bash
cd nexus-frontend
npm install
npm run dev
```

Application sur `http://localhost:5173`

### 3. Agents (conceptuel)

```bash
cd agents
npm install
npm run dev
```

API sur `http://localhost:3001`

## Statut

**Phase 1–2 — Foundation + MVP Financial Orchestration** : wallets, funding sources, Capability/Quote/Routing Engine, Send/Receive/Convert, Execution Engine, Self-Healing, corridor initial EUR → XAF.

---

*NEXUS CORP TECHNOLOGIES — août 2026*
