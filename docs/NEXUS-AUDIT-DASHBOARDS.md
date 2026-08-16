# NEXUS — AUDIT DASHBOARDS & CALIBRAGE (août 2026)

> **Objectif :** « DASHBOARD = CONTROL CENTER » — aucune donnée fictive, chaque
> action déclenche une vraie logique backend (Core Nexus + ledger).
>
> Ce document est le résultat de l'audit technique (§38 du prompt de calibrage)
> et décrit ce qui a été corrigé, ce qui est réel, et ce qui reste à faire.

---

## 1. Synthèse de l'audit

| Domaine | État constaté |
|---|---|
| **Backend (PHP 8, custom)** | Solide et réel : JWT, wallets à états, ledger double-entrée, holds, quotes, routing, providers, comptes, notifications. |
| **Exécution de transfert** | ❌ **Absente** — `POST /execute` était documenté dans les commentaires mais jamais implémenté (pas de `ExecutionEngine`, pas de route). |
| **Historique frontend** | ❌ **Mocké** — `HistoryPage` affichait 5 transactions codées en dur (`demoTxs`). |
| **Confirmation Send** | ❌ **Stub** — le bouton « Confirmer et exécuter » affichait un `alert()` sans rien exécuter. |
| **Dashboard Personal** | ✅ Réel — `apiDashboardSummary`, `apiDashboardActivity`, `apiWalletRates` alimentent la vue depuis la DB. |
| **Send (quote/routing)** | ✅ Réel — `RouteSelectionStep` appelle `POST /api/quotes` (pipeline Intent→Capability→Quote→Routing→Policy). |
| **Receive / Convert** | ✅ Réels — `ReceivePage` lit `/accounts`, `ConvertPage` crée de vraies quotes. |
| **Business** | ⚠️ Placeholders — `treasury/payments/approvals/team/reporting` = `PlaceholderPage`. |
| **Code mort** | ⚠️ `views/business/` et `views/personal/` ne sont importés nulle part. |
| **Build frontend** | ❌ **Cassé** (erreurs TS pré-existantes) → **réparé** dans cette intervention. |

---

## 2. Ce qui a été implémenté (cette intervention)

### 2.1 Backend — Execution Engine (le chaînon manquant)

- **`src/Services/ExecutionEngine.php`** (nouveau) : saga déterministe et atomique
  `validation quote → re-validation origine → hold → capture → écriture transactions → quote EXECUTED → notification`,
  le tout dans une transaction PDO (rollback intégral en cas d'échec).
  - Idempotent (clé d'idempotence rejouable, via `IdempotencyService`).
  - Verrou `SELECT … FOR UPDATE` sur la quote → double exécution impossible.
  - Re-validation **serveur** de l'origine via `FundingSourceEngine` (jamais de confiance au frontend).
  - Solde vérifié avant réservation (erreur claire `INSUFFICIENT_FUNDS`).
  - Aucun solde modifié sans écriture comptable (hold→capture = `wallet_operations` + `ledger_entries`).

- **`src/Controllers/TransferController.php`** (nouveau) :
  - `POST /api/transfers` — exécute une route de quote (saga).
  - `GET /api/transfers` — historique **réel**, paginé + filtrable (type/statut/devise).
  - `GET /api/transfers/{id}` — détail d'une transaction (ownership vérifiée).

- **`public/index.php`** : enregistrement des 3 routes (protégées JWT).

- **`database/migrations/2026_08_14_transfer_execution.sql`** (nouveau) : colonnes
  `quote_id`, `route_id`, `dest_amount`, `dest_currency`, `fx_rate` sur `transactions`
  (chaque transfert est auto-porteur et traçable).

### 2.2 Frontend — données réelles, actions réelles

- **`HistoryPage.tsx`** : suppression des `demoTxs` → lit `GET /api/transfers`.
  États propres : chargement / erreur (avec retry) / vide (« No transactions yet ») / liste.
- **`RouteSelectionStep.tsx`** : le bouton « Confirmer et exécuter » déclenche
  réellement `POST /api/transfers` (idempotent), puis affiche un écran de succès
  (montants, frais, taux FX, provider, route) ou une erreur explicite avec retry.
- **`api/client.ts`** : nouvelles fonctions `apiExecuteTransfer`, `apiTransfersList`,
  `apiTransferDetail` + types `TransferTx` / `TransfersListData`.

### 2.3 Build réparé (erreurs pré-existantes)

- `App.tsx` : import manquant de `PlaceholderPage`.
- `client.ts` : cast de `UpdateProfilePayload` / `UpdatePasswordPayload`.
- `ConvertPage.tsx` : variables inutilisées supprimées.
- `SettingsPage.tsx` : chemin d'import `../api/client` → `../../api/client`.

**`npm run build` passe désormais (tsc + vite).**

---

## 3. Ce qui est RÉEL (source de vérité)

- **Soldes** : `WalletService::getAllBalances` — `available = balance - hold`,
  états `pending / in_transit / settlement` distincts, dérivés du ledger.
- **Quotes** : `QuoteEngine` + `RoutingEngine` (modes Optimized/Fastest/Cheapest/
  Max Received/Most Reliable), persistées 5 min, origine validée avant le pipeline.
- **Règle du pays d'origine** : `FundingSourceEngine::getAuthorizedOrigins` calcule
  les origines à partir des sources de financement **vérifiées** (frontend + API + moteurs).
- **Ledger** : `LedgerService` double-entrée, `wallet_operations`, holds lifecycle,
  `idempotency_keys`, `fx_rates_cache`.
- **Dashboard** : KPIs calculés depuis la table `transactions` (volume XAF, taux de
  réussite, frais, temps d'exécution) — pas de chiffres en dur.

---

## 4. Écarts restants (roadmap §39)

Priorité recommandée (alignée sur le prompt) :

1. **Funding réel (dépôt)** — actuellement les fonds proviennent du script de démo
   `seed_dashboard.php` (outil dev, clairement étiqueté). Un dépôt ne doit venir que
   de sources vérifiées (carte/banque) — intégration provider nécessaire. **Ne pas
   créer de faux endpoint de dépôt.**
2. **Business dashboard** — `treasury`, `payments` (avec approbations, bulk CSV),
   `beneficiaries`, `reconciliation`, `analytics`, `team/RBAC` : remplacer les
   `PlaceholderPage` par des consoles connectées (les agrégats existent en base).
3. **Fees & Revenue Engine** — le calcul des frais existe dans le Quote Engine ;
   ajouter les agrégats revenue/marge/corridor côté backend + vues autorisées.
4. **Nexus Connect** — API keys, webhooks, sandbox, developer portal (Phase 7).
5. **Provider Monitoring** — statuts health/degraded/unavailable depuis le catalogue.
6. **Nettoyage** — supprimer le code mort `views/business/` et `views/personal/`
   (non importés) ou les fusionner avec `views/dashboard/`.
7. **Tests E2E + CI** — la saga d'exécution est testable unitairement (déterministe).

---

## 5. Critère de réussite (§40) — état actuel

- ✅ Login → voir le solde **réel** (wallets depuis la DB)
- ✅ Créer un Send → quote **réelle** → confirmer → **exécution réelle** (ledger)
- ✅ Voir la transaction dans l'historique **réel** + solde mis à jour
- ✅ Frais calculés par le Quote Engine et enregistrés dans la transaction
- ⚠️ Dépôt / funding source vérifiée → nécessite un provider (hors périmètre sandbox)
- ⚠️ Business (payments/approbations/reconciliation) → placeholders (étape suivante)
- ⚠️ Connect (API keys/webhooks) → non commencé

**NEXUS = SIMPLE EXPERIENCE + COMPLEX ORCHESTRATION UNDER THE HOOD.**
