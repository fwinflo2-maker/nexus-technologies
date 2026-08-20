# NEXUS FINANCIAL MODEL — MODÈLE CIBLE PROVIDER / WALLET / COMPTABILITÉ

**Statut** : conception cible (pas de code). Base : audit du modèle portefeuille/provider (2026-08-17), schéma réel vérifié.
**Principe fondateur** : Nexus n'est pas une banque. Nexus orchestre des positions financières dont la contrepartie est détenue par des partenaires externes, tout en maintenant une représentation interne exacte, traçable, réconciliable et auditée.

> **Question de référence** : à n'importe quel instant, Nexus peut-il expliquer où se trouve chaque unité monétaire affichée dans un wallet utilisateur ? Si la réponse est non, le modèle n'est pas terminé.

---

## 1. VISION FINANCIÈRE NEXUS

### 1.1 Ce que Nexus possède / ne possède pas

| Élément | Détenteur | Explication |
|---|---|---|
| La plateforme, le ledger, le plan de comptes | Nexus | Le système d'orchestration et sa trace comptable. |
| Les relations contractuelles avec les providers | Nexus | Les comptes provider sont ouverts au nom de Nexus (ou de son entité régulée partenaire). |
| Les credentials API providers | Nexus | Chiffrés AES-256-GCM, jamais exposés au frontend. |
| **Les fonds des clients** | **Jamais Nexus sur son propre bilan** | Les fonds sont détenus chez des partenaires externes (EMI/PSP/banque partenaire) selon le modèle juridique retenu (safeguarding / client money / compte dédié). |
| Les wallets affichés | **Position interne Nexus** | Une créance de l'utilisateur sur Nexus, adossée à une contrepartie chez un provider. Pas un compte bancaire. |
| Nexus revenue / fees | Nexus | Comptabilisée dans le plan de comptes, séparée des positions clients. |

### 1.2 Vocabulaire non ambigu (à respecter partout)

| Terme | Définition stricte |
|---|---|
| **Wallet utilisateur** | Projection opérationnelle de la position d'un utilisateur (user × devise × environnement). Pas un compte bancaire. |
| **Position utilisateur** | Créance de l'utilisateur sur Nexus, représentée au ledger par le compte `USER_POSITION.{devise}` (détail = wallets). |
| **Provider account** | Compte de Nexus chez un provider (identifiant externe + devise + environnement). |
| **Compte de settlement** | Compte de passage entre positions provider (transit). |
| **Ledger Nexus** | Registre comptable en partie double, source de vérité des positions. |
| **Fonds externes** | Argent réellement détenu chez le partenaire. Nexus n'en a que la représentation comptable (`PROVIDER_ASSET`) et l'observation (`provider_balances`). |
| **Provider balance** | Observation externe du solde réel chez le provider. Jamais éditée à la main, jamais confondue avec `PROVIDER_ASSET`. |

### 1.3 Modèle retenu

**Modèle C + D** : le wallet Nexus est un **agrégateur de positions internes** dont chaque unité est **adossée à un provider account** précis. Selon le provider, l'adossement est direct (un compte par devise) ou mutualisé (pool), mais la **relation wallet → provider account → compte externe est toujours enregistrée** — c'est la différence avec l'existant, où elle n'existe nulle part.

---

## 2. ARCHITECTURE CIBLE (COUCHES)

```
Utilisateur
   │  créance
   ▼
Position utilisateur Nexus          ← compte USER_POSITION.{devise} au ledger (détail : wallets)
   │
   ▼
Ledger Nexus (partie double)        ← écritures équilibrées, source de vérité
   │
   ▼
Provider Account Nexus              ← provider_accounts (notre compte chez le provider)
   │
   ▼
Compte externe Provider             ← external_account_id chez l'EMI/PSP
   │
   ▼
Fonds réellement détenus            ← safeguarding / client money chez le partenaire
```

| Couche | Rôle | Source de vérité | Données conservées | Événements qui la modifient |
|---|---|---|---|---|
| Position utilisateur | Créance de l'utilisateur | Ledger | wallets (user × devise × env) | funding, send, receive, convert, refund, reversal |
| Ledger Nexus | Registre comptable | Lui-même (immutable) | ledger_entries (legs équilibrés) | toute opération financière |
| Provider account | Notre identité chez le provider | Nexus (créé par l'admin) | provider_accounts | ouverture, fermeture, changement de compte externe |
| Provider balance | Solde réel observé | **Provider** | provider_balances (snapshots) | API, webhook, relevé |
| Fonds externes | L'argent réel | Provider (sa propre comptabilité) | jamais chez Nexus | hors périmètre Nexus |

**Règle d'or** : `PROVIDER_ASSET` (ce que Nexus *pense* détenir) et `provider_balances` (ce que le provider *dit*) sont deux choses distinctes. La réconciliation compare les deux. Aucune écriture de `provider_balances` dans le ledger, et aucun snapshot ne modifie le ledger.

---

## 3. ENTITÉS FINANCIÈRES

### 3.1 User Position (= wallet)

Le wallet actuel reste la position utilisateur. **Aucune nouvelle table.** Granularité : `user × currency × environment` (inchangée).

- **Solde** : dérivé du ledger ; écrit transactionnellement en même temps que les écritures (même transaction SQL), plus un test d'invariant périodique `wallet == projection(ledger)`.
- **Statut** : les buckets (voir §10) déterminent la disponibilité.
- **Relation** : chaque wallet est lié à un ou plusieurs provider accounts via le ledger (chaque unité de solde a un contre-leg `PROVIDER_ASSET.{provider}.{devise}` rattachable).

### 3.2 Provider Account

Le modèle proposé dans le brief est **suffisant**, complété par deux champs :

```
provider_accounts
------------------
id                  BIGINT PK
provider_slug       VARCHAR(50)   -- 'pawapay', ...
environment         ENUM('sandbox','production')
external_account_id VARCHAR(190)  -- identifiant réel chez le provider (compte de safeguarding / wallet ID)
currency            VARCHAR(5)    -- devise du compte
account_type        ENUM('safeguarding','settlement','operating','pool')
status              ENUM('active','paused','closed')
label               VARCHAR(190)  -- nom lisible (ex. 'PawaPay CMR XAF — safeguarding')
provider_credentials_id BIGINT FK -- credentials liées (chiffrées)
created_at / updated_at
UNIQUE (provider_slug, environment, currency)
```

Justification : `account_type` distingue le rôle financier (safeguarding = fonds clients ; operating = compte propre Nexus pour les frais) ; `UNIQUE(provider_slug, environment, currency)` empêche deux comptes concurrents pour la même devise (un seul serait ambigu).

### 3.3 Provider Balance

```
provider_balances
-----------------
id                 BIGINT PK
provider_account_id BIGINT FK
available          DECIMAL(20,8)   -- solde disponible selon le provider
pending            DECIMAL(20,8)   -- en cours chez le provider
reserved           DECIMAL(20,8)   -- réservé par le provider
currency           VARCHAR(5)
observed_at        DATETIME
source             ENUM('api','webhook','statement')
method             VARCHAR(50)     -- endpoint / numéro de relevé
raw                LONGTEXT        -- réponse brute du provider
UNIQUE (provider_account_id, observed_at, source)
```

Historique d'observation, jamais édité à la main.

### 3.4 Settlement Account

Pas de table dédiée : les comptes de settlement sont des **provider accounts de type `settlement`** ou des **comptes du plan de comptes** (`PROVIDER_SETTLEMENT.{provider}.{devise}`) selon le niveau de détail requis.

- **Quand utilisés** : toute opération qui traverse un provider (payout, funding, transfert inter-provider) passe par le compte de settlement.
- **Qui les possède** : le compte de settlement chez le provider appartient à l'entité Nexus/partenaire ; sa représentation au ledger est `PROVIDER_SETTLEMENT`.
- **Évolution** : crédité à l'instruction, débité au settlement effectif ; doit être à zéro en régime permanent (sinon = argent en vol → `in_transit`).
- **Rapprochement** : inclus dans le daily reconciliation de son provider account.

### 3.5 Suspense Account

Les fonds inconnus ne sont **jamais attribués automatiquement**. Mécanisme :

1. Le provider annonce +100 EUR sans opération Nexus associée.
2. Le webhook/API est acquitté, mais la somme est **provisionnée** : posting `DEBIT PROVIDER_ASSET.{provider}.EUR 100 / CREDIT SUSPENSE.EUR 100`. L'asset reste équilibré, la somme n'est ni perdue ni attribuée.
3. Un item `reconciliation_items.status = 'unmatched'` (source `balance`/`statement`) est créé, visible en console finance.
4. **Résolution humaine** (rôle finance uniquement) :
   - attribuée à un utilisateur → `DEBIT SUSPENSE.EUR 100 / CREDIT USER_POSITION.EUR 100` ;
   - gain Nexus (fond sans réclamation) → `DEBIT SUSPENSE / CREDIT NEXUS_REVENUE.adjustment` ;
   - erreur provider → `DEBIT SUSPENSE / CREDIT PROVIDER_ASSET` (retour au provider).
5. Chaque résolution est journalisée (qui, quand, note) — c'est une décision comptable, jamais automatique.

---

## 4. MODÈLE DE COMPTABILITÉ INTERNE

### 4.1 Limites de l'existant (constatées, pas supposées)

| Limite | Preuve |
|---|---|
| Double entrée partielle | `LedgerService::credit()`/`debit()` écrivent **une seule** ligne ; seule `transfer()` écrit une paire. |
| Absence de contrepartie | Le send écrit 1 debit englobant montant+frais ; pas de leg revenue, pas de leg provider. |
| Absence de plan de comptes | Aucune notion de compte : `ledger_entries` n'a pas de colonne `account_code`. |
| `wallet_id` NOT NULL | Un leg provider/revenue ne peut pas être représenté (aucune ligne sans wallet). |
| Buckets morts | `pending/in_transit/settlement_balance` présents au schéma, jamais écrits par aucun code. |

### 4.2 Modèle cible — le ledger devient un vrai GL en partie double

1. **`ledger_entries` gagne `account_code`** (varchar, FK `chart_of_accounts.code`) : chaque leg nomme son compte.
2. **`wallet_id` devient NULLABLE** : un leg peut être `PROVIDER_ASSET`, `SUSPENSE`, `NEXUS_REVENUE`… sans wallet.
3. **Chaque opération poste ≥ 2 legs**, reliés par `operation_id` + `sequence` (structure existante conservée).
4. **Invariant d'équilibre (testé)** : pour chaque `operation_id`, **par devise**, `Σ(debit) == Σ(credit)` (bcmath, 8 décimales). Toute opération non équilibrée est refusée → impossibilité structurelle de créer ou détruire de la valeur.
5. `balance_after` reste renseigné sur les legs `USER_POSITION` (wallet) ; les comptes non-wallet n'ont pas de `balance_after` (solde calculé à la demande).
6. `metadata` porte la ventilation (montant principal / frais / FX), et `reference_type`/`reference_id` rattachent le posting à l'objet métier (transaction, quote, provider op).

**Conséquence** : la source de vérité de la position devient démontrable — chaque unité affichée a un chemin de legs jusqu'à un compte `PROVIDER_ASSET`, lui-même rapproché du provider.

### 4.3 Concepts comptables couverts (le vocabulaire du brief)

`USER_POSITION` (positions utilisateurs) · `PROVIDER_ASSET` (fonds chez provider) · `PROVIDER_SETTLEMENT` (transit) · `NEXUS_REVENUE` (fees) · `PROVIDER_FEES` (coût provider) · `FX_GAIN_LOSS` · `REFUND` (reposting inverse) · `CHARGEBACK` (reversal) · `SUSPENSE`. Tous existent au plan de comptes ci-dessous.

---

## 5. PLAN DE COMPTES NEXUS

Table `chart_of_accounts` : `code VARCHAR(30) PK`, `type ENUM('asset','liability','equity','revenue','expense','gain_loss')`, `name`, `environment` (NULL = tous), `active`.

| Code | Compte | Type | Pourquoi il existe |
|---|---|---|---|
| 1100.{provider}.{devise} | `PROVIDER_ASSET` | asset | Ce que Nexus pense détenir chez chaque provider, par devise. Rapproché du provider. |
| 1200.{provider}.{devise} | `PROVIDER_SETTLEMENT` | asset | Transit entre instruction et settlement (côté provider). Doit revenir à zéro. |
| 1300.{devise} | `SUSPENSE` | asset | Fonds inconnus provisionnés, en attente de résolution humaine. |
| 1400.{pair} | `FX_TRANSIT` | asset | Transit interne pour les conversions (équilibre par devise des deux jambes FX). Doit revenir à zéro. |
| 2100.{devise} | `USER_POSITION` | liability | Créances des utilisateurs (détail : wallets). |
| 3100 | `OPENING_BALANCE` | equity | Solde d'ouverture / bascule du modèle (audit trail). |
| 4100.fee | `NEXUS_REVENUE.fee` | revenue | Frais facturés aux utilisateurs. |
| 4101.adjustment | `NEXUS_REVENUE.adjustment` | revenue | Ajustements (fonds sans réclamation, corrections). |
| 4200.{pair} | `FX_REVENUE` | revenue | Spread FX Nexus. |
| 5100.{provider} | `PROVIDER_FEES` | expense | Frais prélevés par le provider. |
| 5200.{pair} | `FX_EXPENSE` | expense | Coût FX du provider. |
| 6100.{pair} | `FX_GAIN_LOSS` | gain_loss | Différence entre taux référence et taux appliqué. |

**Règle de comptabilisation des frais (obligatoire)** : un même montant n'est jamais mélangé dans un champ unique. Le debit utilisateur porte le **total**, le posting porte la **ventilation** en legs séparés (voir §6).

---

## 6. FLUX COMPTABLES OBLIGATOIRES

Convention d'écriture : chaque bloc = un posting équilibré par devise, relié par `operation_id`. Exemple numérique cohérent : envoi 100 EUR → XAF, fee Nexus 2 EUR, provider fee 1 EUR, taux 655 XAF/EUR.

### A. Dépôt / Funding (100 EUR via provider)

| Étape | Événement | Posting (devise EUR) |
|---|---|---|
| 1 | Provider confirme le dépôt (webhook/API) | `DEBIT PROVIDER_ASSET.pawapay.EUR 100` / `CREDIT USER_POSITION.EUR 100` |
| 2 | Wallet | balance +100 ; disponibilité selon politique (pending → available) |
| 3 | Rapprochement | `provider_balances` observe 100 ; daily recon : expected = reported |

Règle : le **webhook du provider** est l'événement déclencheur ; sans lui, aucune augmentation de position (aucun chemin de crédit direct).

### B. Envoi (100 EUR → XAF)

| Étape | Événement | Posting | Wallet |
|---|---|---|---|
| 1 | Quote | aucun (réserve de prix) | aucun |
| 2 | Hold (réservation) | aucun (réservation, pas un mouvement) | available −102, hold +102 |
| 3 | Exécution → provider ACCEPTED | aucun leg de position (la position ne bouge pas encore) | hold −102, **in_transit +102** |
| 4 | Provider COMPLETED | EUR : `DEBIT USER_POSITION.EUR 100` / `CREDIT PROVIDER_SETTLEMENT.pawapay.EUR 100` (principal) ; EUR : `DEBIT USER_POSITION.EUR 2` / `CREDIT NEXUS_REVENUE.fee.EUR 2` (fee Nexus) | in_transit −102 |
| 5 | Provider fee | EUR : `DEBIT PROVIDER_SETTLEMENT.pawapay.EUR 1` / `CREDIT PROVIDER_FEES.pawapay.EUR 1` | — |
| 6 | FX (net 99 EUR) | EUR : `DEBIT PROVIDER_SETTLEMENT.pawapay.EUR 99` / `CREDIT PROVIDER_SETTLEMENT.pawapay.XAF 64845` + `FX_GAIN_LOSS` pour l'écart éventuel | — |
| 7 | Payout | XAF : `DEBIT PROVIDER_SETTLEMENT.pawapay.XAF 64845` / `CREDIT PROVIDER_ASSET.pawapay.XAF 64845` | — |
| 8 | Settlement | `PROVIDER_SETTLEMENT` revient à zéro (EUR : +100 −1 −99 = 0 ; XAF : +64845 −64845 = 0) ; bénéficiaire reçoit 99 EUR équivalent (100 − 1 provider fee) | terminé |

**Changement comportemental assumé** : aujourd'hui le débit de position a lieu à l'exécution (le montant « disparaît » du wallet pendant le vol). Cible : le montant reste visible en `in_transit_balance` jusqu'à confirmation provider. Le test `ExecutionSettlementTest` évoluera en conséquence (solde inchangé, available −102, in_transit +102).

### C. Réception (100 EUR)

| Étape | Posting | Wallet |
|---|---|---|
| Provider confirme la réception | `DEBIT PROVIDER_ASSET.{provider}.EUR 100` / `CREDIT USER_POSITION.EUR 100` | pending +100 |
| Settlement / politique de disponibilité | (aucun leg) | pending −100, available +100 |

### D. Conversion EUR → XAF

Jamais `wallet EUR −= X, wallet XAF += Y` seul : chaque étape est postée et **équilibrée par devise**. Exemple : l'utilisateur donne 100 EUR, reçoit 65 400 XAF (taux de référence 655, spread Nexus 100 XAF).

| Étape | Posting |
|---|---|
| FX interne (même pool) | ① EUR : `DEBIT USER_POSITION.EUR 100` / `CREDIT FX_TRANSIT.{EURXAF}.EUR 100` ; ② `DEBIT FX_TRANSIT.{EURXAF}.EUR 100` / `CREDIT FX_TRANSIT.{EURXAF}.XAF 65500` (taux de référence) ; ③ XAF : `DEBIT FX_TRANSIT.{EURXAF}.XAF 65500` / `CREDIT USER_POSITION.XAF 65400` + `CREDIT FX_REVENUE.{EURXAF} 100` (spread) |
| FX via provider | EUR : `DEBIT USER_POSITION.EUR 100` / `CREDIT PROVIDER_ASSET.EUR 100` ; puis `DEBIT PROVIDER_ASSET.EUR 100` / `CREDIT PROVIDER_ASSET.XAF 65500` + `FX_GAIN_LOSS` (écart taux) ; puis `DEBIT PROVIDER_ASSET.XAF 65500` / `CREDIT USER_POSITION.XAF 65400` + `CREDIT FX_REVENUE 100` |

Équilibre par devise vérifiable à chaque étape (EUR : +100 −100 = 0 ; XAF : −65 500 + 65 400 + 100 = 0). Reconstruisible intégralement : `source amount + fees + FX effect = destination amount` (taux, source du taux, spread tracés dans `wallet_operations` — colonnes existantes `fx_rate`, `fx_source`).

### E. Frais Nexus — séparation obligatoire

Exemple (même modèle que §6.B) : l'utilisateur paie **102 EUR** (100 de principal + 2 de fee Nexus), le provider coûte 1 EUR, le bénéficiaire reçoit **99 EUR** équivalent (avant FX).

| Posting | Montant |
|---|---|
| `DEBIT USER_POSITION.EUR` (principal) | 100 |
| `DEBIT USER_POSITION.EUR` (fee Nexus) | 2 |
| `CREDIT PROVIDER_SETTLEMENT.EUR` (payout) | 100 |
| `CREDIT NEXUS_REVENUE.fee.EUR` | 2 |
| `DEBIT PROVIDER_SETTLEMENT.EUR` / `CREDIT PROVIDER_FEES.{provider}.EUR` (coût provider) | 1 |
| `CREDIT PROVIDER_SETTLEMENT.EUR` (net bénéficiaire) | 99 |

Vérification : `USER_POSITION` −102 ; `SETTLEMENT.EUR` : +100 −1 −99 = 0 ; `NEXUS_REVENUE` +2 ; `PROVIDER_FEES` +1. Tout est isolable : montant utilisateur / provider fee / Nexus fee / net. (Le fractionnement exact — fee prélevée sur le principal ou facturée en plus — est une politique configurable, pas un choix de schéma.)

### F. Refund

| Cas | Posting | Wallet |
|---|---|---|
| Échec provider avant settlement | aucun leg (la position n'avait pas bougé — modèle cible) | in_transit → available |
| Refund après completed | `DEBIT PROVIDER_ASSET.{provider}.EUR` / `CREDIT USER_POSITION.EUR` ; fee remboursée → `DEBIT NEXUS_REVENUE.fee` / `CREDIT USER_POSITION` ; provider fee récupérée → `DEBIT PROVIDER_FEES` / `CREDIT PROVIDER_ASSET` | available +X |

Le refund est un **nouveau posting référençant l'opération d'origine** (`reference_type='refund'`, `reference_id=operation_id`) — jamais une modification de l'écriture d'origine (ledger immutable).

### G. Reversal / Chargeback

`completed` n'est pas définitif pour toujours. Nouveaux statuts : `reversed`, `refunded`.

| Cas | Posting | Note |
|---|---|---|
| completed → reversed | `DEBIT PROVIDER_ASSET` / `CREDIT USER_POSITION` (inverse du posting d'origine) ; `wallet_operations.status = 'reversed'` | Le statut `reversed` existe déjà au schéma mais aucun code ne l'écrit — à activer. |
| completed → chargeback | idem + item `reconciliation_items` (source `balance`) + frais de chargeback éventuels en `NEXUS_REVENUE.fee`/`PROVIDER_FEES` | Le ledger conserve l'historique complet des deux sens. |

---

## 7. MULTI-PROVIDER ROUTING

Cas : fonds source chez Provider A (EUR), payout chez Provider B (XAF).

```
Provider A position (EUR)
      ↓ funding
USER_POSITION.EUR
      ↓ instruction
PROVIDER_SETTLEMENT.A.EUR
      ↓ transfert inter-provider (settlement)
PROVIDER_SETTLEMENT.B.EUR  →  FX  →  PROVIDER_SETTLEMENT.B.XAF
      ↓ payout
PROVIDER_ASSET.B.XAF  →  bénéficiaire
```

Postings (chaque centime reste dans un compte identifié) :

| Étape | Posting |
|---|---|
| Funding depuis A | `DEBIT PROVIDER_ASSET.A.EUR` / `CREDIT USER_POSITION.EUR` |
| Instruction payout B | EUR : `DEBIT USER_POSITION.EUR` / `CREDIT PROVIDER_SETTLEMENT.A.EUR` ; + fee Nexus séparée |
| Transfert A → B | `DEBIT PROVIDER_SETTLEMENT.A.EUR` / `CREDIT PROVIDER_SETTLEMENT.B.EUR` (représente le settlement bancaire réel entre les comptes) |
| FX | `DEBIT PROVIDER_SETTLEMENT.B.EUR` / `CREDIT PROVIDER_SETTLEMENT.B.XAF` + `FX_GAIN_LOSS` |
| Payout | `DEBIT PROVIDER_SETTLEMENT.B.XAF` / `CREDIT PROVIDER_ASSET.B.XAF` |

**Tracabilité** : `transactions` gagne `source_provider_account_id` et `provider_account_id` (le rail de payout), en plus du `provider`/`provider_operation_id` existants. La colonne `provider` reste le rail effectif ; `transactions.provider` actuel + `provider_operation_id` suffisent à isoler chaque rail par transaction, les comptes précis sont portés par les legs.

---

## 8. SOURCE DE VÉRITÉ

| Donnée | Source de vérité | Règle |
|---|---|---|
| Position utilisateur | **Ledger Nexus** (`USER_POSITION`) | wallet = projection, vérifiée par test d'invariant |
| Wallet affiché | Ledger (dérivé) | jamais une source concurrente ; tout écart wallet/ledger est un bug |
| Solde provider | **Provider** | `provider_balances` = observation ; jamais éditée à la main |
| `PROVIDER_ASSET` (Nexus attendu) | Ledger Nexus | rapproché du provider, jamais fusionné avec lui |
| Statut transaction | **Règle documentée** (mapping provider→Nexus) + provider brut conservé | `provider_status` brut + `status` Nexus dérivé ; aucun statut forcé |
| Settlement | Provider + réconciliation | le settlement fait foi une fois rapproché |
| FX final | Quote validée / taux provider | `fx_rate` + `fx_source` sur wallet_operations |
| Frais Nexus | Ledger Nexus (`NEXUS_REVENUE`) | legs séparés, jamais bundlés |
| Frais provider | Provider (observé) | `PROVIDER_FEES` crédité depuis les postings |

**Règle de priorité si deux sources peuvent faire foi** : le **ledger** prime pour toute position interne ; le **provider** prime pour toute réalité externe ; la **réconciliation** est le seul mécanisme qui les confronte (elle ne corrige jamais un montant automatiquement — statut seulement, et sous condition d'absence d'écart).

---

## 9. RÉCONCILIATION

### 9.1 Un seul processus unifié (fusion des deux mécanismes actuels)

Aujourd'hui deux mécanismes disjoints : polling transactionnel (`ProviderReconciliationService`) et relevé manuel (`ReconciliationController` écrivant `reconciliation_items`). Cible : **un seul pipeline** alimenté par trois sources, écrivant tous dans `reconciliation_items` :

| Source | Déclencheur | Contenu |
|---|---|---|
| `polling` | Job périodique (transactions `processing` âgées) | statut + montant provider vs Nexus (déjà implémenté, correctif écart appliqué) |
| `webhook` | Réception webhook | événement provider |
| `balance` / `statement` | Daily reconciliation / relevé fourni | solde ou ligne de relevé |

Statuts d'item : `pending` → `matched` / `unmatched` / `discrepancy` → `resolved` (le statut `unmatched` = suspense, voir §3.5).

### 9.2 Daily reconciliation (par provider account)

Formule appliquée par `reconciliation_runs` :

```
Opening balance (fin de période précédente)
+ inflows  (provider_balances / postings PROVIDER_ASSET crédités)
− outflows (postings PROVIDER_ASSET débités)
± adjustments (résolutions de suspense)
= Closing balance attendu (Nexus)
vs Closing balance rapporté (provider_balances — API ou relevé)
```

- Écart = 0 → run `matched`, clôture de période.
- Écart ≠ 0 → run `discrepancy` + items + alerte (console finance + notification), **aucun correctif automatique**.
- Nouveauté à ajouter : **comparaison de soldes** (aujourd'hui le polling compare transaction par transaction ; `getBalance()` de l'adaptateur existe mais n'est jamais exploité).

### 9.3 Fréquence et périmètre

- Polling transactionnel : toutes les N minutes (configurable), transactions `processing` > 120 s.
- Daily reconciliation : 1×/jour/provider account/env, heure configurable.
- Périmètre strict : par `provider_account`, `environment`, `devise` — jamais de mélange sandbox/production (déjà garanti partout).

---

## 10. ÉTATS FINANCIERS (BUCKETS WALLET)

Les colonnes existent au schéma ; elles sont **activées** par le modèle cible.

| Bucket | Signification | Entrée | Sortie | Utilisable | Ledger |
|---|---|---|---|---|---|
| `available_balance` | Argent disponible immédiatement | settlement confirmé | hold, send | oui | aucune écriture en propre (projection) |
| `hold_balance` | Réservé pour une opération en cours | hold | capture, release, expiration | non | aucun leg (réservation) |
| `pending_balance` | Reçu du provider, non encore settled | webhook entrant | politique de disponibilité | non (affiché) | `PROVIDER_ASSET` crédité |
| `in_transit_balance` | Envoyé, confirmé par le provider comme accepté, non finalisé | exécution (ACCEPTED) | completed / failed | non | aucun leg jusqu'à completed |
| `settlement_balance` | En attente de settlement inter-comptes | transfert inter-provider | settlement | non | legs `PROVIDER_SETTLEMENT` |

**Invariant (testé à chaque mouvement)** :

```
balance == available + hold + pending + in_transit + settlement
```

et chaque unité est dans **exactement un** bucket. Un même argent n'est jamais compté deux fois : les buckets sont des répartitions de `balance`, pas des ajouts.

---

## 11. MACHINE À ÉTATS FINANCIÈRE (TRANSACTION)

État actuel de l'enum : `(completed, processing, pending, failed, cancelled)`. **Cible** :

```
created → quoted → authorized → processing → completed
                                    ↘ failed → refunded (si débit déjà posté)
                                    ↘ reconciliation_required (écart, décision humaine)
completed → reversed → refunded
completed → chargeback (via reconciliation)
```

| Transition | Déclencheur | Écriture | Wallet | Provider |
|---|---|---|---|---|
| created → quoted | quote obtenue | aucun | aucun | quote |
| quoted → authorized | hold | aucun (réservation) | available → hold | — |
| authorized → processing | provider ACCEPTED | aucun leg de position | hold → in_transit | payout créé (`provider_operation_id`) |
| processing → completed | webhook/polling COMPLETED | legs de position + fees (voir §6.B) | in_transit → 0 | completed |
| processing → failed | webhook/polling FAILED | aucun (position jamais débitée) | in_transit → available | failed |
| failed → refunded | remboursement émis | legs refund si débit déjà posté | available +X | refund |
| any → reconciliation_required | écart montant/devise/solde | **aucun** | inchangé | observé |
| completed → reversed | provider REVERSED / chargeback | postings inverses (voir §6.G) | available +X | reversed |

**Règles** : aucune transition illégitime acceptée (mécanisme `ExecutionSettlementService` existant étendu aux nouveaux statuts) ; un statut terminal ne se rejoue pas ; `reconciliation_required` n'est quitté que par décision humaine.

---

## 12. MODIFICATIONS BASE DE DONNÉES

### 12.1 Nouvelles tables (chacune justifiée financièrement)

| Table | Justification |
|---|---|
| `chart_of_accounts` | Le plan de comptes (§5) — sans lui, pas de GL en partie double nommable. |
| `provider_accounts` | Répond à la question « où est la contrepartie » (§3.2). Table absente = gap P0 de l'audit. |
| `provider_balances` | Observation externe du solde réel, distincte du ledger (§3.3). |
| `reconciliation_runs` | Traçabilité du daily reconciliation (opening/closing/écart par période). |

### 12.2 Modifications de tables existantes

| Table | Modification | Pourquoi |
|---|---|---|
| `ledger_entries` | + `account_code VARCHAR(30) NOT NULL` (FK chart_of_accounts) ; `wallet_id` → NULLABLE | nommer chaque leg ; permettre les legs non-wallet (provider, revenue, suspense) |
| `transactions` | enum `status` étendu : `created, quoted, authorized, processing, completed, failed, reversed, refunded, reconciliation_required, cancelled` ; + `provider_account_id` FK nullable ; + `source_provider_account_id` FK nullable (multi-provider) | machine à états complète ; adossement aux comptes |
| `wallet_operations` | + `provider_account_id` FK nullable ; statut `reversed` déjà présent (à activer) | rattacher chaque opération au compte provider |
| `reconciliation_items` | + `run_id` FK nullable ; + `source ENUM('polling','webhook','balance','statement')` ; + `operation_id` nullable (lien posting suspense) | unifier les mécanismes, relier au ledger |
| `quotes` | inchangée (a déjà `destination`, `operator`, `environment`, `expires_at`) | — |

### 12.3 Index, contraintes, clés étrangères

- `provider_accounts.UNIQUE(provider_slug, environment, currency)` — un compte par devise, pas d'ambiguïté.
- `provider_balances.UNIQUE(provider_account_id, observed_at, source)` — pas de double observation.
- `ledger_entries.INDEX(account_code)` ; contrainte applicative (pas SQL) : équilibre par devise par `operation_id` (le SQL ne peut pas exprimer l'équilibre multi-devise proprement — testé en service).
- `reconciliation_runs.UNIQUE(provider_account_id, period_start)` — une période par compte.
- `transactions.FK(provider_account_id)` → `provider_accounts(id)`.
- Toutes les nouvelles tables portent `environment` (sauf `chart_of_accounts`, globale) et suivent la convention d'isolation existante.

### 12.4 Ce qu'on ne crée PAS

- **Pas de table `user_positions`** : les wallets existants sont la position utilisateur.
- **Pas de table `settlement_accounts`** : le settlement est un type de `provider_account` + des comptes `PROVIDER_SETTLEMENT` au plan.
- **Pas de table `fees`** : les frais sont des legs `NEXUS_REVENUE`/`PROVIDER_FEES` au ledger.

---

## 13. GARANTIES CONSERVÉES (NON NÉGOCIABLES)

Le modèle cible **conserve et renforce** :

| Garantie | Comment |
|---|---|
| Idempotence | clés d'idempotence par opération (hold/capture/posting/refund) ; un webhook dupliqué = un posting |
| bcmath / DECIMAL | tous les calculs en bcmath ; `DECIMAL(20,8)` ledger, `DECIMAL(20,2)` wallet (inchangé) |
| Verrouillage `FOR UPDATE` | conservé sur wallets et étendu aux comptes du plan (rows `chart_of_accounts` verrouillées pendant un posting) |
| Isolation multi-tenant + environnement | jointe à chaque requête ; nouvelles tables portent `environment` |
| Audit logs | chaque posting immutable ; `reference_type/reference_id` relient l'écriture à l'objet métier |
| Rollback transactionnel | chaque posting est une transaction SQL complète (tous les legs + effets wallet atomiques) |
| RBAC deny-by-default | les résolutions de suspense / réconciliation sont limitées aux rôles finance ; aucun correctif automatique |
| Pas de création artificielle de valeur | l'invariant d'équilibre par devise rend une écriture non équilibrée impossible |

---

## 14. TESTS FINANCIERS À DÉFINIR (avant implémentation)

Chaque test est une spécification exécutable. Suite cible (en plus des 615 existants) :

| # | Scénario | Assertion clé |
|---|---|---|
| T1 | Funding : provider confirme 100 EUR | `DEBIT PROVIDER_ASSET 100 / CREDIT USER_POSITION 100` ; wallet +100 ; posting équilibré |
| T2 | Send : position −100 | legs USER_POSITION −100 + PROVIDER_SETTLEMENT +100 + NEXUS_REVENUE +2 ; équilibre par devise |
| T3 | Fees séparées | 1 leg `NEXUS_REVENUE` et 1 leg `PROVIDER_FEES` distincts — jamais bundlés |
| T4 | EUR→XAF réconciliable | source + frais + FX effect = destination ; `fx_rate`/`fx_source` tracés |
| T5 | Multi-provider A→B | legs `PROVIDER_ASSET.A` et `PROVIDER_ASSET.B` identifiables ; settlement revient à zéro |
| T6 | Fonds inconnus | +100 provider sans opération → `SUSPENSE` crédité, item `unmatched`, **aucune attribution** |
| T7 | Mismatch solde | daily recon : expected ≠ reported → run `discrepancy`, transaction `reconciliation_required`, aucun correctif |
| T8 | Webhook dupliqué | 1 seul posting (déjà couvert par ExecutionSettlementTest, étendu au nouveau modèle) |
| T9 | Refund | posting inverse référençant l'opération d'origine ; wallet restauré |
| T10 | Reversal | completed → reversed → postings inverses ; ledger conservé intact |
| T11 | Invariant d'équilibre | pour tout `operation_id` : `Σ(debit) == Σ(credit)` par devise (fuzzing sur opérations aléatoires) |
| T12 | Invariant buckets | `balance == available + hold + pending + in_transit + settlement` après chaque mouvement |
| T13 | Invariant wallet = ledger | projection(ledger) == wallet après chaque opération (test d'intégrité périodique) |
| T14 | Isolation | un posting sandbox ne peut jamais référencer un provider account production (et inversement) |

---

## 15. RISQUES, QUESTIONS OUVERTES, PLAN D'IMPLÉMENTATION

### 15.1 Risques

| # | Risque | Sévérité | Mitigation |
|---|---|---|---|
| R1 | Choix du modèle juridique non tranché (safeguarding vs agent vs compte dédié) — change la sémantique de `PROVIDER_ASSET` | **P0** | Trancher AVANT l'implémentation ; le plan de comptes est conçu pour les deux, mais la convention d'écriture en dépend |
| R2 | Migration des transactions historiques (rejouer les postings ? bascule à date ?) | P1 | Bascule à date + compte `OPENING_BALANCE` + audit trail ; pas de rejeu rétroactif |
| R3 | Changement comportemental du send (débit à l'exécution → `in_transit`) | P1 | Validation produit explicite ; tests mis à jour en même temps que la refonte |
| R4 | Disponibilité des fonds entrants (pending → available) : politique, pas code | P1 | Configurable par provider/type ; défaut conservateur (settlement requis) |
| R5 | Multi-devises : l'équilibre par devise impose de scinder les postings FX | P2 | Règle documentée + tests T4/T5 |
| R6 | Deux mécanismes de réconciliation à fusionner sans perdre la traçabilité existante | P2 | Migration des items existants vers le format unifié (ajout de `source`) |

### 15.2 Questions ouvertes (à trancher avant code)

1. **Modèle juridique** : safeguarding (fonds clients sur compte ségrégué) ou modèle agent ? Détermine si `PROVIDER_ASSET` est un actif de Nexus ou un actif client sous contrôle.
2. **Granularité des comptes provider** : un compte par devise suffit-il, ou faut-il des comptes par corridor (pays × méthode) ?
3. **Disponibilité des dépôts** : immédiate (sandbox) vs J+1 (production) ?
4. **Fee model** : fee Nexus prélevée sur le principal ou facturée en plus ? (Configurable — le schéma supporte les deux.)
5. **FX** : le spread Nexus est-il une décision de pricing (QuoteEngine) ou un compte dédié `FX_REVENUE` ? (Les deux : pricing côté quote, comptabilisation côté ledger.)
6. **Réconciliation des soldes** : le provider expose-t-il un endpoint balance fiable ? (pawaPay : oui — à confirmer en réel pour chaque nouveau provider.)

### 15.3 Plan d'implémentation (ordre recommandé)

| Phase | Contenu | Sortie vérifiable |
|---|---|---|
| **P0 — Fondations comptables** | `chart_of_accounts` ; `ledger_entries.account_code` + `wallet_id` nullable ; double entrée sur les flux existants (send, convert, refund) ; invariant d'équilibre ; tests T3/T11/T13 | Suite existante verte (mise à jour assumée des tests de solde) + nouveaux tests d'équilibre |
| **P1 — Provider accounts** | `provider_accounts` + `provider_balances` ; chemin de funding réel ; tests T1/T14 | Un dépôt réel sandbox → position +100 tracée |
| **P2 — Buckets wallet** | Activation `pending/in_transit/settlement` ; refonte de la saga (Modèle Y) ; tests T2/T12 | Q1 répondable : chaque unité affichée a un bucket et un leg |
| **P3 — Réconciliation unifiée** | `reconciliation_runs` + items unifiés + daily job + comparaison de soldes + suspense ; tests T6/T7 | Daily reconciliation démontrée avec écart détecté |
| **P4 — Reversal/chargeback** | machine à états complète ; tests T9/T10 | completed → reversed → refunded cohérent |
| **P5 — Multi-provider** | `source_provider_account_id` ; postings inter-provider ; tests T5 | Provider A → B tracé centime par centime |

Chaque phase conserve : **615 tests verts (aujourd'hui) — 0 régression, aucun correctif automatique d'argent, idempotence et isolation intactes.**

---

## 16. CONCLUSION — CRITÈRE D'ACHÈVEMENT

Le modèle est terminé quand, pour un wallet affichant 1 000 EUR, Nexus peut répondre **de manière déterministe** :

1. **Quelle position** : compte `USER_POSITION.EUR` + wallet (projection vérifiée).
2. **Quel provider** : provider account(s) via les legs `PROVIDER_ASSET` (et `provider_accounts`).
3. **Quel compte externe** : `external_account_id` chez le partenaire.
4. **Quelle écriture** : les legs `operation_id` correspondants, immutables.
5. **Preuve quotidienne** : daily reconciliation `opening + in − out ± adj = closing` vs `provider_balances`, écart = `reconciliation_required` (jamais corrigé automatiquement).

Tant que l'un de ces cinq points est silencieux, le modèle financier n'est pas terminé — et le code ne doit pas le prétendre.
