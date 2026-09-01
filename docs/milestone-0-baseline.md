# MILESTONE 0 — NEXUS ARCHITECTURE BASELINE

Audit du repository `fwinflo2-maker/nexus-technologies` (branche `main`, commit baseline documenté séparément).

Date : 2026-09-01

---

## MILESTONE

**MILESTONE 0 — NEXUS ARCHITECTURE BASELINE**

## OBJECTIVE

Cartographier l'état réel du dépôt production-only (`htdocs/` + `sql/`) avant toute évolution Cashramp / multi-provider, sans modifier le comportement financier critique.

## CHANGES

Aucune modification de code métier. Ajout de ce document d'audit uniquement.

## DATABASE

| Élément | État |
|---|---|
| `sql/schema.sql` + `sql/migrations/` (50 migrations) | Présents, manifeste `sql/migrations.manifest` |
| `provider_credentials` | Existe (credentials chiffrées plateforme + user) |
| `provider_accounts` | Existe — comptes **Nexus chez les providers** (trésorerie/clearing), pas mapping client Cashramp |
| `payment_accounts` | Existe — sources de financement utilisateur (origines KYC) |
| `provider_webhook_events` | Existe — idempotence webhooks |
| `wallets` / `ledger_entries` / `wallet_operations` | Existe — ledger interne Nexus |
| **`provider_customers`** | **ABSENT** (requis Milestone 1) |
| Scripts migrate/compare | **ABSENTS** du dépôt production (références legacy `database/migrate.sh`) |

## API

Backend : `htdocs/api-app/` (PHP, PDO, sans Composer dans le repo).

Pipeline financier existant :

```
IntentEngine → CapabilityEngine → QuoteEngine → RoutingEngine → ExecutionEngine
                                                      ↓
                                            ProviderRegistry / ProviderResolver
                                                      ↓
                                            ProviderAdapter (pawaPay implémenté)
```

## PROVIDER

| Composant | État |
|---|---|
| `ProviderAdapter` | Interface unique — payout/quote/balance/webhook ; **pas** de méthodes customer/account/wallet/crypto |
| `ProviderRegistry` | Adapters dédiés : stripe, stripe_issuing, maplerad, **pawapay**, western_union, moneygram ; défaut = `ConfigDrivenProviderAdapter` |
| `ProviderResolver` | Résout **un slug + contexte** ; pas de `resolveTransferRoute()` multi-scoring |
| `ProviderCapabilityMatrix` | Par provider + capacité globale ; **pas** de granularité corridor (EUR→XAF, pays, channel) |
| `ProviderCredentialService` | Opérationnel — triplet user/provider/environment, chiffrement |
| **Cashramp** | Catalogue + schéma credentials + auth probe ; **pas** d'adapter, **pas** de client HTTP, catégorie `onramp` / pays `NG` uniquement |
| **pawaPay** | Adapter payout + webhook RFC-9421 + reconciliation poll — **préservé** |

Règle respectée : pas de `EUR → XAF = pawaPay` hardcodé dans RoutingEngine.

Gap vs cible : quotes = barème Nexus + FXService (pas `provider.getQuote()` natif) ; wallets = projection DB interne (pas sync Cashramp).

## TESTS

| Tests exécutés | Résultat |
|---|---|
| Suite PHPUnit | **Non exécutable** — aucun fichier `*Test.php`, pas de `composer.json` / `phpunit.xml` dans le dépôt production |
| PHP CLI local | **Non disponible** sur la machine d'audit (Windows, `php` absent du PATH) |

Les tests existaient dans l'ancien monorepo `nexus-api/` supprimé lors de la restructuration production-only.

## SECURITY

| Contrôle | État |
|---|---|
| `.gitignore` exclut `.env`, `*.zip`, `.tmp` | OK |
| Credentials providers chiffrés en base | OK (ProviderCredentialService) |
| Secrets hors Git | OK (`.env` local non versionné) |
| Webhook signature fail-closed | OK (ProviderWebhookController) |
| Idempotency | OK (ExecutionEngine + IdempotencyService) |

## GIT

| | |
|---|---|
| Branch | `main` |
| Working tree | Clean |
| Remote | `origin` → `https://github.com/fwinflo2-maker/nexus-technologies.git` |
| Dernier commit avant doc | `c5ba577` — Restructure repository to production deployment only |

## STATUS

```text
PARTIAL
```

Baseline auditable et stable pour démarrer Milestone 1, mais la suite de tests et plusieurs briques cibles Cashramp/multi-provider sont absentes.

## BLOCKERS

1. **Aucune suite de tests** dans le dépôt production — régression non vérifiable localement.
2. **PHP / Composer non présents** dans l'environnement d'audit Windows.
3. **Pas de `provider_customers`** — prérequis Milestone 1.
4. **`ProviderAdapter` incomplet** pour comptes/wallets/crypto/customer Cashramp.
5. **Pas de `CashrampAdapter` / `CashrampClient`** — Cashramp = catalogue + config générique seulement.
6. **Matrice de capacités non corridor-aware** — incompatible avec spec §16 sans extension.
7. **`ProviderResolver` ≠ routing multi-provider** — pas de scoring fee/fx/health par route.
8. **Quotes non provider-native** — QuoteEngine utilise barème Nexus + FXService.
9. **Wallets utilisateur = ledger interne** — pas de projection Cashramp ni `last_synced_at`.
10. **Frontend source absent** — seulement assets buildés dans `htdocs/assets/` (pas de `nexus-frontend/`).
11. **Outils migrations/scripts** absents du layout production (`sql/` seul).

## NEXT MILESTONE

**MILESTONE 1 — PROVIDER CUSTOMER MAPPING**

- Migration `provider_customers`
- `ProviderCustomerService` (get/create/getOrCreate/sync, idempotent)
- Tests unitaires minimum (restaurer harness PHPUnit dans `htdocs/api-app/`)
