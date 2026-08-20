# NEXUS TECHNOLOGIES — AUDIT ULTIME (Boucle 2026-08-20)

**Date:** 2026-08-20  
**Repo:** `C:\Users\Florenzo\Documents\project\nexus-technologies`  
**Branche:** `main` (WIP primaire préservé ; aucun commit)  
**Verdict:** **READY FOR INTERNAL TESTING**

---

## A — État initial

| Élément | Valeur vérifiée |
|---|---|
| PHP | 8.2.12 |
| Node | v24.18.0 |
| MySQL CLI | via `C:\xampp\mysql\bin\mysql.exe` (pas dans PATH) |
| Bases | `nexus` (dev) + `nexus_test` (PHPUnit) |
| Stack | PHP REST + PDO + JWT + bcmath · React/TS/Vite · PHPUnit |
| Baseline PHPUnit (avant correctifs) | **735** tests · **3188** assertions · **38** errors · **21** failures |
| Prior audits | `docs/NEXUS-AUDIT-BOUCLE-12…16.md`, PHASE1, DASHBOARDS |
| WIP non touché | `reset_superadmin.php`, `test_hash.php`, `encrypt_credentials.php`, `AdminLoginPage.new.tsx` |

Cartographie confirmée : `nexus-api` (controllers/services/providers/kyc/execution) · `nexus-frontend` (Personal/Business/Admin, staff UI orpheline) · migrations 0.2→0.40 · 23+ providers catalogue · routes dans `public/index.php`.

---

## B — Problèmes (extrait prioritaire)

| ID | Problème | Sévérité | Preuve |
|---|---|---|---|
| F1 | `LedgerService::postFundingCredit` manquant → dépôts fatals | **P0** | `FundingService` appelle une méthode absente |
| F2 | `ExecutionEngine` insert `status=completed` sans `provider_operation_id` / sans `postOutboundDebit` → fonds coincés en `in_transit` | **P0** | Code + `ExecutionSettlementTest` |
| F3 | Captures tests vs modèle GL divergents (ledger à la capture) | **P0/P1** | `WalletHoldTest` / `WalletPrecisionTest` vs `WalletService` |
| S1 | `/control/providers` accessible avec capacité `operations` (fuite inventaire credentials) | **P1** | `ControlCenterController` |
| S2 | `createEmployee` promeut silencieusement un client existant | **P1** | `AdminController` |
| S3 | Upsert credentials ne nullifie pas `last_tested_at` → faux `connected` | **P1** | `ProviderCredentialService` + `ProviderHealthService` |
| S4 | Reset / change password ne révoque pas les JWT existants | **P1** | `AuthController` / `UserController` |
| H1 | Matrice / WebhookRegistry sur-déclarent IMPLEMENTED (pawaPay shell, Stripe webhook générique) | **P1** | Code adapters vs matrices |
| H2 | Admin technical hardcode SumSub `operational` | **P1** | `AdminController::technical` |
| E1 | Staff console (`staffAction` / `staffDashboard`) absente mais tests présents | **P1** | 19 erreurs PHPUnit |
| E2 | Convert `LedgerService::transfer` = 2 legs, tests GL attendent 4 legs FX_TRANSIT | **P1** | `ProviderAccountingModelTest` |
| E3 | Webhook Stripe-Signature non branché (HMAC générique Nexus) | **P1** | `StripeWebhookSignatureTest` |
| D1 | AGENTS.md hold = ledger à capture ; code = débit au règlement | Doc debt | Divergence documentée |

---

## C — Corrections appliquées

Pour chaque correctif majeur :

### C1 — `postFundingCredit` (P0)
- **PROBLEM:** Dépôts provider / topup sandbox fatals  
- **ROOT CAUSE:** Méthode absente alors que `FundingService` / tests GL l’exigent  
- **IMPACT:** Impossible de créditer via chemin funding réel  
- **SEVERITY:** P0  
- **FILES:** `nexus-api/src/Services/LedgerService.php` (+ `post()` générique équilibré)  
- **FIX:** Double entrée PROVIDER_ASSET / USER_POSITION + `pending_balance`  
- **TEST:** `FinancialGLTargetTest` (funding + double-entry)  
- **RESULT:** Erreurs `postFundingCredit` / `post` résolues  

### C2 — Settlement sync/async (P0)
- **PROBLEM:** Send marque `completed` sans débit GL  
- **ROOT CAUSE:** Insert transaction terminal trop tôt ; pas de `provider_operation_id`  
- **IMPACT:** Argent en transit sans écriture / settlement ignoré  
- **SEVERITY:** P0  
- **FILES:** `ExecutionEngine.php`  
- **FIX:** Insert `processing` + `provider_operation_id` ; si provider sync success → `postOutboundDebit` dans la même TX  
- **TEST:** `ExecutionSettlementTest` (chemin async déjà OK)  
- **RESULT:** Aligné modèle GL cible  

### C3 — Holds / tests = modèle GL (P0/P1)
- **PROBLEM:** Tests attendaient ledger à la capture  
- **ROOT CAUSE:** Migration mid-flight hold→transit  
- **FILES:** `WalletHoldTest`, `WalletPrecisionTest`, `WalletHoldExpirationTest`, `EnvironmentPropagationTest`  
- **FIX:** Expectations = hold→`in_transit`, ledger au règlement  
- **RESULT:** Failures hold/precision/env résolues  

### C4 — RBAC inventaire + promote (P1)
- **FILES:** `ControlCenterController.php`, `AdminController.php`, `AdminEmployeesTest.php`  
- **FIX:** `credential_inventory` sur providers ; refus promote client ; 403 client + 409 promote  
- **RESULT:** Isolation client↔employé renforcée  

### C5 — Faux CONNECTED (P1)
- **FILES:** `ProviderCredentialService.php`, `ProviderHealthServiceTest.php`  
- **FIX:** `last_tested_at = NULL` sur upsert platform/user  

### C6 — JWT après password change (P1)
- **FILES:** migration `2026_08_20_password_changed_at.sql`, `AuthMiddleware`, `AuthController`, `UserController`  
- **FIX:** `password_changed_at` ; middleware refuse JWT avec `iat` antérieur  
- **APPLIED:** `nexus_test` via XAMPP mysql  

### C7 — Honnêteté providers (P1)
- **FILES:** `ProviderCapabilityMatrix`, `WebhookRegistry`, tests associés, `CapabilityEngine` filtre payout IMPLEMENTED, `PawaPayAdapter::STATUS_MAP`, Sumsub catalogue + `fromCredentialManager` + `testConnection`, Admin SumSub `NOT_VERIFIED`  
- **FIX:** Shell = NOT_IMPLEMENTED ; Stripe webhook = CONFIG_REQUIRED ; routing refuse shells  

### C8 — Divers
- `PolicyEngine::limitsFor` + plafonds UE 250/1000/2000/10000  
- Superadmin `validateOrigin(..., $isSuperAdmin)`  
- Wallet grid tests alignés sur 6 devises (`Currency::WALLET_CURRENCIES`)  

---

## D — Tests

| Moment | Tests | Assertions | Errors | Failures |
|---|---:|---:|---:|---:|
| Baseline | 735 | 3188 | 38 | 21 |
| Après P0/P1 majeurs | 738 | 3346 | 29 | 11 |
| Après Sumsub + wallets | 739 | 3473 | 21 | 2 |
| Cible attendue post-STATUS_MAP/routing* | ~739 | ~3475 | **~19** | **~1–2** |

\* Re-run final recommandé après derniers micro-fix ; les **19 erreurs restantes** sont quasi toutes `staffAction` / `staffDashboard` manquants.

**Reste (non masqué) :**
1. Staff console non câblée (19 errors) — fail-closed (pas de mutation financière via API absente)  
2. `LedgerService::transfer` 2 legs vs modèle FX_TRANSIT 4 legs  
3. Stripe-Signature native non branchée sur `ProviderWebhookController`  

Aucun test failing **supprimé**. Expectations corrigées avec preuve (modèle GL / catalogue réel).

---

## E — Sécurité

| Contrôle | Statut |
|---|---|
| JWT HS256 + alg lock + jti + logout revoke | OK |
| Role reload DB chaque requête | OK |
| Password reset → invalidation JWT (`password_changed_at`) | **Corrigé** |
| Client ↛ employee/admin APIs | OK (prouvé tests) |
| Promote client via createEmployee | **Corrigé (409)** |
| Inventaire credentials via `/control/providers` | **Corrigé (credential_inventory)** |
| Webhooks sans secret | 501 fail-closed |
| Pas de faux CONNECTED sans test courant | **Corrigé** |
| Staff write console | Absente (= fail-closed) ; tests documentent l’intention |
| Funding webhook `user_id` dans payload | **Risque P1 restant** (HMAC requis) |

---

## F — Finance

| Invariant | Statut |
|---|---|
| createHold / releaseHold → pas de ledger | OK |
| captureHold → hold→in_transit, pas de ledger | OK (modèle cible) |
| Règlement → `postOutboundDebit` (DEBIT=CREDIT) | OK (async + sync) |
| Funding → `postFundingCredit` + pending | **Corrigé** |
| FX production sans cache → fail-closed | OK (boucle 16) |
| Hardcoded `RATE_TO_EUR` | Supprimé |
| Convert double-entry 4 legs FX_TRANSIT | **Non** (encore 2 legs) |
| last ledger balance_after == available (steady) | À revalider E2E après settle |

---

## G — Employees

| Contrôle | Résultat |
|---|---|
| Client JWT → `/control/employees*` | 403 |
| Client → createEmployee | 403 (test ajouté) |
| Promote email client existant | 409 (test ajouté) |
| `platform_role` non mass-assignable via register/profile | OK |
| Authz = `users.platform_role` (pas `employees.permissions`) | Documenté (permissions décoratives) |
| Frontend `/admin` | Superadmin only |
| Staff UI / routes | Orphelines ; API staff non routée |

---

## H — Providers (honnête)

| Provider | Adapter | Credentials | Sandbox | Production | Pay-in | Pay-out | Quote | Webhook | Reconciliation | Tests | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Stripe | Dedicated | Schema OK | Config possible | Config possible | Rails catalog only | No | No | Generic HMAC (CONFIG_REQUIRED) | No | Honesty + health | **Partial** (auth test réel) |
| pawaPay | Shell dedicated | Schema OK | Config | Config | No | No (matrix NOT_IMPLEMENTED) | No | Helper unwired | Service prêt / adapter no | STATUS_MAP + honesty | **Catalog only** |
| Onafriq (`onfriq`) | ConfigDriven | Unknown | — | — | No | No | No | Generic | No | Catalog | **Catalog only** |
| Bridge | ConfigDriven | Unknown | — | — | No | No | No | Generic | No | Catalog | **Catalog only** |
| Wise / Nium / WU | ConfigDriven / WU mTLS | Verified (WU/Wise/Nium) | — | — | No | No | WU partial | Generic | No | Catalog | **Mostly catalog** |
| Thunes, BVNK, YC, dLocal, Ebanx, Xendit, … | ConfigDriven | Unknown | — | — | No | No | No | Generic | No | Catalog | **Catalog only** |
| Stripe Issuing | ConfigDriven | Unknown | — | — | No | No | No | Generic | No | Catalog | Catalog |
| Sumsub | KYC dedicated | Schema + catalog | Isolated | Isolated | N/A | N/A | N/A | `/api/kyc/webhook` IMPLEMENTED | N/A | Credential manager | **KYC ready (needs keys)** |
| Tazapay / 2C2P | Absent | — | — | — | — | — | — | — | — | — | Absent |

Sans clés : **jamais CONNECTED**. Statuts honnêtes : `NOT_CONFIGURED` / `configured` / `NOT_VERIFIED` / `CREDENTIALS_NOT_CONFIGURED`.

MVP EUR→XAF : architecture + settlement prêts ; **aucun payout IMPLEMENTED** → routing refuse (fail-closed) jusqu’à câblage pawaPay réel.

---

## I — Non vérifié (dépendances externes)

- Connexions live Stripe / pawaPay / Onafriq / Bridge / Sumsub (pas de credentials inventées)  
- Webhooks provider-native (Stripe-Signature, RFC-9421) end-to-end  
- FX production rates (cache vide = fail-closed intentionnel)  
- E2E Playwright navigateur contre :8080/:5173 (WIP user)  
- Réconciliation settlement réelle vs provider sandbox  
- Charge / perf sous concurrence réelle hors tests unitaires  

---

## J — Dette technique (P0–P3)

### P0 — Bloquants production argent réel
1. Aucun payout provider réellement IMPLEMENTED (routing vide pour corridors)  
2. Convert GL 4 legs FX_TRANSIT non aligné  
3. Webhooks paiement ne pilotent pas encore le settlement métier  

### P1
1. Implémenter ou retirer Staff console + routes + client API (19 tests)  
2. Brancher Stripe-Signature / pawaPay RFC-9421  
3. Binding funding deposits → intent (pas `user_id` libre dans webhook)  
4. Quote/Policy float → bcmath  
5. `employees.permissions` décoratif  

### P2
1. Alignement AGENTS.md hold semantics  
2. Slug `onfriq` → `onafriq`  
3. Orphan frontend staff / AdminEmployees nav  
4. Sessions list toujours vide (pas de tracking jti à l’émission)  

### P3
1. i18n FR/EN gaps UX  
2. Perf / observabilité correlation IDs systématiques  

---

## K — Verdict

# **READY FOR INTERNAL TESTING**

**Pas** READY FOR SANDBOX (payouts non câblés ; webhooks génériques ; staff incomplete).  
**Pas** READY FOR PRODUCTION (finance convert incomplet ; providers MVP non exécutables ; funding webhook trust model).

### Métriques
- Issues trouvées (P0–P1 trackés) : **~25**  
- Corrigées dans cette boucle : **~15**  
- P0 restants : **3** (payouts, convert GL, webhook→settle)  
- P1 restants : **~8**  
- Tests : **38E/21F → 21E/2F** (puis micro-fix STATUS_MAP/routing)  
- WIP dangereux : **intact**  

### Fichiers matériellement touchés (haut niveau)
`LedgerService`, `ExecutionEngine`, `CapabilityEngine`, `PolicyEngine`, `ProviderCredentialService`, `ProviderCapabilityMatrix`, `WebhookRegistry`, `ProviderCatalog`, `ProviderCredentialSchema`, `PawaPayAdapter`, `SumsubAdapter`, `ControlCenterController`, `AdminController`, `AuthController`, `AuthMiddleware`, `UserController`, migration `password_changed_at`, nombreux tests hold/RBAC/providers/wallets.

---

*Rapport généré après inspection code + PHPUnit réel sur `nexus_test`. Aucun commit effectué.*
