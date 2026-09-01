# Admin Provider Control Center — Architecture & Operations

## Overview

Le **Provider Control Center** (Admin → Providers) constitue le plan de contrôle unique pour la gestion des partenaires et adaptateurs financiers de Nexus Technologies.

```
Super Admin / Control Center UI
      │
      ▼
GET /api/control/providers
GET /api/control/providers/{slug}
PUT /api/providers/{slug}/credentials
POST /api/providers/{slug}/test
GET/PUT /api/control/providers/cashramp/card-policy
      │
      ▼
ControlCenterController & ControlCenterService
      │
      ▼
ProviderRegistry & ProviderCredentialService (AES-256-GCM)
```

## Fonctionnalités Principales

1. **Matrice des Providers** (`GET /api/control/providers`):
   - Statut de configuration par environnement (`sandbox` / `production`).
   - Opérations réellement implémentées par introspection de `AbstractProviderAdapter`.
   - Feature flags (`accounts`, `crypto`, `transfers`, `cards`).

2. **Fiche Provider Détaillée** (`GET /api/control/providers/{slug}`):
   - Verification documentation, capabilities matrix, health status real-time.
   - Status routing (`ENABLED` / `DISABLED`).

3. **Gestion Sécurisée des Credentials**:
   - Chiffrement AES-256-GCM via `ProviderCredentialService`.
   - Masquage automatique au frontend (`••••••••••`).
   - Isolation stricte `sandbox` vs `production`.

4. **Test de Connexion Réel**:
   - `POST /api/providers/{slug}/test`.
   - Effectue une requête API d'authentification native via le client GraphQL (ex. query `CashrampConnectionTest`).

5. **Politique de Création de Carte Virtuelle**:
   - `GET/PUT /api/control/providers/cashramp/card-policy`.
   - Config de la règle $1.00 USD minimum et identifiant du compte Business Cashramp.

## Contrôle d'Accès & Sécurité (RBAC)

- Capacité `credential_inventory` requise pour l'inventaire des credentials.
- Capacité `superadmin` requise pour la modification des politiques commerciales de création de carte et l'administration des credentials.
