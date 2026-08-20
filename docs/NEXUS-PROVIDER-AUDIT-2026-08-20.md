# NEXUS-PROVIDER-AUDIT — 2026-08-20

**Dépôt :** `C:\Users\Florenzo\Documents\project\nexus-technologies`  
**Objectif :** architecture provider-agnostic complète + classification honnête  
**Runner :** `php scripts/provider_connect_test.php`

---

## Verdict global

```text
PARTIALLY PROVIDER READY
```

- Pipeline Core présent : ProviderInterface → Catalog → Credential Manager →
  Capability → Quote → Routing → Execution → Webhooks → Idempotence → Ledger →
  Reconciliation.
- **0 provider CONNECTED** (aucun credential sandbox fourni au moment de l'audit).
- **Jamais FULLY PROVIDER READY** sans tests sandbox externes réels.

---

## Synthèse (26 providers catalogue)

| Métrique | Count |
|---|---|
| Total catalogue | 26 |
| IMPLEMENTED (au moins une capacité réelle) | 3 (`pawapay`, `stripe`, `sumsub`) |
| NOT_IMPLEMENTED (shell ConfigDriven) | 23 |
| CREDENTIALS_NOT_CONFIGURED | 26 |
| CONFIGURED | 0 |
| CONNECTED | 0 |
| CONNECTION_FAILED | 0 |
| AVAILABLE (adapter+creds+connection+capability) | 0 |
| SANDBOX_TESTED | 0 |
| PRODUCTION | PRODUCTION_NOT_TESTED (tous) |

---

## Tableau opérationnel

| Provider | Prio | Implementation | Adapter | Credentials | Connection | Sandbox | Production | Available |
|---|---|---|---|---|---|---|---|---|
| pawapay | P1 | IMPLEMENTED | PawaPayAdapter | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| onfriq (Onafriq) | P1 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| stripe | P1 | IMPLEMENTED | StripeAdapter | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| bridge | P1 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| thunes | P2 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| nium | P2 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| currencycloud | P2 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| wise | P3 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| yellow_card | P3 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| bvnk | P3 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| dlocal | P4 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| ebanx | P4 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| tazapay | P4 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| 2c2p | P4 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| xendit | P4 | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| western_union | P? | NOT_IMPLEMENTED | WesternUnionAdapter | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| sumsub | P? | IMPLEMENTED (KYC) | SumsubAdapter (KYC) | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |
| orange_money, mtn_momo, safaricom_mpesa, swan, modulr, stripe_issuing, marqeta, noah, cashramp | P? | NOT_IMPLEMENTED | ConfigDriven | CREDENTIALS_NOT_CONFIGURED | BLOCKED | NOT_TESTED | PRODUCTION_NOT_TESTED | no |

---

## Fiches prioritaires

### 1. pawaPay (P1)

| Axe | Statut |
|---|---|
| Catalogue | Oui |
| Adapter dédié | `PawaPayAdapter` — payout + polling + testConnection |
| Credential schema | Vérifié (`api_token`, `private_key?`, `api_key_id?`) |
| Credentials sandbox | **CREDENTIALS_NOT_CONFIGURED** |
| Connection | **BLOCKED** (aucun appel sortant) |
| Pay-in / Pay-out / FX | Payout IMPLEMENTED ; quote/balance NOT_IMPLEMENTED |
| Webhook | CONFIG_REQUIRED (RFC-9421 code présent) |
| Reconciliation | IMPLEMENTED (polling via matrice) |
| Sandbox E2E | NOT_TESTED |
| Production | PRODUCTION_NOT_TESTED (jamais auto-activée) |

### 2. Onafriq / onfriq (P1)

| Axe | Statut |
|---|---|
| Catalogue | Oui — slug **`onfriq`** (alias scripts : `onafriq`) |
| Adapter | ConfigDriven uniquement → **NOT_IMPLEMENTED** |
| Credentials | CREDENTIALS_NOT_CONFIGURED |
| Connection | BLOCKED |

### 3. Stripe (P1)

| Axe | Statut |
|---|---|
| Adapter | `StripeAdapter` — testConnection + verifyWebhook natif |
| Payout | NOT_IMPLEMENTED |
| Credentials | CREDENTIALS_NOT_CONFIGURED |
| Connection | BLOCKED |

### 4. Bridge (P1)

Catalogue + ConfigDriven uniquement → **NOT_IMPLEMENTED** + **CREDENTIALS_NOT_CONFIGURED**.

### 5–15. Thunes, Nium, Currencycloud, Wise, Yellow Card, BVNK, dLocal, EBANX, Tazapay, 2C2P, Xendit

Tous : **NOT_IMPLEMENTED** + **CREDENTIALS_NOT_CONFIGURED** + **BLOCKED**.  
Présents au catalogue (shell) ; non retirés. Prêts à recevoir credentials sans casser les autres.

---

## Architecture (état)

| Composant | État |
|---|---|
| `ProviderAdapter` | Contrat commun |
| `ProviderCatalog` | 26 providers (dont tazapay, 2c2p ajoutés) |
| `ProviderCredentialSchema` | Vérifié : stripe, pawapay, wise, nium, western_union, sumsub |
| `ProviderCredentialService` | AES-256-GCM, sandbox≠production |
| `ProviderRegistry` | Routing uniquement si CONFIGURED |
| `CapabilityEngine` | Provider-agnostic + filtre payout=IMPLEMENTED |
| `RoutingEngine` | Aucun `if ($provider === 'pawapay')` |
| `ExecutionEngine` / Saga | Provider-agnostic via Registry |
| Webhooks | Registry + contrôleur par slug |
| Idempotence | `provider_webhook_events` UNIQUE |
| Ledger / Settlement | Séparé des holds (capture seule) |
| Reconciliation | Pollable dérivé de la matrice (plus de hardcode SQL) |

---

## Livrables de cette mission

| Fichier | Rôle |
|---|---|
| `nexus-api/src/Providers/ProviderOperationalAudit.php` | Classification honnête |
| `nexus-api/scripts/provider_connect_test.php` | Runner générique |
| `nexus-api/scripts/provider_register_from_env.php` | Bootstrap credentials depuis env |
| `docs/PROVIDER-CAPABILITY-MATRIX.md` | Matrice vérifiée |
| `docs/PROVIDER-CREDENTIALS.md` | Credentials + PowerShell |
| `docs/NEXUS-PROVIDER-AUDIT-2026-08-20.md` | Ce rapport |

---

## Commandes PowerShell (P1) — sans secrets

```powershell
cd nexus-api

# --- pawaPay ---
$env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN = '<token-sandbox>'
php scripts/provider_register_from_env.php --provider=pawapay
Remove-Item Env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=pawapay

# --- Stripe ---
$env:PROVIDER_STRIPE_SANDBOX_SECRET_KEY = '<sk_test_...>'
php scripts/provider_register_from_env.php --provider=stripe
Remove-Item Env:PROVIDER_STRIPE_SANDBOX_SECRET_KEY -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=stripe

# --- Onafriq (slug onfriq) — adapter NON implémenté : connexion restera BLOCKED ---
$env:PROVIDER_ONFRIQ_SANDBOX_API_KEY = '<key>'
$env:PROVIDER_ONFRIQ_SANDBOX_API_SECRET = '<secret>'
php scripts/provider_register_from_env.php --provider=onfriq
Remove-Item Env:PROVIDER_ONFRIQ_SANDBOX_API_KEY, Env:PROVIDER_ONFRIQ_SANDBOX_API_SECRET -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=onfriq

# --- Bridge — adapter NON implémenté ---
$env:PROVIDER_BRIDGE_SANDBOX_API_KEY = '<key>'
$env:PROVIDER_BRIDGE_SANDBOX_API_SECRET = '<secret>'
php scripts/provider_register_from_env.php --provider=bridge
Remove-Item Env:PROVIDER_BRIDGE_SANDBOX_API_KEY, Env:PROVIDER_BRIDGE_SANDBOX_API_SECRET -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=bridge

# Audit global (un provider BLOCKED n'empêche pas les autres)
php scripts/provider_connect_test.php --all
```

Dès qu'un ladder affiche `connection=CONNECTED` pour pawaPay, enchaîner payout sandbox EUR→XAF → webhook → ledger → reconciliation avant de revendiquer **SANDBOX_TESTED**.

---

## Sécurité

- Aucun secret écrit dans Git.
- Production jamais auto-activée.
- Credentials absents → fail-closed, aucun appel mocké.
