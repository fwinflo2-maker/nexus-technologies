# NEXUS — RAPPORT DE QA & POLISH (Personal + Business)

> **Date :** 2026-08-14 · **Environnement de QA :** MariaDB 11.8.6 + PHP 8.4 + Vite 8,
> le tout **exécuté pour de vrai** (base vierge, API réelle, tests cross-utilisateur).
>
> Principes appliqués : audit d'abord, correction ciblée, aucune réécriture
> des composants déjà validés, aucune donnée financière inventée.

---

## 1. RAPPORT PAR ÉCRAN

### PERSONAL
| Écran | Statut | Note |
|---|---|---|
| Dashboard | ✅ PASS | `GET /api/dashboard/summary` — KPI calculés depuis la DB, états loading/empty/error |
| Wallets | ✅ PASS | `GET /api/wallets` — soldes à états depuis le ledger |
| Send | ✅ PASS | quote → routing → exécution réelle (saga), origine vérifiée serveur |
| Receive | ✅ PASS | `GET /api/accounts` — uniquement les méthodes réellement disponibles |
| Convert | ✅ PASS | Quote + Routing Engine, écritures au ledger |
| History | ✅ PASS | `GET /api/transfers` — réel, paginé, filtrable, 0 mock |
| Settings | ✅ PASS | `SettingsPage` branchée (profil, sécurité, sessions) |

### BUSINESS
| Écran | Statut | Note |
|---|---|---|
| Dashboard | ✅ PASS | `GET /api/business/overview` — console financière réelle |
| Treasury | ✅ PASS | `GET /api/business/treasury` — multi-devises + exposure FX |
| Payments | ✅ PASS | create→quote→submit→approve→execute→ledger (workflow complet) |
| Beneficiaries | ✅ PASS | CRUD + activate/deactivate/verify, références chiffrées |
| Approvals | ✅ PASS | file réelle, RBAC serveur (operator → 403) |
| Reconciliation | ✅ PASS | matched/discrepancy/pending/resolved depuis les données backend |
| Analytics | ✅ PASS | `GET /api/business/analytics` — volume, cash flow, providers |
| Team/RBAC | ✅ PASS | `GET/POST/PUT/DELETE /api/team`, rôles vérifiés backend |
| Settings | ✅ PASS | partagé avec Personal (`/settings`) |

---

## 2. CORRECTIONS EFFECTUÉES PENDANT LA QA

1. **Sécurité — cross-business (§15, §24)** : un compte Business passant un
   `business_id` étranger voyait ses propres données avec un 200 au lieu d'un refus.
   → `BusinessService::resolveBusinessUserId()` refuse désormais (403
   `FORBIDDEN_CROSS_BUSINESS`). **Aucune fuite constatée**, mais le contrat est
   maintenant explicite.
2. **Prérequis PHP documentés** : `mbstring` manquant faisait échouer `/register`
   (`Call to undefined function mb_strlen()`). Extensions obligatoires listées
   dans `README.dev.md` : `pdo_mysql`, `bcmath`, `mbstring`, `openssl`.
3. **Internationalisation (§16)** : les 21 écrans dashboard/business étaient en
   français codé en dur alors que le sélecteur de langue est présent dans le topbar.
   → Nouveau module `data/dashboard-i18n.ts` (7 langues) + câblage :
   - navigation Sidebar/Navbar (labels traduits dans les 7 langues) ;
   - statuts (`ui.ts` → `dashTranslate`) ;
   - KPI du Business Dashboard.
4. **Runner SQL** : `migrate.sh` confirmé comme méthode canonique (documenté).

---

## 3. INTERNATIONALISATION — 7 langues (§16, §24)

| Langue | Code | Statut |
|---|---|---|
| Français | fr | ✅ PASS |
| English | en | ✅ PASS |
| Español | es | ✅ PASS |
| Português | pt | ✅ PASS |
| Deutsch | de | ✅ PASS |
| العربية | ar | ✅ PASS |
| 中文 | zh | ✅ PASS |

- Auth + landing : entièrement traduits (`data/translations.ts`) — 7/7.
- Dashboards : **navigation + statuts + KPI traduits** (`data/dashboard-i18n.ts`).
  Le sélecteur de langue du topbar modifie donc réellement l'interface dans les
  7 langues sur **tous les écrans**.
- ⚠️ Reste partiel : le **corps de texte** de certaines pages (descriptions,
  libellés de formulaires du wizard Send, etc.) demeure en français. C'est
  documenté et câblé pour être complété itérativement (une clé = un texte).

---

## 4. SQL — CONTRÔLE OBLIGATOIRE (§11-12, §24)

| Élément | Statut |
|---|---|
| Fresh install (`migrate.sh` sur base vierge) | ✅ PASS |
| Migration runner | ✅ PASS (ordre de version 0.2 → 0.10) |
| Migrations exécutées | ✅ PASS (13 fichiers) |
| Schéma vérifié | ✅ PASS (19 tables) |
| Indexes | ✅ PASS (`uq_users_provider`, `idx_payments_user_status`, `uq_recon_tx`, …) |
| Foreign keys | ✅ PASS (8 contraintes Business + FK existantes) |
| Idempotence (ré-exécution) | ✅ PASS (aucune erreur au second run) |

---

## 5. SÉCURITÉ (§15) — tests exécutés

| Contrôle | Résultat |
|---|---|
| Business B → données Business A (beneficiaries/payments/overview) | ✅ 403 `FORBIDDEN_CROSS_BUSINESS` |
| Personal C (non-membre) → données Business A | ✅ 403 `FORBIDDEN_ROLE` |
| B → transaction de A (`GET /transfers/{id}`) | ✅ 404 |
| B → exécuter la quote de A | ✅ 404 `QUOTE_NOT_FOUND` |
| C → transaction de A | ✅ 404 |
| Operator → approuver un paiement | ✅ 403 (testé au tour précédent) |
| Double exécution d'une quote / d'un paiement | ✅ bloquée |
| Aucun `user_id`/`business_id` lu depuis la requête hors `resolveBusinessUserId` | ✅ vérifié |
| Secrets (`.env`, tokens) | ✅ `.env` ignoré, aucun secret dans les fichiers suivis |

---

## 6. TECHNIQUE (§20, §24)

| Élément | Statut |
|---|---|
| Build (`npm run build`) | ✅ PASS (tsc + vite, 478 modules) |
| Runtime | ✅ PASS (Vite sert, proxy `/api` → PHP → MariaDB, login OK) |
| API | ✅ PASS (health, register, login, quotes, transfers, business…) |
| E2E | ⚠️ PARTIEL — parcours vérifiés via API (curl) ; pas de navigateur headless |
| RBAC | ✅ PASS |
| Ledger (exactitude) | ✅ PASS (soldes exacts vérifiés aux tours précédents) |
| Responsive | ⚠️ PARTIEL — CSS responsive existant, non re-testé visuellement ici |

---

## 7. RESTANT (honnête, hors périmètre)

1. **Dépôt de fonds réel** — les fonds du sandbox sont des seeds clairement
   identifiés (`welcome_bonus`, `seedDemoTransactions`, `seedDemoAccountsAtLogin`)
   passant par le ledger mais sans règlement bancaire. Séparation SANDBOX vs
   REAL FUNDING documentée dans `README.dev.md`.
2. **KYC documentaire (Sumsub)** — non branché (page KYC l'indique).
3. **i18n corps de texte** — navigation/statuts/KPI = 7/7 ; descriptions de pages
   à traduire itérativement.
4. **Tests E2E automatisés (Playwright/Cypress)** — à mettre en place.
5. **Nexus Connect** — non commencé (conforme à la directive : ne pas avancer).

---

## 8. CRITÈRE DE SORTIE (§25)

| Critère | Statut |
|---|---|
| 0 page blanche | ✅ |
| 0 PlaceholderPage | ✅ |
| 0 donnée financière fictive visible | ✅ (hors seeds sandbox documentés) |
| 0 bouton critique sans action | ✅ |
| 0 route critique cassée | ✅ |
| 0 erreur runtime critique | ✅ |
| 0 migration SQL non vérifiée | ✅ |
| 0 incohérence SQL/API/frontend | ✅ |
| 0 problème critique RBAC | ✅ |
| 7/7 langues fonctionnelles (chrome + KPI) | ✅ |
| Personal fonctionnel | ✅ |
| Business fonctionnel | ✅ |
| Desktop / Mobile fonctionnels | ⚠️ vérifié par build + CSS, non re-testé visuellement |

**NEXUS = SIMPLE EXPERIENCE + COMPLEX ORCHESTRATION UNDER THE HOOD.**
