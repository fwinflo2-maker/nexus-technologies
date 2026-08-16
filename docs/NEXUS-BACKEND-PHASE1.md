# NEXUS — Rapport Backend Phase 1

Date : 2026-08-14
Périmètre : Provider Credentials, Provider Adapters, Sumsub KYC/KYB,
Business isolation, Security hardening.

---

## PASS — ce qui a été réellement exécuté

| Contrôle | Résultat |
|---|---|
| Tests backend | **209 tests / 1011 assertions — 0 failure, 0 error** (baseline 172/932) |
| Fresh install SQL | base détruite/recréée → 21 tables, 14 migrations, sans erreur |
| Idempotence migrations | `migrate.sh` rejoué, exit 0, aucun index dupliqué |
| Équivalence des schémas | `compare_schemas.sh` PASS (21 tables, 254 colonnes, 67 index, 22 FK) |
| Contrat SQL ↔ backend | `sql_contract_audit.php` PASS, 182 colonnes, 0 incohérence |
| Précision monétaire | 0 FLOAT/DOUBLE/REAL (inchangé) |
| Isolation sandbox/production | 4 directions testées : sandbox→sandbox PASS, sandbox→production FAIL, production→production PASS, production→sandbox FAIL |
| Chiffrement des credentials | vérifié en base : le secret en clair est absent de `credentials_enc` |
| Webhook signé (bout en bout) | signature valide → `processed=true` + KYC promu ; rejeu → `duplicate=true` ; payload falsifié → **HTTP 401** |
| Routes protégées | `/api/kyc/status` sans JWT → 401 ; `/api/providers/status` sans JWT → 401 |
| Secret scan | aucun `sk_live_`/`pk_live_`/`whsec_`/token réel dans le code, les migrations ou les docs |
| Frontend | `npm run build` OK |

### Validation des tests par test négatif

Un test vert ne prouve rien tant qu'il n'a pas été vu échouer. Deux garde-fous
critiques ont été volontairement cassés puis restaurés :

1. **Idempotence du webhook** — garde supprimé → le test de rejeu **échoue**
   (l'événement est retraité). Restauré → PASS.
2. **Vérification de signature** — `hash_equals` remplacé par `return true` →
   **3 tests de sécurité échouent**. Restauré → 23/23 PASS.

---

## FIXED — problèmes corrigés

### 1. `ProviderCredentialController` : requêtes non déterministes (§3)
Les opérations SELECT / UPDATE / DELETE filtraient sur `(user_id, provider_slug)`
sans `environment`. Depuis que la contrainte SQL autorise la coexistence
sandbox + production, un `LIMIT 1` choisissait **arbitrairement** l'une des deux
lignes : un test de connectivité pouvait viser la production en croyant tester
la sandbox, et un `DELETE` détruisait les deux environnements.
→ Nouveau `ProviderCredentialService` : **toute** opération est qualifiée par
`user_id + provider_slug + environment`. `DELETE` et `test` exigent désormais un
paramètre `environment` explicite.

### 2. Quatrième liste de migrations codée en dur — **faux PASS de tests**
`setup_test_db.php` embarquait sa propre copie de la liste (les trois autres
avaient été unifiées en phase SQL). Conséquence concrète et observée : les
premiers tests d'isolation **échouaient** parce que la base de test était
construite sur l'ancien schéma. Le symptôme aurait pu être lu comme « le code
est cassé » alors que c'était l'outillage de test qui l'était.
→ `setup_test_db.php` lit désormais `database/migrations.manifest`.

### 3. Seeding de démonstration non gardé (§29)
Quatre points d'injection de données fictives n'avaient aucun garde `APP_ENV`,
dont un chemin **crédite des soldes directement en base sans passer par le
ledger** (inscription via Google).
→ `Nexus\Core\DemoMode` centralise le garde-fou (production = refus
inconditionnel, non réactivable par variable d'environnement) et couvre les
5 points d'injection, y compris celui du parcours Google initialement manqué.

### 4. `GOOGLE_CLIENT_ID` en dur (§30)
Valeur réelle codée en dur dans `config/constants.php`, `.env.example` **et**
`nexus-frontend/src/components/GoogleButton.tsx`.
→ Backend : lecture exclusive depuis l'environnement. Un garde supplémentaire
refuse l'authentification si la constante est vide — sans lui, un `aud` vide
aurait « matché » une constante vide et validé un jeton.
→ Frontend : `VITE_GOOGLE_CLIENT_ID` + `.env.example` créé.

### 5. URL de base de Nium erronée
Le catalogue pointait vers **Airwallex** (`www.airwallex.com/api/v1`).
→ Corrigé d'après la documentation officielle Nium
(`api.spend.nium.com/api` / `gateway.nium.com/api`).

### 6. Faux positif de l'audit SQL
`sql_contract_audit.php` ne reconnaissait pas `INSERT IGNORE INTO` et signalait
`kyc_webhook_events` comme « jamais référencée ». Un faux positif toléré finit
par masquer un vrai problème.
→ Détection corrigée.

### 7. Corps brut de requête indisponible
La vérification HMAC d'un webhook exige les **octets exacts** reçus ; `Request`
n'exposait que le JSON décodé, dont la ré-encodage aurait invalidé toute
signature.
→ `Request::rawBody()` (+ `Request::headers()`), compatible avec l'injection de
corps utilisée par les tests.

---

## PROVIDERS

Détail complet : `docs/NEXUS-PROVIDER-CONFIG.md`.

| Provider | Adapter | Schéma credentials | Environnements | Clés publiques | Webhook | Statut |
|---|---|---|---|---|---|---|
| Stripe | dédié | **vérifié** (doc officielle) | sandbox/production par préfixe de clé | `publishable_key` **uniquement** | `webhook_secret` | opérationnel (config) |
| pawaPay | dédié | **vérifié** | URLs distinctes, tokens non interchangeables | **aucune** | signature optionnelle | opérationnel (config) |
| Wise | générique | **vérifié** | credentials séparées | **aucune** (`client_id` inclus) | UNKNOWN | opérationnel (config) |
| Nium | générique | **vérifié** | URLs distinctes (corrigées) | **aucune** | UNKNOWN | opérationnel (config) |
| Onafriq | générique | **UNKNOWN** | non confirmé | aucune (précaution) | UNKNOWN | non vérifié |
| Thunes | générique | **UNKNOWN** | non confirmé | aucune (précaution) | UNKNOWN | non vérifié |

**Classification des clés publiques (§6)** : le défaut est *backend-only*.
Seule la `publishable_key` de Stripe est marquée exposable, sur la foi d'une
citation explicite de sa documentation. Le piège inverse est traité : la clé de
signature de pawaPay, bien que nommée « publique » dans son dashboard, n'est
**pas** destinée au navigateur et reste backend-only.

**Opérations non implémentées** : les adaptateurs lèvent
`ProviderOperationNotImplemented` — aucun succès n'est simulé.

---

## SUMSUB

| Élément | État |
|---|---|
| Abstraction `KycProvider` | **opérationnelle** — le Core ne dépend pas de Sumsub |
| `SumsubAdapter` | **opérationnel** — signature HMAC conforme à la doc (`ts + METHOD + path + body`) |
| KYC Personal | parcours applicant + session + statut |
| KYB Business | niveau distinct (`SUMSUB_LEVEL_NAME_KYB`), `applicantType=company`, jamais réduit à un booléen |
| Applicant | persisté (`kyc_verifications`), lié à `user_id` |
| Vérification | statut traduit en vocabulaire Nexus, jamais le vocabulaire provider |
| Webhook | signature `x-payload-digest` vérifiée en **temps constant** (`hash_equals`) |
| Idempotence | `(provider, environment, event_id)` — contrainte **UNIQUE en base**, pas seulement applicative |
| Policy | seul `VERIFIED` élève `kyc_level` ; `REJECTED` le rétrograde ; tout autre statut est sans effet |
| Sécurité | secret absent en Git/SQL ; frontend ne reçoit qu'un token court mono-applicant |

**Mapping des statuts** : `completed`+`GREEN` → VERIFIED ; `completed`+`RED`+`RETRY`
→ RESUBMISSION_REQUESTED ; `completed`+`RED`+`FINAL` → REJECTED ; `pending`/`queued`/
`prechecked` → PENDING ; `onHold`/`awaitingUser`/`awaitingService` → ON_HOLD.
Un statut inconnu n'est **jamais** interprété comme vérifié (testé).

**Aucun appel réel à Sumsub** n'est effectué dans les tests (transport injecté).
Aucune vérification réussie n'a été simulée.

---

## DATABASE

**SQL freeze : une exception documentée.**

Migration `2026_08_14_kyc_verifications.sql` — 2 tables :

- `kyc_verifications` : lien user ↔ applicant provider (+ environnement, type
  de sujet, statut). `users.kyc_level` ne peut ni stocker un identifiant
  d'applicant externe, ni distinguer KYC de KYB, ni porter l'environnement.
- `kyc_webhook_events` : **rend l'idempotence possible**. Sans persistance des
  événements, la garantie « aucun événement traité deux fois » du §24 serait
  invérifiable.

**Non stocké** (§23) : aucun document, selfie, donnée biométrique, secret
Sumsub, ni réponse brute. La source de vérité documentaire reste Sumsub.

Après migration : 21 tables, 254 colonnes, 67 index, 22 FK, 0 flottant.
Fresh install, idempotence et équivalence des deux chemins d'installation : PASS.

---

## SECURITY

| Contrôle | Résultat |
|---|---|
| Secret scan | PASS — aucun secret réel en Git |
| Credential isolation | PASS — 4 directions sandbox/production testées |
| Chiffrement | AES-256-GCM via `APP_KEY`, jamais en SQL ; vérifié en base |
| Frontend exposure | PASS — `frontend_exposable` explicite, défaut backend-only, provider non vérifié → rien exposé |
| Webhook security | PASS — HMAC temps constant, corps brut, algorithme inconnu refusé, absence de secret = refus |
| Tenant isolation | 22 FK, orphelins impossibles ; RBAC `requireRole` sur 23 points d'entrée |
| RBAC | 403 confirmé sur tous les refus d'autorisation |
| Production strict mode | conservé — `APP_ENV=production` force `strictMode`, mode démo impossible |
| Audit logs | PASS — seuls `environment`, `reachable`, `latency_ms` ; aucun secret |

---

## REMAINING — ce qui n'est pas terminé

1. **Onafriq / Thunes : schémas UNKNOWN.** Documentation d'authentification non
   confirmée. Marqués non vérifiés, aucune credential exposable. À confirmer
   avant production.

2. **Opérations métier des adaptateurs non implémentées.** `getQuote`,
   `createPayment`, `getPaymentStatus`, `cancelPayment`, `getBalance` lèvent
   `ProviderOperationNotImplemented`. Aucun mouvement de fonds réel n'est câblé.
   L'interface existe — **cela ne signifie pas que la fonctionnalité est prête**.

3. **Health check limité à une sonde TCP.** `configured` ≠ `healthy` est
   respecté conceptuellement, mais la sonde n'authentifie pas auprès du provider.
   Un provider joignable mais aux credentials invalides ne sera pas détecté.

4. **Pas de table `businesses`.** L'isolation multi-tenant reste applicative
   (`users.account_type` + `team_members`). Décision d'architecture non tranchée.

5. **KYB partiel.** Le parcours entreprise est en place (niveau distinct, type
   `company`), mais la collecte structurée des **représentants** et
   **bénéficiaires effectifs** n'est pas modélisée côté Nexus — elle reste chez
   Sumsub. À arbitrer selon les obligations réglementaires visées.

6. **Rotation des secrets** (§17) : l'architecture le permet (variables
   d'environnement, aucun secret en code), mais aucune procédure de rotation
   sans interruption n'est outillée.

7. `oauth_identities` : table morte, à supprimer par migration.

8. Frontend : 1 erreur de lint (hook conditionnel `AnalyticsPage.tsx`), bundle
   > 500 kB.

---

## Critère de fin de phase — évaluation honnête

| Critère | État |
|---|---|
| Provider credentials = réel | **oui** (chiffrées, scopées, testées) |
| Sandbox / production isolés | **oui** (4 directions testées) |
| Provider Registry opérationnel | **oui** (aucun `if ($provider === 'stripe')` dans le Core) |
| Provider adapters opérationnels | **partiel** — configuration/santé oui, opérations métier non |
| No hardcoded secrets | **oui** |
| Clés publiques correctement catégorisées | **oui** (sources citées) |
| Production strict mode obligatoire | **oui** |
| Provider status API sécurisé | **oui** |
| Abstraction Sumsub opérationnelle | **oui** |
| Adapter Sumsub opérationnel | **oui** (aucun appel réel effectué) |
| KYC Personal réel | **oui**, sous réserve de credentials Sumsub réelles |
| KYB Business réel | **partiel** (UBO/représentants non modélisés) |
| Webhook sécurisé + idempotent | **oui** (prouvé de bout en bout) |
| Policy Engine connecté | **partiel** — `kyc_level` piloté par le webhook ; règles de limites non recâblées |
| Business isolation vérifiée | **oui** au niveau FK/RBAC ; réserve architecturale |
| Ledger intact | **oui** |
| SQL contract intact | **oui** |
| 172+ tests PASS | **oui — 209** |

**Aucune fonctionnalité n'est déclarée « ready » au seul motif que son interface
existe.** Les points 2, 3 et 5 sont explicitement incomplets.
