# Milestone 2 — Provider Registry / Resolver Unification

Références : Milestone 0 (`3877ce6`), Milestone 1 (`36b1fd9`).

## Objectif

Unifier le socle multi-provider de Nexus autour d’un **ProviderRegistry** unique et d’un **ProviderResolver** capable de produire des candidats de route explicables, sans hardcoder Cashramp ou pawaPay dans les controllers.

Règle produit actuelle :

- **Cashramp** = seul provider financier ciblé (comptes/wallets) — adapter stub, routing **DISABLED**
- **pawaPay** = adapter conservé, **INACTIVE / NOT_CONFIGURED / NOT_ELIGIBLE** (aucune clé API Nexus)
- Architecture = **multi-provider ready** pour les transferts

## Architecture avant

```
Transfer Request
    → CapabilityEngine (filtres catalogue + isConfigured + matrix payout)
    → QuoteEngine (Nexus fees, pas de provider.getQuote())
    → RoutingEngine (score quotes)
    → ExecutionEngine → ProviderRegistry::adapter()
```

Problèmes :

- `ProviderResolver` existait mais **n’était jamais appelé**
- `ProviderHealthService` **non branché** au routing
- Cashramp passait par `ConfigDrivenProviderAdapter` (sonde HTTP ≠ intégration financière)
- pawaPay pouvait apparaître seul dans le routing si configuré (matrix payout IMPLEMENTED)
- Pas de modèle corridor explicite ni de candidats expliqués

## Architecture après

```
Transfer Request
    → CapabilityEngine
        → ProviderResolver::resolveTransferRoute()
            → ProviderEligibilityService (chaîne d’éligibilité)
            → ProviderHealthService (santé persistée)
            → ProviderRouteScoring (classement déterministe)
    → QuoteEngine (contrat futur : adapter.getQuote())
    → RoutingEngine
    → ExecutionEngine
        → ProviderResolver::resolve() + ProviderRegistry::get()
        → ProviderAdapter
```

## ProviderRegistry

Convention unique :

| Méthode | Rôle |
|---------|------|
| `get($slug)` | Adaptateur (alias `adapter()`) |
| `has($slug)` | Présence dans le catalogue |
| `all()` | Tous les slugs catalogue |
| `enabled()` | Slugs avec `routing_enabled` catalogue |

Adaptateurs dédiés : `cashramp` → `CashrampAdapter` (stub NOT_IMPLEMENTED).

## ProviderResolver

| Méthode | Rôle |
|---------|------|
| `resolve($slug, $context)` | Adapter + credentials scopées (existant, **branché**) |
| `resolveProviders($intent, $context)` | Liste de candidats avec reasons |
| `resolveTransferRoute(...)` | Candidats + route sélectionnée + `NO_ELIGIBLE_PROVIDER` |
| `hasCredentialFor(...)` | Env scopée ou credentials plateforme DB |

## Provider eligibility

Chaîne minimale (`ProviderEligibilityService`) :

1. Provider existe
2. `routing_enabled` catalogue
3. `PROVIDER_*_ENABLED`
4. Corridor (catégorie + pays)
5. Capacité opération (matrix ou `createPayment` implémenté)
6. Credentials configurées
7. Configuration valide
8. Santé acceptable (pas DEGRADED/UNAVAILABLE)
9. Capacité corridor (`UNKNOWN` par défaut — n’invente rien)

## Capability matrix

- Niveau provider : inchangé (`IMPLEMENTED`, `NOT_IMPLEMENTED`, …)
- Niveau corridor : modèle prêt (`routeStatus`, `routeKey`, `ROUTE_DIMENSIONS`)
- États corridor : `UNKNOWN`, `AVAILABLE`, `UNAVAILABLE`, `DISABLED`, `TESTED`, `PRODUCTION_READY`
- `ROUTE_DECLARED` vide au M2 — données réelles au milestone d’intégration provider

## Cashramp

| Axe | État |
|-----|------|
| Adapter | `CashrampAdapter` — **NOT IMPLEMENTED** |
| Credentials | NOT CONFIGURED |
| Connection | NOT TESTED |
| Routing | **DISABLED** (`routing_enabled: false`) |
| Capabilities payout | NOT_IMPLEMENTED |

## pawaPay

| Axe | État |
|-----|------|
| Adapter | `PawaPayAdapter` — **EXISTING** (conservé) |
| Credentials | NOT CONFIGURED (aucune clé Nexus) |
| Connection | NOT TESTED |
| Routing | **DISABLED** (`routing_enabled: false`) |
| Execution | DISABLED via éligibilité |
| Matrix payout | IMPLEMENTED (code présent, non sélectionné sans activation) |

## Routing

- Aucun hardcode `EUR → XAF = provider`
- Résultat actuel attendu : **NO_ELIGIBLE_PROVIDER**
- Admin Control Center : bloc `routing` par provider (adapter, credentials, connection, routing, capabilities)

## QuoteEngine (préparation)

Contrat futur documenté :

```text
QuoteEngine → eligible providers → adapter.getQuote() → Nexus fees → final quote
```

Non implémenté au M2 (pas de quotes Cashramp inventées).

## Tests

| Fichier | Couverture |
|---------|------------|
| `ProviderRegistryTest` | get/has/all/enabled, CashrampAdapter |
| `ProviderResolverTest` | NO_ELIGIBLE_PROVIDER, reasons, fake future provider |
| `ProviderEligibilityTest` | credentials, Cashramp, unknown |
| `ProviderCapabilityMatrixTest` | matrix + route model |
| `ProviderRouteScoringTest` | scoring déterministe |
| `ProviderHealthEligibilityTest` | santé DB (skip si MySQL absent) |

Fake adapter : `tests/Support/FakeFutureProviderAdapter.php` (PHPUnit uniquement).

## Sécurité

- Le client ne choisit pas `provider_slug` pour le routing
- Credentials jamais exposées dans les candidats / admin routing summary
- Reasons d’éligibilité destinées admin/logs — pas au client final

## Fichiers principaux

```text
htdocs/api-app/src/Providers/CashrampAdapter.php
htdocs/api-app/src/Providers/ProviderEligibilityService.php
htdocs/api-app/src/Providers/ProviderEligibilityResult.php
htdocs/api-app/src/Providers/ProviderRouteCandidate.php
htdocs/api-app/src/Providers/ProviderRouteScoring.php
htdocs/api-app/src/Execution/ProviderResolver.php
htdocs/api-app/src/Services/CapabilityEngine.php
htdocs/api-app/src/Services/ControlCenterService.php
htdocs/api-app/src/Services/ExecutionEngine.php
htdocs/api-app/tests/Provider*.php
```

## Prochain milestone

**MILESTONE 3 — CASHRAMP CLIENT + CASHRAMP ADAPTER**

- Implémenter l’adapter Cashramp réel
- Activer `routing_enabled` Cashramp quand prêt
- Alimenter `ROUTE_DECLARED` avec capacités réelles API
