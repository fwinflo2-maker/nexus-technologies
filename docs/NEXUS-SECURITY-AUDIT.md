# NEXUS — AUDIT FINAL DE SÉCURITÉ & ROBUSTESSE

> **Date :** 2026-08-14 · **Périmètre :** Provider Credentials + Personal + Business.
> **Environnement de vérification :** MariaDB 11.8 + PHP 8.4 + Vite 8 + Playwright (Chromium headless).
> Tout ce qui est marqué PASS a été **réellement exécuté** (pas seulement compilé).

---

## PASS

### Credentials & secrets (§1)
- Aucun secret hardcodé : scan complet du repo (patterns `sk_`, `pk_`, `whsec_`, `AKIA`, `AIza`, clés privées, `ghp_`) → uniquement des placeholders de formulaire et de fausses valeurs de test.
- Aucun secret dans Git : `.env` non suivi (vérifié via `git ls-files`).
- Aucun secret dans le frontend, les réponses API, les logs, les exceptions ou les migrations SQL (scan `error_log` + `git grep`).

### APP_KEY & AES-256-GCM (§2)
- `APP_KEY` absente de Git, MySQL, logs et API (vérifié).
- Chiffrement authentifié AES-256-GCM, IV aléatoire (12 octets) par chiffrement, tag GCM (16 octets) vérifié par `openssl_decrypt`.
- **Tests `CryptoTest` (5 tests)** : round-trip ✅, IV unique ✅, altération du tag → échec ✅, altération du ciphertext → échec ✅, entrées dégénérées → null ✅.
- « Mauvaise clé » = tag invalide (garanti par GCM) → `decrypt` renvoie `null`.

### Sandbox / Production (§4)
- Séparation stricte : `PROVIDER_{SLUG}_{SANDBOX}_{FIELD}` vs `PROVIDER_{SLUG}_{PRODUCTION}_{FIELD}`.
- **Test** : clé sandbox jamais lue comme clé production (et inversement) ✅.
- La clé est scoped à l'environnement demandé, en priorité sur le générique.

### DÉMO MODE impossible en production (§5) — CORRIGÉ
- **Vulnérabilité corrigée** : `ProviderRegistry::isAvailableForRouting()` renvoyait `true` (catalogue) quand le mode strict était off, **sans considérer `APP_ENV`**. Un mode démo implicite aurait été possible en production.
- **Correctif** : `ProviderConfig::strictMode()` force `true` en production (`isProduction()`).
- **Tests** : `test_demo_mode_impossible_in_production` (PHPUnit) + vérification API : `APP_ENV=production` sans provider → quote refusée `NO_PROVIDER` ✅.

### Provider Registry / Adapters / Status (§6-8)
- Interface commune `ProviderAdapter` + adaptateurs (Stripe, pawaPay, générique). Aucun `if ($provider === 'stripe')` dans le Core (scan effectué) ✅.
- Statuts : configured / missing_credentials / invalid_configuration / disabled / healthy / degraded / unavailable — **transitions testées** (missing→configured, configured→unavailable).
- Routing : avec pawaPay seul configuré, le CapabilityEngine ne renvoie que pawaPay (test PHPUnit + vérification API : 1 route au lieu de 3).
- `GET /api/providers/status` : retourne uniquement slug/environment/status/**enabled**/capabilities/**last_health_check** — **aucun secret** (vérifié sur la réponse JSON).

### Personal / Business non-régression (§9-10)
- E2E API : 13/13 PASS — login, wallets, quote, exécution (solde **exact au centime** : 2500 − 100 − 3.07 = 2396.93), historique, overview, bénéficiaire, paiement, approbation, exécution, réconciliation.
- RBAC : operator bloqué (403) sur approbation (vérifié aux tours précédents).

### Cross-tenant (§11)
- Biz2 → données Biz1 : 403 `FORBIDDEN_CROSS_BUSINESS` ✅
- Personal non-membre → données Biz : 403 `FORBIDDEN_ROLE` ✅
- User B → transaction User A : 404 ✅ (vérifié aux tours précédents + ce tour)

### SQL (§14-15)
- Base vierge → `migrate.sh` : 19 tables, 0 erreur ✅
- 2e exécution (idempotence) : 0 erreur ✅
- Contrat SQL↔code : colonnes `users`, `transactions` conformes aux services ✅ (et couvert par 172 tests d'intégration).

### Build & runtime (§16)
- `npm run build` : ✅ (478→477 modules, tsc + vite).
- Runtime Vite → proxy → PHP → MariaDB : health/login OK.

### Internationalisation (§17) & RTL (§18)
- Dictionnaire des dashboards étendu à **~90 clés × 7 langues** (fr/en/es/pt/de/ar/zh).
- Navigation (Sidebar/Navbar), titres de topbar, statuts, méthodes, KPI, libellés de formulaires et états vides des pages Business + KYC/Agents câblés.
- **RTL arabe : PASS** (E2E : `document.documentElement.dir === 'rtl'` en arabe, `ltr` en fr).

### E2E navigateur (§19)
- Playwright + Chromium headless installé et exécuté.
- Login Personal & Business ✅ ; **toutes les routes** Personal (dashboard, wallet, send, receive, convert, history, settings, kyc, agents) et Business (treasury, payments, approvals, beneficiaries, reconciliation, team, reporting) rendent **sans page blanche** ✅.
- **Mobile 390px : pas d'overflow horizontal** ✅.
- Console : **aucune erreur critique** (hors warning tiers Google Identity Services).

---

## CORRECTIFS

1. **Mode démo en production** (critique) : `ProviderConfig::isProduction()` + `strictMode()` force le routing strict en production.
2. **`GET /api/providers/status`** : ajout des champs `enabled` et `last_health_check` (contrat §12).
3. **Tests** : `CryptoTest` (AES-256-GCM), `ProviderRegistryTest` étendu (transitions, routing filter, demo-in-prod) — suite complète **172 tests, exit 0**.
4. **i18n/RTL** : expansion du dictionnaire + `dir=rtl` automatique pour l'arabe.
5. **Health check testable** : sonde TCP sur serveur éphémère local (supprime la dépendance au port 8080).

---

## FAIL

- **Aucun échec bloquant.** Les seuls points non-verts sont documentés en REMAINING.

---

## SQL — commandes réellement exécutées

```bash
mysql -e "DROP DATABASE IF EXISTS nexus;"
bash database/migrate.sh          # → schéma + 12 migrations, 0 erreur
bash database/migrate.sh          # → 2e run, 0 erreur (idempotence)
mysql nexus -e "SHOW TABLES;"     # → 19 tables
```

## SECURITY — tests réalisés

- Scan de secrets (git grep, patterns multiples) : OK.
- `CryptoTest` : 5 tests (round-trip, IV unique, tag tamper ×2, dégénérés).
- `ProviderRegistryTest` : 24 tests (configuré, non configuré, manquants, invalides, sandbox, production, disabled, santé, unavailable, health check, mode strict, demo-in-prod, fuite de secrets, webhooks, transitions, routing filter).
- Cross-tenant API : 13 contrôles PASS (ce tour + tours précédents).
- Production mode API : quote refusée `NO_PROVIDER`.

## E2E — parcours navigateur testés

- Login Personal → dashboard → 8 routes → aucune page blanche.
- Login Business → dashboard → 7 routes → aucune page blanche.
- RTL arabe / LTR français.
- Viewport mobile 390×844 sans overflow.
- Console : aucune erreur critique.

## I18N — état réel des 7 langues

- fr / en / es / pt / de / ar / zh : **navigation, titres, statuts, méthodes, KPI, formulaires, états vides** = 7/7.
- Corps de texte approfondi (wizard Send, paragraphes du dashboard Personal, KYC détaillé) : **partiellement en français** — câblé pour être complété itérativement (une clé = un texte).

## REMAINING (objectif, non masqué)

1. **i18n corps de texte** : le wizard Send et les descriptions longues restent en français (clés créées pour le chrome ; le corps doit être traduit au fil de l'eau).
2. **Rotation `APP_KEY` scriptée** : la procédure est documentée mais pas encore automatisée (à faire quand des credentials réels seront en DB).
3. **`last_health_check`** : non persisté (health check à la demande) — une table d'historique de santé sera ajoutée quand les providers seront intégrés.
4. **Warning tiers Google Sign-In** : le client ID de démo n'est pas whitelisté pour `127.0.0.1:5173` (cosmétique, disparaît avec un vrai client ID).
5. **Tests E2E commités** : le script Playwright est exécuté mais vit hors du dépôt (`vendor/` déjà tracké ; à intégrer proprement dans une CI plus tard).
6. **`nexus-api/vendor/` tracké dans Git** (1689 fichiers, état pré-existant) : à retirer du suivi + `vendor/` dans `.gitignore` (changement d'historique à faire proprement).

---

*NEXUS = SIMPLE EXPERIENCE + COMPLEX ORCHESTRATION UNDER THE HOOD.*
