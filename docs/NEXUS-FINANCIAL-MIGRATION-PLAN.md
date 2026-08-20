# NEXUS FINANCIAL MODEL — PLAN DE MIGRATION

**Statut** : plan technique et financier (aucun code, aucune migration SQL à cette étape). Dépend de `docs/NEXUS-FINANCIAL-MODEL-TARGET.md` (modèle cible validé).
**Principe directeur** : migrer sans perte de données, sans rupture d'historique, sans compromettre les invariants financiers — et **sans jamais fabriquer d'historique comptable**.

---

## 1. ÉTAT ACTUEL — PHOTOGRAPHIE EXACTE

### 1.1 Wallet

| Fait | Détail (vérifié au schéma) |
|---|---|
| Table | `wallets` : `user_id × currency` (pas d'environnement dans la clé — un wallet sert un seul env par user×devise ; l'env est porté par les opérations) |
| Colonnes utilisées | `balance`, `available_balance`, `hold_balance` |
| Colonnes mortes | `pending_balance`, `in_transit_balance`, `settlement_balance` (présentes, jamais écrites par aucun code) |
| Calcul du solde | **Maintenu transactionnellement** par `WalletService` (écrit en même temps que l'écriture ledger, même transaction SQL) — pas dérivé à la lecture |
| Invariant actuel | `available_balance = balance − hold_balance` (testé) |
| Cycle hold | `createHold` → `captureHold`/`releaseHold`/expiration, verrous `FOR UPDATE`, idempotence par clé |

### 1.2 Ledger actuel

| Fait | Détail |
|---|---|
| Table | `ledger_entries` : `operation_id VARCHAR(36)`, `sequence`, `entry_type ENUM(debit,credit)`, `wallet_id NOT NULL`, `wallet_currency`, `environment`, `amount DECIMAL(20,8)`, `balance_after`, `reference_type`, `reference_id`, `metadata` |
| Double entrée | **Partielle** : `transfer()` écrit une vraie paire ; `credit()`/`debit()` écrivent **une seule** ligne |
| Écritures produites | debit (send : hold→capture englobant montant+frais), credit (refund), paire debit/credit (convert EUR→XAF) |
| Limite structurelle | Pas de colonne `account_code` → pas de plan de comptes ; `wallet_id NOT NULL` → aucun leg non-wallet possible ; frais non isolables |
| **Découverte clé** | **Les wallets de démo n'ont AUCUNE écriture ledger** (le seed insère `wallets` et `transactions`, jamais `ledger_entries`) : la majeure partie de l'existant n'a aucune représentation comptable |

### 1.3 Transactions

| Fait | Détail |
|---|---|
| Enum actuel | `('completed','processing','pending','failed','cancelled')` |
| Absents | `reversed`, `refunded`, `reconciliation_required`, `created`, `quoted`, `authorized` |
| Règlement | `ExecutionSettlementService` : processing → completed (statut seul) / failed (compensation refund), rejeu idempotent, transitions illégitimes refusées (409) |
| Mapping provider | `provider`, `provider_operation_id`, `provider_status` (pawaPay intégré, sandbox) |

### 1.4 Synthèse conservé / évolue / remplacé

| Élément | Conservé | Évolue | Remplacé |
|---|---|---|---|
| `wallets` (position user) | ✅ identité, granularité | colonnes buckets activées | — |
| `WalletService` | invariants, verrous, idempotence | écriture de la position via legs équilibrés | — |
| `ledger_entries` | table, `operation_id/sequence`, immutabilité | + `account_code`, `wallet_id` nullable, flag legacy | la convention d'écriture mono-ligne |
| `transactions` | table, mapping provider | enum étendu, `provider_account_id` | — |
| Frais bundlés | historique intact | legs séparés pour les nouvelles opérations | le calcul bundlé |

---

## 2. STRATÉGIE GÉNÉRALE

### 2.1 Options analysées

| Option | Description | Avantages | Inconvénients |
|---|---|---|---|
| **A — Migration directe** | Bascule immédiate ancien → nouveau modèle en une opération | Simple, un seul état | Aucun filet : le moindre défaut casse toute l'exécution ; pas de retour en arrière propre |
| **B — Phase hybride** | Couche de compatibilité : le vieux code continue d'écrire, un traducteur produit le nouveau format | Progressif | Le traducteur re-écrit des données (risque de divergence) ; deux formats vivants sans vérification croisée |
| **C — Double écriture temporaire** | Pendant une fenêtre, chaque nouvelle opération écrit **les deux** formats (ancien + nouveau GL) ; un job de vérification compare ; bascule ensuite | **Rollback possible, divergence détectée immédiatement, aucun silence** | Coût d'écriture doublé pendant la fenêtre ; complexité temporaire |

### 2.2 Recommandation : **Option C (double écriture), en pattern expand–contract**

```
Phase 1-2 :  EXPAND  — ajouts strictement additifs (tables, colonnes), code existant inchangé
Phase 3-4 :  SEED    — backfill account_code + postings d'ouverture (vérifiés, réversibles)
Phase 5-6 :  DUAL    — chaque opération écrit ancien format + legs GL ; job de vérification
Phase 7-8 :  CUTOVER — le GL devient l'unique écriture ; la vérification continue en permanence
Phase 9 :    CONTRACT — suppression de l'ancienne convention (jamais de l'historique)
```

**Justification** : le modèle cible change la *convention d'écriture* de toutes les opérations financières. Une bascule directe (A) rend toute erreur de posting irréversible en production. La double écriture (C) garantit que chaque nouvelle opération est **démontrablement identique** dans les deux formats (solde, montant, idempotence) avant de retirer l'ancien chemin — le critère de validation n°1 (« aucun solde ne change involontairement ») est vérifié en continu, pas au dernier moment.

---

## 3. MIGRATION DU LEDGER VERS UN VRAI GENERAL LEDGER

### 3.1 Changements de structure

| Colonne | Avant | Après | Rôle |
|---|---|---|---|
| `account_code` | — | `VARCHAR(30) NOT NULL` (après backfill), FK `chart_of_accounts.code` | nomme le compte GL de chaque leg |
| `wallet_id` | `NOT NULL` | **NULLABLE** | permet les legs non-wallet (`PROVIDER_ASSET`, `SUSPENSE`, `NEXUS_REVENUE`…) |
| `is_legacy` | — | `TINYINT(1) DEFAULT 0` | marque les lignes antérieures à la bascule (exclues des calculs GL, conservées pour l'audit) |
| `migrated_at` | — | `DATETIME NULL` | date de backfill (audit) |

Nouvelles tables : `chart_of_accounts` (définie dans le modèle cible §5), `provider_accounts`, `provider_balances`, `reconciliation_runs` (créées en phase EXPAND, vides).

### 3.2 Compatibilité historique — règle d'or

**Aucune ligne existante n'est modifiée, supprimée ou réécrite.** Les opérations d'avant bascule restent telles quelles (`is_legacy = 1`), y compris leurs écritures mono-ligne. La migration ne fait que :

1. **backfiller `account_code`** — dérivable sans ambiguïté : tout leg avec `wallet_id` → `USER_POSITION.{wallet_currency}`. Les paires convert existantes → également `USER_POSITION` des deux côtés (la conversion est une opération de position). Aucune conjecture.
2. **marquer `is_legacy = 1`** sur les lignes antérieures à la date de bascule.
3. **créer les postings d'ouverture** (§4) pour représenter les soldes courants.

**Ce que la migration ne fait PAS** : fabriquer des contreparties pour les écritures mono-ligne historiques (un ancien send debit n'a pas de leg credit dans la réalité — lui en inventer un serait falsifier l'histoire). À la place, la « queue legacy » (Σ des écritures mono-ligne) est **quantifiée, isolée et surveillée** : elle figure dans le rapport d'écart jusqu'à sa résolution (voir §7), jamais mélangée au GL courant.

### 3.3 Comptes cibles

`USER_POSITION` (legs wallet) · `PROVIDER_ASSET` · `PROVIDER_SETTLEMENT` · `PROVIDER_FEES` · `NEXUS_REVENUE` · `FX_TRANSIT` · `FX_GAIN_LOSS` · `SUSPENSE` · `REFUND` (reposting inverse, `reference_type='refund'`) · `CHARGEBACK` (idem) — conformément au modèle cible §4-5.

---

## 4. MIGRATION DES WALLETS EXISTANTS

### 4.1 Opération d'ouverture par wallet

Wallet existant : Utilisateur A, EUR, 1000 → à la date de bascule D, un **posting d'ouverture équilibré** :

```
operation_id : OPEN-{wallet_id}-{dateD}        (respecte la limite 36 chars)
date         : D (date de bascule, horodatée)
reference_type : 'opening_balance'
reference_id   : {wallet_id}
environment    : celui du wallet

Leg 1 : DEBIT  SUSPENSE.EUR           1000.00   (contrepartie non encore identifiée)
Leg 2 : CREDIT USER_POSITION.EUR      1000.00   (position utilisateur, balance_after = 1000.00)
```

- **Type d'opération** : `wallet_operations.type = 'welcome_bonus'` réutilisé **non** — nouvelle valeur `'opening_balance'` ajoutée à l'enum (documentée).
- **Audit** : entrée `audit_logs` (qui a lancé la migration, date, version du script, hash des soldes avant/après).
- **Environnement** : un posting d'ouverture par wallet et par environnement (sandbox et production traités séparément, jamais mélangés).

### 4.2 Pourquoi SUSPENSE et pas OPENING_BALANCE

Le compte `OPENING_BALANCE` (équity) ne sert que pour les fonds **dont la nature est connue** (ex. un compte d'exploitation que Nexus possède réellement). Pour des soldes de démo créés sans provider, la contrepartie est **inconnue par construction** → `SUSPENSE`, qui dit exactement la vérité : *« ces fonds n'ont pas encore de contrepartie externe identifiée »*. Les deux comptes existent ; le choix dépend de la nature du solde (cf. §5).

### 4.3 Non-double-comptage

Le posting d'ouverture représente le **solde courant à la date D**. Les écritures historiques antérieures (déjà dans `ledger_entries`, `is_legacy=1`) ne sont **pas** additionnées au GL : le GL courant démarre à D (ouverture) ; l'avant-D reste de l'archive consultable. Test 1 (§13) vérifie explicitement : `wallet.balance == projection GL courante` et `ancien historique intact`.

---

## 5. GESTION DES ANCIENS FONDS (sans provider réel)

| Option | Décision | Justification |
|---|---|---|
| 1 — `INTERNAL_MIGRATION_PROVIDER` | **Rejetée** | Créer un « provider interne » laisserait croire que des fonds sont détenus chez un partenaire qui n'existe pas — c'est exactement le mensonge que l'audit interdit (« aucune credential fictive présentée comme opérationnelle », « pas de création artificielle »). |
| 2 — `SUSPENSE_OPENING_BALANCE` | **Choisie** | Honnête : la contrepartie est inconnue, elle est provisionnée et visible dans le rapport d'écart. |
| 3 — Autre (`OPENING_BALANCE`) | Retenue en complément | Uniquement pour les fonds **réellement possédés** par Nexus (compte d'exploitation), jamais pour les positions clients sans backing. |

**Devenir des fonds suspense** : à mesure que des providers réels sont connectés et que les balances sont observées, la réconciliation peut **rattacher** ces fonds (attribution à un funding réel) ou les **ajuster** (écriture de sortie avec validation finance). Jusqu'à résolution, le rapport d'inventory affiche l'écart « positions non adossées » — c'est le comportement voulu, pas un bug.

---

## 6. INTRODUCTION DE `provider_accounts`

### 6.1 Structure (modèle cible §3.2, inchangée)

```
provider_accounts
  id, provider_slug, environment, external_account_id, currency,
  account_type ENUM('safeguarding','settlement','operating','pool'),
  status ENUM('active','paused','closed'), label, provider_credentials_id FK,
  created_at, updated_at
  UNIQUE (provider_slug, environment, currency)
```

### 6.2 Rattachement des positions

```
User Position (USER_POSITION.{devise}, legs wallets)
        │  legs PROVIDER_ASSET.{provider}.{devise}
        ▼
Provider Account (provider_accounts — notre compte chez le provider)
        │  external_account_id
        ▼
External Balance (provider_balances — observation du provider)
```

- **Nouvelles opérations** : chaque operation porte `provider_account_id` (via `transactions` et `wallet_operations`, colonnes ajoutées) ; les legs `PROVIDER_ASSET` référencent le compte dans `metadata`.
- **Anciennes opérations** : non rétro-attribuées (pas de conjecture). Elles restent dans la queue legacy jusqu'à ce qu'un rattachement soit justifiable.
- **Seul l'admin** crée/gèle/ferme un provider account ; `UNIQUE(provider_slug, environment, currency)` empêche deux comptes concurrents par devise.

---

## 7. MIGRATION DES BALANCES — NOUVELLE SOURCE DE VÉRITÉ

| Avant | Après |
|---|---|
| `wallet.balance` maintenu à la main (source unique) | `ledger` = source de vérité ; `wallet` = **projection** ; `provider_balances` = observation externe |

- **Qui calcule le wallet** : `WalletService` continue d'écrire la projection transactionnellement (performance), mais chaque écriture est désormais accompagnée de ses legs GL équilibrés dans la même transaction.
- **Quand recalculer** : (a) à chaque opération (écriture conjointe) ; (b) un job de vérification périodique recalcule la projection depuis le GL et compare ; (c) tout écart = alerte immédiate (bug, pas tolérance).
- **Comment vérifier** : invariant d'équilibre par `operation_id` (Σ debit = Σ credit par devise) + invariant `wallet == projection(ledger)` + invariant buckets.
- **Comment détecter un écart** : le job de vérification + la daily reconciliation (expected `PROVIDER_ASSET` vs reported `provider_balances`) produisent des items `reconciliation_items` — jamais de correctif automatique.

**Queue legacy** : le rapport d'inventory affiche en permanence « positions adossées », « positions suspense », « queue legacy », « écart provider » — les quatre lignes, y compris zéro, pour que la question « où est l'argent » ait toujours une réponse visible même quand la réponse est « pas encore adossé ».

---

## 8. MIGRATION DES ÉTATS FINANCIERS (BUCKETS)

Les colonnes existent ; la migration les **active** (aucun changement de schéma pour cette partie).

| Bucket | Signification | Entrée | Sortie | Écriture ledger | Événement provider |
|---|---|---|---|---|---|
| `available` | Utilisable immédiatement | settlement confirmé | hold / send | aucune en propre (projection) | — |
| `hold` | Réservé pour une opération en cours | hold | capture / release / expiration | aucune (réservation) | — |
| `in_transit` | Envoyé, accepté, non finalisé | exécution (ACCEPTED) | completed / failed | aucune jusqu'à completed | payout créé |
| `pending` | Reçu, non settled | webhook entrant | politique de disponibilité | `PROVIDER_ASSET` crédité | réception confirmée |
| `settlement` | En transit inter-comptes | transfert inter-provider | settlement | legs `PROVIDER_SETTLEMENT` | settlement bancaire |

**Invariant activé** : `balance == available + hold + pending + in_transit + settlement`, chaque unité dans exactement un bucket.

**Séquence de migration** : les wallets existants n'ont que `balance`/`available` (hold à zéro sauf opérations en vol) → au moment de la bascule, les soldes courants sont intégralement `available` (rien en vol), puis la machine à états §11 du modèle cible prend le relais. Les opérations en vol à la date D (transactions `processing`) sont **gelées avant bascule** : réglées (completed/failed) ou passées en `reconciliation_required` avant la bascule — jamais migrées « en vol ».

---

## 9. MIGRATION DES FRAIS

| | Avant bascule | Après bascule |
|---|---|---|
| Nouvelles opérations | — | legs séparés : `DEBIT USER_POSITION` (principal) + `DEBIT USER_POSITION` (fee) / `CREDIT NEXUS_REVENUE.fee` ; coût provider : `DEBIT PROVIDER_SETTLEMENT` / `CREDIT PROVIDER_FEES` (voir modèle cible §6.B/E) |
| Anciennes transactions | debit bundlé (montant + frais) conservé intact (`is_legacy`) | — |
| Historique des revenus | **Non réécrit** : les frais bundlés historiques ne sont pas rétro-splittés. Option (validée par la finance uniquement) : une écriture d'ajustement unique `NEXUS_REVENUE.adjustment` estimant le revenu historique, explicitement marquée, jamais présentée comme un détail facturé | — |

Règle : on ne réécrit jamais l'historique ; la revenue ouvre à zéro au GL, l'estimation éventuelle est une écriture d'ajustement signée.

---

## 10. MIGRATION DES TRANSACTIONS EXISTANTES

| Statut actuel | Traitement |
|---|---|
| `completed` | conservé tel quel (`is_legacy` au niveau ledger), source de vérité intacte ; rattaché à un provider account si justifiable |
| `failed` | idem |
| `cancelled` | idem |
| `pending` / `processing` en vol à D | **gelées avant bascule** : réglées (completed/failed via règlement existant) ou `reconciliation_required` — jamais migrées en vol |
| `refund` / `reversal` / `chargeback` | nouveaux statuts de l'enum (ajoutés par ALTER additif) ; uniquement pour les nouvelles opérations ; l'historique n'est pas reclassé |

**Enum** : ALTER additif `ENUM(...)+created,quoted,authorized,reversed,refunded,reconciliation_required` — MySQL permet d'étendre un enum sans réécrire les lignes (les anciennes valeurs restent valides). Aucun reclassement silencieux.

**Ne jamais modifier silencieusement l'historique** : toute écriture corrective (ajustement, rattachement, reclassement) est un **nouveau posting** référençant l'opération d'origine + entrée `audit_logs` + rôle finance requis.

---

## 11. COMPATIBILITÉ API

| API | Statut | Détail |
|---|---|---|
| Wallet GET | **inchangée (additive)** | le contrat existant reste ; ajout des champs `pending_balance`, `in_transit_balance`, `settlement_balance` (nouveaux, optionnels pour les clients stricts) |
| Transfer / Send | **inchangée** | le contrat de création ne change pas ; l'exécution interne passe par le GL |
| Convert | **inchangée** | idem |
| Quote | **inchangée** | — |
| Transactions GET | **additive** | nouveaux statuts documentés dans l'enum de réponse |
| Reconciliation | **modifiée (additive)** | réponses enrichies (`source`, `run_id`) ; aucun champ existant supprimé |
| Admin providers | **nouvelle** | CRUD `provider_accounts`, vue `provider_balances`, rapport d'inventory |
| Funding (dépôt) | **nouveau** | endpoint webhook/API de dépôt — n'existe pas aujourd'hui (gap P0) |
| Suspense | **nouveau** | endpoint de résolution (rôle finance uniquement) |

Règle : toute évolution API est **additive** pendant la fenêtre de migration (jamais de champ supprimé ni renommé avant la fin du CONTRACT). Versionnement : `Accept: application/vnd.nexus+json;version=2` si un changement cassant devient inévitable — à éviter pendant la fenêtre.

---

## 12. MIGRATION DES DONNÉES DE TEST/DEV (SEED)

Objectif : les données de démo reflètent le nouveau modèle.

1. **Montants** : déjà corrigés (audit P2-004 — plafonds KYC mensuels respectés, `amount_ref` EUR exact). Vérification conservée par `SeedKycLimitsTest`.
2. **Provider sandbox** : création d'un `provider_accounts` pawaPay sandbox (CMR/XAF) via le harness existant (`scripts/provider_sandbox`).
3. **Balances provider cohérentes** : `provider_balances` observées depuis le harness, cohérentes avec les postings `PROVIDER_ASSET` (la réconciliation de démo doit passer *matched*).
4. **Écritures comptables équilibrées** : le seed écrit désormais des postings complets (ouverture SUSPENSE/PROVIDER_ASSET + legs par opération) au lieu de simples `INSERT wallets` sans ledger.
5. **Invariant seed** : test automatisé « le seed ne place jamais un compte au-dessus de ses limites » (existant) étendu en « le seed produit un ledger équilibré » (Σ debit = Σ credit par devise, wallet == projection).

---

## 13. TESTS DE MIGRATION OBLIGATOIRES

| # | Test | Assertion |
|---|---|---|
| 1 | Migration d'un wallet 1000 EUR | après : `USER_POSITION = 1000`, posting d'ouverture équilibré, `wallet.balance` inchangé |
| 2 | Aucune création d'argent | `Σ wallets avant == Σ wallets après` (et `Σ USER_POSITION == Σ wallets`) |
| 3 | Aucune perte d'historique | chaque ligne `ledger_entries` antérieure existe encore, intacte, `is_legacy=1`, `account_code` backfillé |
| 4 | Invariant double entrée | pour tout `operation_id` post-bascule : `Σ debit == Σ credit` par devise (et zéro leg orphelin) |
| 5 | Provider mismatch détectable | `provider_balances` ≠ `PROVIDER_ASSET` → item `discrepancy` + `reconciliation_required`, aucun correctif |
| 6 | Reconciliation possible après migration | une run de daily reconciliation passe `matched` sur données seed ; une run sur données altérées passe `discrepancy` |
| 7 | Double écriture à l'identique | pendant DUAL : pour chaque opération, ancien format et legs GL produisent le même solde wallet (job de vérification = test continu) |
| 8 | Gel des opérations en vol | toute transaction `processing` à D est réglée ou `reconciliation_required` avant bascule — aucune n'est migrée en vol |
| 9 | Queue legacy quantifiée | le rapport d'inventory affiche la queue legacy et l'écart suspense ; zéro si rien |
| 10 | Rollback | rejouer le snapshot pré-migration restaure l'état exact (testé sur une copie, pas en prod) |

---

## 14. RISQUES DE MIGRATION

| # | Risque | Sévérité | Mitigation |
|---|---|---|---|
| R1 | Posting d'ouverture défectueux → double comptage (solde + ouverture) | **P0** | Test 1/2 avant tout déploiement ; ouverture = solde courant à D, jamais solde cumulé ; exécution en transaction unique par wallet |
| R2 | Opérations en vol migrées « en vol » → argent indéterminable | **P0** | Gel systématique avant bascule (§10) ; une bascule refuse de partir si une `processing` non réglée existe |
| R3 | Écart silencieux entre ancien format et GL pendant DUAL | **P0** | Job de vérification bloquant : tout écart stoppe la bascule (fail-closed) |
| R4 | ALTER enum / index sur grosse table `transactions` | P1 | Fenêtre de maintenance ; vérifier taille ; MySQL étend l'enum sans réécrire les lignes (vérifié en staging) |
| R5 | Divergence wallet / projection après bascule (bug de posting) | P1 | Invariant `wallet == projection(ledger)` vérifié à chaque opération + job périodique ; alerte immédiate |
| R6 | Backfill `account_code` erroné (conversions) | P1 | Backfill déterministe (wallet_currency) + revue des cas non triviaux ; jamais de déduction par défaut |
| R7 | API additive cassant des clients stricts (nouveaux champs/enum) | P2 | Annonce de version, champs optionnels pendant la fenêtre, test de compatibilité des contrats |
| R8 | Revenue historique estimée présentée comme facturée | P2 | L'ajustement est marqué `NEXUS_REVENUE.adjustment` + signé ; pas de détail facturé rétroactif |
| R9 | Suspense permanent non résolu → rapport d'écart éternel | P2 | Processus de rattachement/ajustement défini (§5) ; suivi en revue finance |
| R10 | Colonnes mortes et ancienne convention non nettoyées | P3 | Suppression en phase CONTRACT, après stabilisation et archivage |

---

## 15. PLAN D'EXÉCUTION RECOMMANDÉ

```
Phase 0 — Backup + snapshot
  Snapshot complet (données + schéma), test de restauration sur copie (Test 10).
  Sortie : restauration prouvée.

Phase 1 — EXPAND : nouvelles tables
  chart_of_accounts, provider_accounts, provider_balances, reconciliation_runs (vides).
  Colonnes additives : ledger_entries.account_code (nullable), is_legacy, migrated_at,
  wallet_id nullable ; transactions.enum étendu ; transactions/wallet_operations + provider_account_id.
  Aucun code métier modifié. Sortie : suite existante verte à l'identique (615 tests).

Phase 2 — Compatibilité
  Le code existant fonctionne sans changement (l'ancien chemin écrit toujours comme avant).
  Sortie : aucune régression observable.

Phase 3 — Backfill ledger
  account_code sur les lignes existantes (déterministe) + is_legacy=1.
  Sortie : Test 3 vert (aucune perte, backfill exact).

Phase 4 — Postings d'ouverture
  Un posting équilibré par wallet (SUSPENSE/OPENING_BALANCE selon la nature).
  Sortie : Tests 1, 2, 4 verts ; Σ wallets == Σ USER_POSITION ; rapport d'inventory cohérent.

Phase 5 — DUAL : double écriture
  Chaque nouvelle opération écrit l'ancien format ET les legs GL (même transaction).
  Sortie : Test 7 vert sur toute la suite E2E.

Phase 6 — Validation réconciliation
  Daily reconciliation sur les données seed (matched) + altération contrôlée (discrepancy).
  Sortie : Tests 5, 6 verts.

Phase 7 — CUTOVER : activation du nouveau moteur comptable
  Le GL devient l'unique écriture ; l'ancien format n'est plus produit.
  Sortie : suite complète verte sous le nouveau régime ; invariants actifs en continu.

Phase 8 — Provider accounts + funding
  Création des comptes réels sandbox, premier dépôt réel (webhook → posting → position).
  Sortie : un funding réel sandbox tracé de bout en bout.

Phase 9 — CONTRACT : nettoyage
  Archive de l'ancienne convention, suppression des colonnes de compatibilité devenues inutiles,
  revue des colonnes mortes (pending/in_transit/settlement désormais actives).
  Sortie : schéma conforme au modèle cible, documentation à jour.
```

---

## 16. CRITÈRES DE VALIDATION FINALE

La migration est réussie **uniquement** si, à la fin de la phase 9 :

1. **Aucun solde utilisateur n'a changé involontairement** — Test 2 vert en continu (avant/après/DUAL/CUTOVER).
2. **Chaque position utilisateur possède une représentation ledger** — un posting d'ouverture ou des legs GL par wallet ; zéro wallet « orphelin » (sans leg).
3. **Chaque écriture est équilibrée** — invariant `Σ debit == Σ credit` par devise, appliqué à toute opération post-bascule.
4. **Chaque opération peut être reconstruite** — legs reliés par `operation_id/sequence`, `reference_type/reference_id` vers l'objet métier, `metadata` portant la ventilation.
5. **Les fonds historiques sont identifiés** — queue legacy quantifiée et surveillée, fonds sans backing en `SUSPENSE`, jamais présentés comme adossés.
6. **Les futures opérations peuvent être reliées à un provider** — `provider_account_id` + legs `PROVIDER_ASSET` + `provider_balances` observées.
7. **La question de référence a une réponse** : *« pour chaque montant affiché dans Nexus, quelle écriture comptable le représente et quelle contrepartie externe le justifie ? »* — réponse déterministe via ledger → provider account → balance externe (et, pour le résidu, réponse explicite « non encore adossé, en suspense »).

**Règle finale** : à chaque phase, si un invariant échoue, on **s'arrête** (fail-closed) et on restaure le snapshot de la phase précédente. La migration n'avance jamais avec un écart non résolu.
