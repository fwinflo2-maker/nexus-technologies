# PHASE 4 — BOUCLE A : Baseline réelle + Architecture

> Audit réalisé sur l'état RÉEL du dépôt. Aucune hypothèse, aucun test déclaré
> sans exécution. Date de l'audit : 2026-08-15.

## BASELINE RÉELLE

- **Branch** : `main`
- **HEAD** : `fc52743` (Merge PR #8 — audit et correction des faux succès du backend, boucles 12 à 16)
- **Remote** : `origin` → `https://github.com/fwinflo2-maker/nexus-technologies.git`
- **Working tree** : propre (aucune modification non commitée)
- **Branches distantes** : `main`, `amélioration-de-projet-documenté-35a30`, `chore/repo-hygiene-and-ci`, `jules-…`, `restauration-phase-b1-nexus-711e5`

### Environnement d'exécution (reconstruit pour l'audit)
| Outil | Version |
|---|---|
| PHP CLI | 8.4.24 (extensions : pdo, pdo_mysql, bcmath, mbstring, openssl, json, xml, curl, zip) |
| Composer | 2.10.2 |
| PHPUnit | 10.5.64 |
| MariaDB | 11.8.6 (serveur local sur 127.0.0.1:3306) |
| Node.js | v20.20.2 |
| npm | 10.8.2 |

> Note portabilité : la CI (`.github/workflows/ci.yml`) teste PHP 8.1 et 8.3
> sous **MySQL 8.0**. L'audit local tourne sous PHP 8.4 + MariaDB 11.8 — un
> delta à garder en tête pour toute conclusion « moteur-dépendante ».

### Tests — Baseline réelle
```
PHPUnit 10.5.64
Configuration: nexus-api/phpunit.xml
OK (550 tests, 2311 assertions)
```
- **Tests** : 550
- **Assertions** : 2311
- **Failures** : 0
- **Errors** : 0
- **Risky** : 0
- **Warnings** : 0
- **Durée** : ~44 s, mémoire 16 MB

> ⚠️ Le README (`database/README.md`) annonce « 502 tests ». Le **réel** est
> **550 tests / 2311 assertions**. Écart documenté (probablement lié à l'ajout
> de tests depuis la dernière mise à jour de la doc).

### Validations complémentaires
| Validation | Résultat |
|---|---|
| PHP lint (`composer run-script lint`) | ✅ PASS — 0 erreur de syntaxe |
| Contrat SQL ↔ PHP (`scripts/sql_contract_audit.php`) | ✅ PASS — 20/21 tables référencées, 191 colonnes qualifiées, aucune incohérence |
| TypeScript (`npx tsc -b`) | ✅ PASS |
| Lint frontend (`npx oxlint`) | ✅ 0 erreur / **6 warnings** (react-refresh/only-export-components) — connus, non bloquants |
| Build frontend (`npm run build`) | ✅ PASS (avertissement chunk > 500 kB, non bloquant) |
| Build agents (`npm run build`) | ✅ PASS |
| Base de test | ✅ `nexus_test` reconstruite via `setup_test_db.php` (schema.sql + manifeste de migrations) |

## FICHIERS INSPECTÉS (architecture)

### Monorepo
```
nexus-api/            backend PHP 8 + MySQL (API REST)
nexus-frontend/       frontend React + Vite + TypeScript
agents/               agents Node.js/Express (TS) — compliance, routing, execution
docs/                 vision & spécifications
.github/workflows/ci.yml   CI (API PHP, schéma SQL, frontend, agents)
```

### Backend `nexus-api/`
| Couche | Fichiers clés |
|---|---|
| Config | `config/env.php`, `app.php`, `database.php`, `constants.php` |
| Entrée | `public/index.php` (front controller + routes), `public/router.php` |
| Routage | `src/Core/Router.php`, `Request.php`, `Response.php` |
| Auth | `src/Auth/AuthMiddleware.php`, `Jwt.php`, `JwtException.php` |
| Contrôleurs | `AuthController`, `WalletController`, `AccountController`, `PaymentController`, `TransferController`, `QuoteController`, `IntentController`, `DashboardController`, `NotificationController`, `KycController`, `ProviderCredentialController`, `ControlCenterController`, `MaintenanceController`, `ReconciliationController`, `BusinessController`, `TeamController`, `BeneficiaryController`, `UserController` |
| Services | `WalletService`, `LedgerService`, `ExecutionEngine`, `QuoteEngine`, `RoutingEngine`, `FundingSourceEngine`, `CapabilityEngine`, `PolicyEngine`, `IntentEngine`, `FXService`, `IdempotencyService`, `ProviderCatalog`, `ProviderCredentialService`, `SanctionsScreening`, `KycService`, `PaymentRecoveryService`, `ControlCenterService`, `BusinessService` |
| Providers | `ProviderRegistry`, `PawaPayAdapter`, `StripeAdapter`, `ConfigDrivenProviderAdapter`, `AbstractProviderAdapter`, `ProviderConfig`, `SecretRedactor`, `WebhookVerifier` |
| Exécution | `src/Execution/` — `ExecutionContext`, `ExecutionEnvironment`, `EnvironmentGuard`, `PlatformRole`, `ProductionAuthorizationPolicy`, `AccountContext`, `ProviderResolver`, `ExecutionAudit` |
| KYC | `src/Kyc/` — `SumsubAdapter`, `KycStatus`, `KycSubjectType`, `KycWebhookEvent` |
| Sécurité | `src/Core/Crypto.php`, `src/Providers/SecretRedactor.php` |

### Base de données `nexus-api/database/`
- **21 tables**, 264 colonnes, 77 index, 36 contraintes uniques, 24 clés étrangères, 37 colonnes ENUM.
- `schema.sql` (socle) + **21 migrations versionnées** (`migrations/`, liste dans `migrations.manifest`).
- `full_schema.sql` (généré), `sql/nexus_schema.sql` / `nexus_seed.sql` / `nexus_full.sql` (référence générée).
- Tables : `users`, `wallets`, `wallet_operations`, `ledger_entries`, `transactions`, `payments`, `payment_accounts`, `provider_credentials`, `quotes`, `fx_rates_cache`, `idempotency_keys`, `revoked_tokens`, `login_attempts`, `notifications`, `beneficiaries`, `team_members`, `kyc_verifications`, `kyc_webhook_events`, `reconciliation_items`, `audit_logs`, `oauth_identities`.

### Frontend `nexus-frontend/`
- `src/api/client.ts` (client API + `apiGoogleAuth`), `src/context/AuthContext.tsx`, `ProtectedRoute`.
- Views : `auth/` (Login, Register, ForgotPassword), `dashboard/`, `business/`, `control/`, `public/`.
- Composant `src/components/GoogleButton.tsx` (OAuth Google GIS).

### Agents `agents/`
- `src/agents/` (orchestrator, compliance, routing, execution), `src/routes/agents.ts`.

## PROBLÈMES RÉELLEMENT TROUVÉS (BOUCLE A)

1. **`oauth_identities` orpheline** : table créée par la migration `2026_08_10_oauth_phone.sql`, mais **jamais référencée par le code PHP** (confirmé par le contrat SQL↔PHP : `[INFO] Tables jamais référencées (1) : oauth_identities`). La doc indique que les identités Google vivent dans `users.auth_provider` + `users.provider_id`. → À traiter (BOUCLE B/Database).
2. **README « 502 tests » vs 550 réels** : documentation périmée (non bloquant).
3. **Google Auth implémenté mais à désactiver temporairement** (section 3 du cahier des charges) :
   - Backend : route `POST /api/google` (`public/index.php:110`) → `AuthController::google()`.
   - Frontend : `GoogleButton.tsx` utilisé dans `LoginPage.tsx` et `RegisterPage.tsx` ; `apiGoogleAuth()` dans `client.ts`.
   - Aucune dépendance npm Google (script GIS chargé au runtime).
   → Désactivation complète prévue en BOUCLE C.

## MODIFICATIONS RÉELLES
- Aucune modification de code en BOUCLE A (baseline = inspection + documentation uniquement, conformément au cahier des charges : « Aucune modification avant la baseline réelle »).
- Seul artefact produit : ce rapport.

## DATABASE
- schema.sql : ✅ présent (non modifié)
- migrations : 21 (non modifiées)
- tables : 21
- contraintes : 36 uniques / 24 FK
- indexes : 77
- SQL exécuté : ✅ `scripts/setup_test_db.php` (reconstruction réelle de `nexus_test`)
- database vérifiée : ✅ 21 tables, `wallets.hold_balance` OK, contrat SQL↔PHP PASS

## SECURITY (état constaté, non modifié)
- IDOR : des tests de tenant isolation existent (`WalletTenantIsolationTest`, `CredentialOwnershipTest`, `ProviderCredentialIsolationTest`). Audit approfondi prévu (BOUCLE D/G).
- Authorization : `AuthMiddleware`, environnement + `PlatformRole` présents.
- JWT : `Jwt.php` + `revoked_tokens` + `logout`. Audit détaillé en BOUCLE C.
- Idempotency : `IdempotencyService` + table `idempotency_keys` présents. Audit en BOUCLE E.
- Financial precision : `LedgerService`, `bcmath`, colonnes DECIMAL. Audit en BOUCLE D/E.
- Note : `.env.example` contient `JWT_SECRET=nexus-dev-secret-change-me` (défaut dev, attendu) — le bootstrap de test utilise la même valeur. Vérifier qu'aucun secret réel n'est commité (tests `ApiSecretLeakageTest`, `CredentialSurfaceTest` existent et passent).

## TESTS RÉELLEMENT EXÉCUTÉS
- Suite PHPUnit complète : ✅ 550/550 pass, 2311 assertions.
- PHP lint : ✅
- Contrat SQL↔PHP : ✅
- TypeScript / Lint / Build frontend : ✅
- Build agents : ✅
- Reconstruction base de test : ✅

## GIT
- Commit : aucun (baseline sans modification de code)
- Push : à venir après BOUCLE B
- Working tree : propre

## RESTE À FAIRE
- **BOUCLE B** — Database + migrations (supprimer/migrer `oauth_identities` orpheline si justifié).
- **BOUCLE C** — Authentication + authorization (désactivation Google Auth, audit JWT).
- **BOUCLE D** — Wallet + financial integrity.
- **BOUCLE E** — Ledger + idempotency + concurrency.
- **BOUCLE F** — API contracts.
- **BOUCLE G** — Security / red team.
- **BOUCLE H** — Full regression.
- **BOUCLE I** — Final production audit.
