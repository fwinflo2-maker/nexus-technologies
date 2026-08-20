# NEXUS TECHNOLOGIES — AUDIT CYCLE 4

**Date :** 2026-08-20  
**Dépôt :** `C:\Users\Florenzo\Documents\project\nexus-technologies`  
**Verdict unique :** **READY FOR INTERNAL TESTING**  
**Git :** aucun commit ; WIP primaire conservé ; fichiers dangereux non touchés.

Cycle 3 (READY FOR INTERNAL TESTING) est préservé. Cycle 4 rapproche le chemin
EUR→XAF du palier sandbox **en code**, sans déclarer SANDBOX CONNECTED ni
PRODUCTION : aucune credential réelle pawaPay / Stripe / Sumsub n’a été
trouvée ni inventée.

## 1. Résultat exécutif

Le payout pawaPay v2, les webhooks signés, le settlement et le ledger restent
stables. Cycle 4 ajoute :

- tests de fraude contre le **vrai** code de vérification (RFC-9421 pawaPay
  avec paire EC générée ; `Stripe-Signature` natif) ;
- corrélation `request_id` / `event_id` / `provider_operation_id` /
  `transaction_id` / `provider_transaction_id` dans audit et réponses ;
- cache des clés publiques pawaPay ; initiation `ENQUEUED` = processing ;
- statut honnête FX (aucun vendor choisi) et sanctions (**OUT OF SCOPE**,
  fail-closed) ;
- UI KYC alignée sur `GET /api/kyc/status` (jamais « terminé » avant le serveur) ;
- table `funding_intents` appliquée sur la base live `nexus` (36 tables).

**Échelle de vérité (ne pas sauter un palier) :**

| Palier | Statut |
|---|---|
| 1. CODE READY | **oui** pour le corridor payout EUR→XAF, webhooks signés, settlement, ledger, fraude, corrélation |
| 2. CONFIGURATION READY | **partiel** : schémas / `.env.example` / matrices prêts ; pas de vendor FX ni de screening nominatif |
| 3. CREDENTIALS CONFIGURED | **non** — `CREDENTIALS_NOT_CONFIGURED` |
| 4. SANDBOX CONNECTED | **non** |
| 5. SANDBOX VERIFIED | **non** / `NOT_VERIFIED` |
| 6. PRODUCTION READY | **non** |
| 7. PRODUCTION VERIFIED | **non** |

Découverte honnête (aucune valeur de secret affichée ni journalisée) :

- pas de fichier `nexus-api/.env` ;
- `provider_credentials` live : **0** ligne plateforme, **0** ligne client ;
- variables `PROVIDER_PAWAPAY_*` / Stripe / Sumsub : absentes du dépôt versionné
  (placeholders vides dans `.env.example`) ;
- un harness **local** (`nexus-api/scripts/provider_sandbox`) existe avec un
  token fictif et une paire EC de test — ce n’est **pas** l’API sandbox
  pawaPay ; il n’a **pas** été utilisé pour déclarer une connexion ;
- `scripts/seed_provider_credentials.php` (fakes explicites) n’a **pas** été
  exécuté.

## 2. Tests

| Moment | Commande | Résultat |
|---|---|---|
| Cycle 3 baseline | `php vendor/bin/phpunit --display-warnings` | **750** tests, **3629** assertions, 0 W/E/F |
| Cycle 4 ciblé | `php vendor/bin/phpunit --display-warnings tests/PawaPayAdapterTest.php tests/ProviderWebhookTest.php tests/ProviderWebhookFraudTest.php tests/FundingWebhookFraudTest.php tests/FXAndSanctionsStatusTest.php tests/CorrelationObservabilityTest.php tests/StripeWebhookSignatureTest.php tests/PawaPaySignatureTest.php tests/FundingWebhookAttributionTest.php tests/ExecutionSettlementTest.php` | **63** tests, **224** assertions, **0** error, **0** failure |
| Backend final | `php vendor/bin/phpunit --display-warnings` | **777** tests, **3752** assertions, **0** warning / error / failure |
| Frontend lint | `npm run lint` | **exit 0** (avertissements historiques React keys/hooks/Fast Refresh) |
| Frontend build | `npm run build` | **PASS**, 1089 modules ; warning chunk 1.67 MB |
| PHP lint (fichiers Cycle 4) | `php -l` sur les sources touchées | **0** erreur de syntaxe |
| Playwright | absent de `package.json` | **N/A** |
| E2E navigateur live | non lancé contre :8080/:5173 (serveurs permanents de l’utilisateur) | **non exécuté** |

Delta vs Cycle 3 : **+27 tests**, **+123 assertions**, suite toujours verte.

## 3. Providers

| Provider | Credentials | Connection | Sandbox | Webhook | Settlement | Reconciliation | Status |
|---|---|---|---|---|---|---|---|
| pawaPay | absentes (`CREDENTIALS_NOT_CONFIGURED`) | non testée vers `api.sandbox.pawapay.io` | non | CODE READY RFC-9421 + cache keyid ; **NOT_VERIFIED** live | CODE READY (hold→capture→webhook/polling) | CODE READY (polling, écarts non auto-corrigés) | **CODE READY** / CREDENTIALS_NOT_CONFIGURED / NOT_VERIFIED |
| Stripe | absentes (`whsec` / `sk_` absents) | non | non | `Stripe-Signature` natif (t, plusieurs v1, tolérance, `hash_equals`) ; contrôle montant/devise si présents | seulement si opération Nexus connue | NOT_IMPLEMENTED | webhook **CONFIG_REQUIRED** / NOT_VERIFIED |
| Sumsub | absentes | non | non | HMAC digest provider, idempotence, transitions de statut (tests existants) | N/A | N/A | **CODE READY** / CREDENTIALS_NOT_CONFIGURED / NOT_VERIFIED |
| Western Union / catalogue | absentes | non | non | générique HMAC si secret | NOT_IMPLEMENTED | non | catalogue / fail-closed |

La matrice de capacité reste la source de vérité : payout pawaPay
`IMPLEMENTED` **et** credentials valides exigées au routing. Un adaptateur
présent n’est jamais « connecté ».

## 4. EUR→XAF — chemin de référence

Corridor visé : User → KYC → Funding → Quote → FX → Capability → Routing →
Hold → payout pawaPay → Webhook → Settlement → Ledger → Wallet → Notification
→ History.

| Hop | Preuve Cycle 4 | Résultat |
|---|---|---|
| User | register JWT inchangé | CODE READY |
| KYC | Sumsub adapter + UI lit `GET /api/kyc/status` | CODE READY ; **pas de clés** → session 503 `KYC_PROVIDER_NOT_CONFIGURED` |
| Funding source | `funding_intents` maintenant sur `nexus` live (36 tables) ; attribution par intent | CODE READY ; webhook inbound funding encore HMAC générique (pas RFC-9421) |
| Quote | Quote/Policy BCMath (Cycle 3) | refuse si FX absent (`FX_RATE_UNAVAILABLE`) |
| FX | aucun vendor approuvé | fail-closed ; voir §5 |
| Capability / Routing | payout pawaPay IMPLEMENTED uniquement si configuré | sans token → `NO_AVAILABLE_PROVIDER` / `CREDENTIALS_NOT_CONFIGURED` |
| Hold | create/release **sans** ledger ; capture = débit définitif | inchangé, tests verts |
| Payout pawaPay | `POST /v2/payouts` ; ACCEPTED/ENQUEUED/DUPLICATE_IGNORED = async | **aucun appel réel** (pas de token) |
| Webhook signé | RFC-9421 + Content-Digest + fenêtre created/expires ; tests fraude | **simulé-signé** contre le vrai vérificateur ; pas de callback sandbox réel |
| Settlement | `ExecutionSettlementService` + verrou FOR UPDATE | COMPLETED sans second débit wallet ; FAILED recrédite ; duplicata ignoré |
| Ledger | capture + `postOutboundDebit` / return | équilibré dans les tests ; `balance_after` = `available_balance` au repos |
| Wallet projection | inchangée | OK en tests |
| Notification / History | settlement notifie ; Send poll jusqu’à statut serveur | UI n’affiche le vert que pour `completed` |

**Résultat E2E live sandbox :** non exécuté. Le chemin est **immédiatement
utilisable dès qu’un token sandbox réel** est déposé dans le credential
manager (chiffré, isolé sandbox, `last_tested_at` remis à NULL à l’upsert).

IDs vérifiés en tests (pas en live) : `payoutId` = `provider_operation_id` =
idempotence pawaPay ; `event_id` = `payoutId:STATUS` ; `providerTransactionId`
tracé ; `request_id` dans la réponse webhook et l’audit de settlement.

## 5. FX

| Élément | Valeur |
|---|---|
| Vendor approuvé en code/docs/config | **aucun** — ne pas inventer ECB/Frankfurter/etc. |
| `FXSourceStatus` | `configured=false`, `source=none`, `fail_closed=true`, ladder `CODE_READY` |
| Cache | `fx_rates_cache`, scopé par environnement, expiration `expires_at > NOW()` |
| Production sans taux | 503 `FX_RATE_UNAVAILABLE` — inchangé |
| Base live `nexus` | **25** lignes `source=manual`, `environment=sandbox`, non expirées. Ce n’est **pas** une source vendor. Non semées par ce cycle ; non effacées. |
| Base `nexus_test` | cache vide par défaut ; les tests injectent une paire puis la retirent |
| Devises | EUR/XAF + paires réellement utilisées via cache ; identité EUR ; XAF dérivé EUR→XAF et EUR→devise |
| Tests | absent / exception ; describe() sans vendor ; isolation d’environnement (Cycle 3) |

## 6. Compliance / sanctions

| Élément | Valeur |
|---|---|
| Source nominative OFAC/UE/ONU | **absente** — **OUT OF SCOPE** |
| Source minimale | codes ISO-2 via `NEXUS_SANCTIONS_COUNTRIES` / `NEXUS_SANCTIONS_LIST_FILE` |
| Live | non configurée (`source=none`) |
| États | `CLEARED` / `HIT` / `UNAVAILABLE` — `UNAVAILABLE` ≠ `CLEARED` |
| Production | fail-closed (refus) |
| Sandbox si UNAVAILABLE | `REVIEW_REQUIRED`, jamais « contrôles passés » |
| Fréquence / cache vendor | N/A (pas de provider) |
| Timeout / fallback | fichier illisible → UNAVAILABLE (pas un succès) |

`SanctionsScreening::describe()` expose cet état pour l’audit. Brancher un
vrai provider consiste à remplacer `loadCountryList()` : les trois états
restent valides.

## 7. Sécurité — fraudes webhook

Preuves **simulées-signées contre le code réel**, jamais un CONNECTED fictif.

| Mutation | pawaPay RFC-9421 | Stripe-Signature | Funding HMAC |
|---|---|---|---|
| `user_id` attaquant | ignoré ; règlement du propriétaire de la tx | N/A (id objet) | ignoré ; crédit de l’intent |
| référence / payoutId | `UNKNOWN_PROVIDER_OPERATION` | idem | `UNKNOWN_FUNDING_INTENT` |
| montant | `PROVIDER_AMOUNT_MISMATCH`, statut `processing` inchangé | idem (cents vs majeur) | `FUNDING_INTENT_MISMATCH` |
| devise | mismatch | mismatch | mismatch |
| duplicata | 200 `duplicate`, pas de double settlement / crédit | 200 duplicate | un seul crédit |
| signature invalide | 401, audit `provider.webhook.rejected` | 401 | 401 |
| stale (created/expires ou `t=`) | 401 | 401 (tolérance 300 s) | pas d’anti-rejeu temporel sur le HMAC générique (**P1**) |
| plusieurs v1 | N/A | une v1 valide suffit | N/A |
| mauvais type d’événement | processing ignoré | `received` sans settlement | N/A |

Injection d’échec (timeout 504 → `PROVIDER_RETRYABLE` ; JSON altéré → 401 ;
quote expirée / FX absent / sanctions UNAVAILABLE déjà couverts par la suite
Cycle 3). Après chaque cas testé : pas de création d’argent, pas de double
débit, pas de double settlement.

Rotation des credentials **en code** (stage → activate → revoke, isolation
env, audit sans secret) : tests existants avec valeurs **locales de test**.
Aucune rotation de clé sandbox réelle (clés absentes).

Scan d’exposition après découverte : pas de `.env` ; `.gitignore` ignore
`.env` ; CI/phpunit n’injecte pas de secrets réels ; réponses webhook /
audit sans token/JWT. Les `sk_live_*` du dépôt sont des **fixtures de test**.
Le harness `.keys/harness_ec_p256.pem` est une clé **locale de mock**, pas un
token marchand.

## 8. Charge / concurrence

| Contrôle | Preuve | Limite |
|---|---|---|
| Même clé d’idempotence | `IdempotencyServiceTest`, transferts | unitaire |
| Captures/releases concurrentes | `WalletHoldTest` | simulé |
| Paiements concurrent | `PaymentConcurrencyTest` | unitaire |
| Webhooks concurrent | settlement `FOR UPDATE` | unitaire |
| Charge réelle / latence / throughput | **non** | P1 infra |

Aucun plafond financier relevé pour faire passer un test.

## 9. Frontend

- Send : `processing`/`pending` pollent le serveur ; seul `completed` est
  l’écran vert (Cycle 3, inchangé).
- KYC Personal/Business : carte **« Statut provider (serveur) »** = mapping
  `not_started` / `in_progress` / `pending` (examen) / `verified` (approuvée)
  / `resubmission_requested` / `rejected` / `on_hold` (revue) depuis
  `GET /api/kyc/status`. Sumsub non configuré → pill explicite, pas de
  simulation.
- Employees / Admin / Staff : inchangés (RBAC `users.platform_role`).
- Journeys sandbox live : **non** (providers non connectés). Ports :8080/:5173
  non perturbés.

## 10. Observabilité

`Correlation` + `X-Request-Id` (validé ou généré) + en-tête de réponse.
Audit webhook : `provider`, `event_id`, `request_id` (jamais le payload).
Audit settlement : `transaction_id`, `provider_operation_id`, `event_id`,
`provider_transaction_id`, `request_id`. `SecretRedactor` inchangé.

## 11. Base / isolation

- `nexus_test` : 36 tables (PHPUnit).
- `nexus` live : **36 tables** après `CREATE TABLE IF NOT EXISTS funding_intents`
  (manquait vs Cycle 3 / manifeste 0.41). `fx_rates_cache` live non vide
  (`manual`/sandbox) — isolation : ces taux ne sont **pas** lus en production.
- Sandbox ≠ production pour credentials, FX cache, webhooks `environment`.

## 12. P0–P3 restants

### Corrigé / ajouté en Cycle 4

- Fraude webhooks pawaPay/Stripe/funding (mutations + stale + duplicata).
- Contrôle montant/devise Stripe avant settlement.
- Corrélation d’IDs ; cache clés publiques pawaPay ; `ENQUEUED` à l’initiation.
- Statuts FX/sanctions honnêtes en code.
- KYC UI suit le backend.
- `funding_intents` sur la base live.

### Restant

- **P0 production / sandbox :** obtenir des credentials réelles et exécuter
  Quote → payout → callback signé **pawaPay sandbox** (succès / pending /
  failed / timeout / retry). Tant que c’est absent : pas READY FOR SANDBOX.
- **P0 production :** source FX **vendor** approuvée (aujourd’hui : aucune).
  Le cache `manual` sandbox n’en est pas une.
- **P0 production :** screening nominatif **ou** rester OUT OF SCOPE
  fail-closed (choix actuel documenté).
- **P1 :** charge réelle ; rotation opérationnelle des clés publiques
  pawaPay ; anti-rejeu temporel sur le HMAC générique **funding**.
- **P2 :** drop `employees.permissions` ; lint React / code-split bundle ;
  funding pawaPay deposit en RFC-9421 (aujourd’hui HMAC Nexus).
- **P3 :** i18n restante.

**Aucun P0 de régression** introduit sur auth, RBAC, Employees, Personal,
Business, Admin, Wallet, Ledger, Quotes, Routing, KYC code, i18n, DB test, CI.

## 13. Verdict

**READY FOR INTERNAL TESTING**

Pas READY FOR SANDBOX : pawaPay n’a pas répondu, aucun webhook sandbox réel,
FX vendor non choisi, compliance nominative hors scope, credentials absentes.
Le prompt Cycle 4 l’interdit explicitement : ne pas déclarer READY FOR SANDBOX
parce que le code est prêt.

Pas READY FOR PRODUCTION.

---

Rapport produit après exécution réelle des commandes ci-dessus.
Aucun commit. Non modifiés / non stagés :
`nexus-api/reset_superadmin.php`, `test_hash.php`, `encrypt_credentials.php`,
`AdminLoginPage.new.tsx`.
