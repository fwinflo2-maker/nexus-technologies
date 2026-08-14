# NEXUS — Rapport d'audit Phase 1

**Date :** 14 août 2026
**Périmètre :** audit préalable exigé par le prompt maître (§1), correction des
blocages empêchant toute vérification ultérieure (§2, §32).
**Environnement :** PHP 8.4.24, MariaDB 11.8.6, Node 20.20.2, Vite 8.2.1.

> Ce rapport applique la règle §37 : rien n'est déclaré conforme sans preuve
> d'exécution. Ce qui n'a pas été fait est listé tel quel en REMAINING.

---

## 1. PASS — vérifié par exécution

| Contrôle | Exigence | Résultat |
|---|---|---|
| Suite PHPUnit complète | §32 — 0 failing | **172 tests / 932 assertions OK** |
| Fresh install SQL | §31 | `DROP` + `CREATE` + `migrate.sh` → 12 migrations |
| Idempotence migrations | §31 — 2ᵉ exécution sans erreur | **0 erreur** |
| Structure DB | §31 | 19 tables, 20 clés étrangères, 58 index |
| Build frontend | §35 | `npm run build` OK (478 modules) |
| Scan de secrets | §30 | **0 secret réel** — seulement des placeholders (`sk_test_...`) et des valeurs de test |
| Écritures ledger | §28 | **0 occurrence** de `UPDATE wallets SET balance` hors ledger |
| Auth non authentifiée | §30 | `/dashboard/summary`, `/wallets`, `/business/overview` → **401** |
| Cloisonnement business | §12 | `BusinessService` : `FORBIDDEN_ROLE` et `FORBIDDEN_CROSS_BUSINESS` en **403** |
| Endpoints Business | §9-14 | 7/7 en **200** (overview, treasury, analytics, payments, beneficiaries, team, reconciliation) |
| Couverture i18n dashboards | §23 | **7/7 langues à 100 %** (150 clés : fr, en, es, pt, de, ar, zh) |
| RTL | §23 | `document.documentElement.dir = 'rtl'` piloté par `I18nContext` |
| PlaceholderPage | §26 | **non référencé** dans `App.tsx` |
| `alert()` | §26 | **0 occurrence** dans `src/` |

---

## 2. FIXED — 4 bugs 500 en production

Tous **reproduits en HTTP avant correction**, tous **revérifiés en 200 après**.

### 2.1 `GET /users/me/sessions` → 500

Mismatch SQL/API (§2) : `ORDER BY created_at` sur `revoked_tokens`, dont le
schéma réel est `(id, jti, user_id, revoked_at, expires_at)`. La colonne
n'existe pas → erreur MySQL 1054.

**Correctif :** tri sur `revoked_at`.

### 2.2 `PUT /users/me/password` → 500

`UserController::audit()` appelle `$request->ipAddress()`, méthode **absente**
de `Request`. Toute tentative de changement de mot de passe échouait.

**Correctif :** ajout de `ipAddress()` et `userAgent()`. `X-Forwarded-For`
n'est honoré que si `TRUSTED_PROXY` est actif — l'en-tête est falsifiable par
le client et ne doit pas être cru en frontal direct.

### 2.3 `DELETE /users/me/sessions/{id}` → 500

Deux défauts cumulés :
- appel à `$request->route('id')`, méthode inexistante (la bonne est `param()`) ;
- `INSERT` omettant `expires_at`, colonne `NOT NULL` sans défaut → SQLSTATE 1364.

**Correctif :** `param('id')` et expiration alignée sur `JWT_TTL`.

### 2.4 `PUT /users/me` — transaction pendante

`Response::badRequest()` était appelé **à l'intérieur** d'une transaction
ouverte. Comme la méthode termine le flux, la transaction restait pendante sur
la connexion PDO partagée, contaminant les requêtes suivantes
(*There is already an active transaction*).

**Correctif :** validation déplacée avant `beginTransaction()`, `rollBack()`
gardé par `inTransaction()`.

### 2.5 Déblocage de la suite de tests

**Cause racine :** `Response::json()` se termine par `exit`. En PHPUnit, le
premier contrôleur appelé tuait le process entier — `UserControllerTest`
(13 tests) était donc **totalement inexécutable**, et c'est précisément ce qui
masquait les 4 bugs ci-dessus.

Correctifs :
- `Response::enableTestMode()` : en test, `json()` lève une `ResponseSent`
  porteuse du statut et du corps au lieu d'appeler `exit`. **Le comportement
  HTTP de production est strictement inchangé.**
- `Request` accepte un corps pré-décodé en test. Les tests écrivaient dans
  `$GLOBALS['_PUT']`, **jamais lu** par `Request` : ils ne testaient rien.
- Assertions alignées sur le contrat réel `{ success, data: {...} }`.

**Avant :** 132 erreurs, 40 tests inexécutables.
**Après :** 172 tests / 932 assertions OK.

### 2.6 Portabilité de l'environnement de test *(commit précédent)*

`setup_test_db.php` dépendait de `C:\xampp\mysql\bin\mysql.exe`. Réécrit en
PDO pur, 12 migrations au lieu de 4, configurable par variables d'environnement.

---

## 3. REMAINING — non traité, par ordre de gravité

### 3.1 Sumsub : **absent du code** (§18-22)

C'est l'écart le plus important au contrat.

```
grep -ri "sumsub" --include="*.php" --include="*.ts" --include="*.sql"  →  0 résultat
```

Sumsub n'existe que dans la **documentation**. Concrètement :

- aucun `SumsubAdapter`, aucun service KYC/KYB ;
- aucune route `/kyc/*` côté API (vérifié dans `public/index.php`) ;
- aucun endpoint webhook, donc aucune vérification de signature ;
- `KycPage.tsx` (67 lignes) se contente d'afficher `user.status` — **aucun
  appel réseau, aucun CTA fonctionnel** ;
- aucune table de vérification, aucun mapping de statuts.

Le `PolicyEngine` consomme déjà `kyc_level` (plafonds LIMITED/STANDARD, seuil
à 1000 EUR) : le point de branchement existe, la source de vérité manque.

**Conforme à §37 :** aucune vérification KYC n'est simulée. L'écran est
honnête sur l'absence de fonctionnalité — mais la fonctionnalité reste à
construire (adapter, migration, webhook signé, mapping, UI).

### 3.2 RBAC : 400 au lieu de 403 (§12)

Un compte **Personal** appelant une route Business reçoit :

```json
{"success":false,"error":"Paramètre business_id requis.","code":"BUSINESS_ID_REQUIRED"}
```

soit **HTTP 400**, alors que §12 exige **403**. Le cloisonnement lui-même
fonctionne (l'accès est refusé, `FORBIDDEN_CROSS_BUSINESS` existe bien), mais
l'ordre des contrôles fait primer la validation de paramètre sur celle du type
de compte. Le code de statut est donc trompeur pour un client d'API.

**Correctif suggéré :** vérifier `account_type !== 'business'` → 403 *avant* de
valider `business_id`.

### 3.3 i18n : ~130 chaînes FR en dur (§23)

Les 150 clés sont traduites en 7 langues, mais du texte français reste écrit en
dur dans le JSX. Les pages passeront donc partiellement en français dans les
6 autres langues :

| Fichier | Chaînes |
|---|---|
| `SendPage.tsx` | 21 |
| `SettingsPage.tsx` | 20 |
| `RouteSelectionStep.tsx` | 16 |
| `WalletPage.tsx` | 9 |
| `BusinessDashboard.tsx` | 9 |
| `DashboardPage.tsx` | 8 |
| `ReceivePage.tsx`, `HistoryPage.tsx`, `AnalyticsPage.tsx`, `AccountsPanel.tsx` | 7 chacun |
| `TreasuryPage.tsx` | 6 |
| 9 autres fichiers | 1 à 3 chacun |

`SendPage` et `RouteSelectionStep` sont prioritaires : ce sont les écrans
d'orchestration, les plus visibles (§5).

### 3.4 Lint : 1 erreur (§25, §34)

`npm run lint` → **1 erreur, 3 avertissements**. L'erreur est un appel de Hook
React non atteignable sur tous les chemins de rendu dans
`views/business/AnalyticsPage.tsx` (~ligne 39) — violation des Rules of Hooks,
risque de désynchronisation d'état.

### 3.5 Bundle 727 kB (§25)

200 kB gzip, au-dessus du seuil Vite de 500 kB. Three.js + GSAP non
code-splittés. Pénalisant au premier chargement.

### 3.6 `GOOGLE_CLIENT_ID` en dur

Présent dans **3 fichiers** : `.env.example`, `config/constants.php` (valeur de
repli) et `components/GoogleButton.tsx`. Identifiant public, donc **pas une
fuite de secret**, mais il lie le dépôt à un projet Google Cloud précis. À
remplacer par une variable d'environnement.

### 3.7 Non vérifié à ce stade

Par honnêteté, ces points du contrat **n'ont pas été testés** :

- E2E navigateur complets (§33) — les parcours Personal/Business n'ont pas
  été rejoués après les corrections ;
- responsive 390/768/1440 (§24) ;
- rendu RTL réel en arabe (§23) — le mécanisme existe, le rendu n'est pas validé ;
- états Loading/Empty/Error de chaque route (§25) ;
- cross-tenant Business A → Business B avec deux entreprises réelles (§12) ;
- `APP_ENV=production` + provider non configuré → absence de route démo (§30).

---

## 4. SQL

| Élément | Valeur |
|---|---|
| Migrations | 12 + `schema.sql` |
| Tables | 19 |
| Clés étrangères | 20 |
| Index | 58 |
| Fresh install | OK |
| 2ᵉ exécution (idempotence) | OK, 0 erreur |
| Base de test | `nexus_test`, recréée par `setup_test_db.php` (PDO, portable) |

---

## 5. SECURITY

| Contrôle | Résultat |
|---|---|
| Scan `sk_`, `pk_`, `whsec_`, `AKIA`, `AIza`, `ghp_`, clés privées | 0 secret réel |
| `.env` versionné | Non — seul `.env.example` l'est, avec placeholders vides |
| Secrets côté frontend | Aucun |
| Chiffrement | AES-256-GCM (`CryptoTest` : 5 tests OK) |
| Cloisonnement business | `FORBIDDEN_CROSS_BUSINESS` en 403 |
| Routes sans token | 401 |
| Nouveau : `X-Forwarded-For` | Honoré uniquement si `TRUSTED_PROXY` |

---

## 6. PROVIDERS

Catalogue : Stripe, pawaPay, Onafriq, Thunes, Wise, Nium, Currencycloud, BVNK,
dLocal. **Tous en `missing_credentials`** (aucune credential configurée dans cet
environnement) — état attendu et honnête.

Architecture conforme §15 : le Core ne connaît jamais les credentials, chaque
provider est isolé derrière son adaptateur. `SecretRedactor` et
`WebhookVerifier` sont présents. `ProviderRegistryTest` : 23 tests OK, dont la
séparation sandbox/production.

Aucune valeur de credential n'est exposée par l'API.

---

## 7. KYC

| Élément | État |
|---|---|
| Configuration Sumsub | **Absente** |
| KYC Personal | **Non implémenté** (écran d'affichage de statut uniquement) |
| KYB Business | **Non implémenté** |
| Webhook | **Absent** |
| Intégration Policy | Point de branchement présent (`kyc_level` consommé), source absente |
| Mapping de statuts | **Absent** |

---

## 8. Prochaine étape recommandée

Par rapport coût/risque :

1. **RBAC 400 → 403** — correctif court, exigence explicite du contrat.
2. **Erreur de lint** — Rules of Hooks, risque de bug réel.
3. **i18n `SendPage` + `RouteSelectionStep`** — écrans d'orchestration.
4. **Sumsub** — chantier à part entière : migration, adapter, webhook signé
   idempotent, mapping de statuts, UI. À cadrer avant de coder.
5. **E2E + responsive + RTL** — validation finale.
