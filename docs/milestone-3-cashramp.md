# Milestone 3 — Cashramp Integration + Multi-Provider Routing

Référence officielle Cashramp API : [https://docs.cashramp.co/cashramp](https://docs.cashramp.co/cashramp)

## Objectif

Ce milestone concrétise l'intégration de Cashramp comme premier provider financier réellement configurable, testable et opérationnel dans Nexus Technologies tout en maintenant un moteur de transfert strictement **multi-provider**.

## Key Architecture

Nexus maintient une séparation stricte entre **Ressources/Comptes** (où Cashramp est le provider principal) et **Transferts** (qui restent multi-providers).

```
                  NEXUS CORE
                      │
   ┌──────────────────┼──────────────────┐
   │                  │                  │
ACCOUNTS          TRANSFERS           CRYPTO
   │                  │                  │
   ▼                  ▼                  ▼
CASHRAMP        MULTI-PROVIDER        CASHRAMP
   │           ┌──────┼──────┐           │
   │           ▼      ▼      ▼           │
   └───────► Cashramp X      Y ◄─────────┘
```

## API Cashramp Discovery & Capabilities

| Capability | Documented | API | Account Access | Configured | Sandbox Tested | Production Ready |
|------------|------------|-----|----------------|------------|----------------|------------------|
| Customer | Yes | Yes | Available | Yes | Tested | Configurable |
| USD Account | Yes | Yes | Available | Yes | Tested | Configurable |
| EUR Account | Yes | Yes | Available | Yes | Tested | Configurable |
| Crypto Wallet | Yes | Yes | Available | Yes | Tested | Configurable |
| BTC | Yes | Yes | Available | Yes | Tested | Configurable |
| USDT | Yes | Yes | Available | Yes | Tested | Configurable |
| USDC | Yes | Yes | Available | Yes | Tested | Configurable |
| Transfer / Ramp | Yes | Yes | Available | Yes | Tested | Configurable |
| Quote | Yes | Yes | Available | Yes | Tested | Configurable |
| Virtual Card | Yes | Yes | Available | Yes | Tested | Configurable |
| Webhook | Yes | Yes | Available | Yes | Tested | Configurable |

## Cashramp Component Stack

1. **CashrampClient** (`src/Providers/Cashramp/CashrampClient.php`): Client GraphQL centralisé avec auth Bearer secret key (`CSHRMP-SECK_...`), gestion des timeouts, normalization des erreurs.
2. **CashrampAdapter** (`src/Providers/CashrampAdapter.php`): Adaptateur concret héritant de `AbstractProviderAdapter`. Implémente `testConnection`, `createCustomer`, `requestVirtualBankAccount`, `getBalance`, `getQuote`, `createPayment`, `verifyWebhook`, `withdrawOnchain`.
3. **CashrampCustomerProvisioner** (`src/Services/CashrampCustomerProvisioner.php`): Service d'idempotence pour la création du customer chez Cashramp à partir de la vérification KYC/KYB.
4. **CashrampAccountService** (`src/Services/CashrampAccountService.php`): Mapping et persistance des comptes virtuels (`provider_user_accounts`).
5. **CashrampCardCreationPolicyService** (`src/Services/CashrampCardCreationPolicyService.php`): Application de la politique commerciale Nexus **$1.00 USD minimum** par carte virtuelle créée.
6. **Feature Flags** (`src/Providers/Cashramp/CashrampFeatureFlags.php`): Flags serveur (`accounts`, `crypto`, `transfers`, `cards`).

## Cashramp Card Creation Policy ($1 USD Minimum)

- **Min USD**: $1.00 USD par carte créée (politique commerciale Nexus).
- **Business Cashramp Account**: Compte entreprise pré-alimenté configurable via l'Admin.
- **Financement idempotent**: Financement $1 avec réservation et compensation en cas d'échec de la création de la carte.

## Suppression de pawaPay

pawaPay est totalement désactivé et supprimé du système actif de providers (`ProviderRegistry`, `ProviderCatalog`, `WebhookRegistry`). Aucune référence opérationnelle ne subsiste.
