# NEXUS CORP TECHNOLOGIES

**Nexus** est une **plateforme d'orchestration financière multi-providers** : l'utilisateur exprime une *intention* (et une **préférence de routage**), et Nexus détermine puis exécute automatiquement la **meilleure route** disponible.

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

**Nexus Pro n'existe plus.** Les capacités avancées (routing, optimisation, treasury, intelligence financière) sont des **capacités du Nexus Core ou de Nexus Business**, et **Nexus Connect** constitue l'offre API/B2B. La partie **Interne** (Super Admin) est un centre de contrôle global séparé.

## Nexus Core

- **Capability Engine** — ce que Nexus peut réellement faire (corridors, pays, devises, modes)
- **Quote Engine** — calcul des devis comparables (taux, frais, spread, ETA)
- **Routing Engine** — route optimale `SOURCE → RAILS → DESTINATION` selon la **préférence de routage** (Optimized ⭐ / Fastest ⚡ / Cheapest 💸 / Max Received 💰 / Most Reliable 🛡️), avec scoring multi-critères
- **Policy Engine** — règles & conformité (KYC/AML, plafonds, sanctions)
- **Funding Source Engine** — sources de financement vérifiées
- **Execution Engine** — exécution Saga / multi-étapes, idempotence, rollback, débit du wallet source
- **Self-Healing / Re-Routing** — bascule automatique sur route alternative
- **Ledger** — comptabilité en partie double, holds, réconciliation, audit

## Fonctionnalités

### Expérience client (Personal & Business)
- **Envoi / Réception / Conversion** multi-devises (EUR, USD, XAF…)
- **Modes de transfert (préférence de routage)** — l'utilisateur indique son objectif, Nexus choisit la route. **Les providers et mécanismes de routing ne sont jamais exposés à l'utilisateur.**
- **Source des fonds** : wallet réel **ou** proposition d'un provider (origine de financement vérifiée)
- Wallet multi-devises avec répartition disponible / en attente / en transit
- Historique, notifications, KYC, paramètres, agents
- **Support chat** intégré (assistant « Fin ») : chat pré-ticket avec réponses rapides + escalade vers un agent humain, pièces jointes, notes internes, temps de réponse, badge non-lu

### Dashboard Super Admin (centre de contrôle)
- Cockpit temps réel avec graphiques (Recharts) sur données réelles
- Comptes, transactions, opérations, trésorerie, compliance/KYC, risque/fraude, providers, support, sécurité, technique, audit
- Vue providers avec clés API / publiques (jamais exposées), credentials, Western Union inclus
- RBAC strict côté backend

### Providers intégrés (catalogue)
Stripe, pawaPay, Wise, Nium, Currencycloud, Thunes, Modulr, Swan, BVNK, Orange Money, MTN MoMo, M-Pesa, dLocal, Ebanx, Xendit, Marqeta… et **Western Union** (Mass Payments API, auth mTLS, endpoints officiels vérifiés). L'accès réel nécessite un onboarding partenaire.

### Pages publiques
Landing, connexion, inscription (profil riche en base), mot de passe oublié (reset réel connecté à la base), pages légales professionnelles (Confidentialité, Conditions, Documentation API, Support) avec TOC sticky.

## Architecture du dépôt

```
NEXUS/
├── nexus-frontend/          # Frontend React 19 + Vite + TypeScript (Personal, Business, Super Admin)
├── nexus-api/               # Backend PHP 8 + MySQL/MariaDB (API REST, RBAC)
├── agents/                  # Agents Node.js/Express (conceptuel) : compliance, routing, execution
└── docs/                    # Vision & spécifications
```

### Backend (`nexus-api`)
- PHP 8, API REST préfixée `/api`, PDO + MySQL/MariaDB
- **RBAC** côté serveur (jamais seulement côté frontend), JWT, rate-limiting, anti-énumération
- Migrations versionnées (`database/migrations/`), schéma complet régénéré (`full_schema.sql`), base de test `nexus_test`
- Providers pilotés par le catalogue (`ProviderCatalog`) + adaptateurs (`ProviderAdapter`), credentials chiffrées (AES-256-GCM), jamais exposées

### Frontend (`nexus-frontend`)
- React 19 + Vite + TypeScript, routing React Router
- **Animations React ultra premium** (framer-motion springs, stagger, transitions) + GSAP (ScrollTrigger, parallax) — appliquées sur tout le produit (landing, auth, dashboards, envoi)
- Stockage navigateur sécurisé (`safeStorage`, fallback mémoire, décodage JWT base64url) — robuste sur tous les navigateurs (mode privé inclus)
- Thème Revolut/Wise (accent bleu unique, fond sombre épuré)

## Démarrage rapide

### 1. Backend PHP

```bash
cd nexus-api
composer install
cp .env.example .env   # adapter DB_USER/DB_PASS (ex. nexus / nexus_dev_pw)
# reconstruire la base depuis le schéma complet
mariadb nexus < database/full_schema.sql
php -S 0.0.0.0:8080 -t public public/router.php
```

Tests : `DB_USER=nexus DB_PASS=nexus_dev_pw php scripts/setup_test_db.php && php vendor/bin/phpunit`

### 2. Frontend

```bash
cd nexus-frontend
npm install
npm run dev
```

Application sur `http://localhost:5173` (proxy `/api` → backend 8080)

### 3. Agents (conceptuel)

```bash
cd agents
npm install
npm run dev
```

API sur `http://localhost:3001`

## Documentation

- `docs/NEXUS-VISION.md` — **Vision globale v6 (source de vérité)**
- `docs/NEXUS-TECHNOLOGIES.md` — Spécification produit v5.3 (archivée, supersédée)
- `docs/NEXUS-Document-Technique.md` — Architecture technique (archivée)
- `README.dev.md` — Guide de développement (lancement, ports, config)

## Statut

**Phase 1–3 — Foundation + MVP Financial Orchestration + Super Admin + Support** :
wallets multi-devises, sources de fonds (wallet/provider), Capability/Quote/Routing/Policy/Funding/Execution Engines, Send/Receive/Convert avec modes de transfert, Execution Engine (idempotence, débit wallet), Self-Healing, corridor EUR → XAF, dashboard Super Admin (cockpit + 13 sections), support chat avec assistant IA, pages légales, providers Western Union, animations premium, auth robuste cross-navigateur.

---

*NEXUS CORP TECHNOLOGIES — août 2026*
