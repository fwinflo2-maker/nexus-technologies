# AUDIT — MODÈLE DE PORTEFEUILLE, COMPTABILITÉ ET RELATION PROVIDERS

**Date** : 2026-08-17 — **Base** : `nexus_test` (schéma migrations ≡ full_schema), suite complète 615 tests / 2545 assertions verte.
**Méthode** : vérification dans le code ET dans la base réelle, pas à la lecture. Aucune supposition.

---

## 0. Verdict en une phrase

> **Nexus modélise les wallets comme des positions INTERNES (créances sur Nexus), pas comme des fonds détenus, et le ledger est un journal de traçabilité à écriture unique, pas une comptabilité en partie double complète. Il n'existe aucune table reliant une position Nexus à un compte externe chez un provider, aucun chemin de dépôt en production, et aucun plan de comptes. La relation « 1 000 EUR affichés → où est la contrepartie ? » ne peut pas être répondu de manière déterministe aujourd'hui.**

Le code est propre, sûr (bcmath, verrous, idempotence, ownership) — mais le **modèle comptable provider n'existe pas encore**. Ce qui existe est un excellent *squelette* : la notion est claire dans les commentaires, le schéma a déjà les colonnes (pending/in_transit/settlement, fee_amount, fx_*), mais **rien ne les alimente**.

---

## A. Architecture financière réelle

### A.1 Le flux réellement implémenté

```
Utilisateur
   ↓  (aucun moyen de paiement réel — pas de funding endpoint)
Nexus wallet (position interne, créée par le seed de dev uniquement)
   ↓  send : hold → capture (débit unique montant+frais) → provider
PawaPayAdapter (seul rail réellement intégré, sandbox)
   ↓  ACCEPTED → 'processing' ; webhook COMPLETED/FAILED → règlement
Nexus ledger (écriture unique debit ; refund = écriture unique credit)
   ↓
Provider = pawaPay  (sans AUCUNE table « provider account / balance »)
```

### A.2 Qui détient quoi

| Étape | Propriétaire juridique | Détenteur technique | Trace dans Nexus |
|---|---|---|---|
| 1 000 EUR affichés | Utilisateur (créance sur Nexus) | ? (jamais enregistré) | `wallets.balance` = 1 000.00 |
| Provider / EMI | Provider | Provider | `provider_credentials` (API keys seulement) |
| Compte de safeguarding | ? | ? | **aucune table** |
| Ledger | Nexus | MySQL | `ledger_entries` (écritures) |

**Constat central** : `wallets.balance` est une **position interne** — mais rien dans le schéma ne dit où se trouve la contrepartie. Le modèle juridique n'est pas non plus documenté (nulle part dans le code : pas de mention safeguarding / settlement account / EMI partner).

### A.3 Modèle A/B/C/D — réponse

**Modèle C incomplet** : les wallets Nexus ne sont pas des sous-comptes chez le provider (pas de Modèle B — pas de champ « account id externe » sur wallets). Ils ressemblent à un **agrégateur de positions internes** (Modèle C), mais **sans agrégation réelle** : aucune table ne mappe wallet → provider account → external account. Donc aujourd'hui c'est un **Modèle C non réalisé** : des positions internes sans rattachement externe.

---

## B. Modèle comptable réellement implémenté

### B.1 Le ledger

`LedgerService` (vérifié ligne par ligne) :

- `transfer()` → **vraie paire debit+credit** (double entrée, `balance_after`, liée par `operation_id`). Utilisé par la conversion EUR→XAF.
- `debit()` / `credit()` → **UNE SEULE écriture** (pas de contrepartie). C'est le chemin réel de toutes les opérations de production : send (debit), refund (credit).
- **Conclusion** : le ledger est un **journal de traçabilité**, pas une comptabilité en partie double. Chaque mouvement utilisateur n'a **pas** de contre-écriture identifiable (pas de « fonds safeguarded », pas de « revenue fee », pas de « settlement account »).

### B.2 Les écritures réellement générées

| Opération | Écriture réelle | Contre-écriture | Compte de frais | Revenue |
|---|---|---|---|---|
| Send 100 EUR + frais 2 | debit 102 unique (hold→capture) | **aucune** | **aucune** (bundled dans le debit) | **aucun** |
| Conversion EUR→XAF | debit EUR + credit XAF (paire) | paire complète | non (fx_rate/fx_source tracés sur wallet_operations) | aucun |
| Refund (échec provider) | credit 102 (type `refund`) | **aucune** | — | — |
| Funding | **n'existe pas en production** | — | — | — |

### B.3 Frais

`wallet_operations.fee_amount` / `fee_currency` **existent** mais la saga send n'écrit **aucune entrée de frais séparée** : le debit englobe montant + frais. Le test `test_fees_are_bundled_into_a_single_debit_not_separated` prouve : 1 écriture debit de 102.00 pour un send de 100 + frais 2. **Impossible de distinguer provider fee / Nexus fee / revenue.**

### B.4 FX

La conversion est tracée intégralement sur `wallet_operations` (`source_currency`, `source_amount`, `dest_currency`, `dest_amount`, `fx_rate`, `fx_source`) et produit une paire debit/credit au ledger. **Mais** : pas de compte FX gain/loss, pas de spread, pas de provider FX. Le taux est `fx_manual` (démo) — jamais un taux provider.

### B.5 États des soldes

`wallets` a 6 colonnes de solde ; **seules 3 sont maintenues** par WalletService (`balance`, `available_balance`, `hold_balance`). `pending_balance`, `in_transit_balance`, `settlement_balance` sont **présentes dans le schéma mais jamais écrites par aucun code** (vérifié : aucun INSERT/UPDATE ne les touche). Le cycle hold est réel : hold → capture/release → expiration avec verrous FOR UPDATE.

---

## C. Mapping base de données (source de vérité)

| Table | Colonne | Rôle financier | Source de vérité | Provider associé |
|---|---|---|---|---|
| `wallets` | balance | Position interne brute | Ledger (dérivé) | aucun |
| `wallets` | available_balance | Disponible (balance − hold) | Ledger | aucun |
| `wallets` | pending/in_transit/settlement | **mortes** (jamais écrites) | — | — |
| `wallet_operations` | id, type, status, source/dest, fee, fx_* | Journal opérationnel | Nexus (unique) | non (pas de provider op id ici) |
| `ledger_entries` | entry_type, amount, balance_after, reference_type | Écritures | **Nexus (source de vérité de la position)** | non |
| `transactions` | provider, provider_operation_id, provider_status | Trace provider | Nexus + provider | **oui** (pawapay) |
| `provider_credentials` | encrypted credentials | Auth API | Provider | oui — mais **pas une position** |
| `reconciliation_items` | expected/actual/status | Rapprochement manuel (relevé fourni par l'utilisateur) | Provider statement | oui |

**Absents du schéma** (vérifié par `SHOW TABLES` + recherche de code) :
- `provider_accounts` / `provider_balances` — **aucune table de position provider**.
- chart of accounts — aucun compte (fees, revenue, safeguarding, FX P&L, suspense).
- aucun champ `provider_account_id` / `external_account_id` sur `wallets` ou `transactions`.

---

## D. Réconciliation — deux mécanismes distincts (et non reliés)

1. **`ProviderReconciliationService` (polling automatique)** — interroge pawaPay pour les transactions `processing` âgées. Détecte : completé/failed (règle), transaction sans trace provider (`missing_at_provider`), écart montant/devise (`discrepancies`). **Ne touche PAS `reconciliation_items`** (table réservée au mécanisme 2).
2. **`ReconciliationController` (relevé manuel)** — l'utilisateur/finance fournit un relevé (référence + montant), comparé à `dest_amount`. Écrit dans `reconciliation_items` (matched/discrepancy/pending/resolved).

**Correctifs appliqués pendant cet audit (défauts réels trouvés et corrigés)** :
- **Écart montant ≠ blocage du règlement** : la réconciliation automatique, en mode `apply`, réglaît quand même la transaction en `completed` malgré un écart montant (ex : provider dit 100, Nexus attend 80 → complétée). **Désormais** un écart bloque tout règlement automatique : la transaction reste `processing` avec action `reconciliation_required` (décision humaine). Test : `test_amount_discrepancy_is_detected_never_corrected`.
- **`still_processing` initialisé en scalaire `0`** mais utilisé comme tableau → crash PHP dès qu'une transaction tombait dans ce cas (jamais déclenché avant). Corrigé (`[]`).

**Ce qui manque** : pas de réconciliation quotidienne (opening/closing balance), pas de comparison de *soldes* provider (le polling compare par transaction, jamais `getBalance()` de pawaPay), pas de statut « suspense » réel (l'inconnu n'est pas attribué — bien — mais nulle part enregistré comme suspense : la table `reconciliation_items` n'est pas alimentée par le mécanisme automatique).

---

## E. Invariant fondamental — applicable aujourd'hui

L'invariant qui **tient réellement** dans le code actuel :

```
Σ wallet.balance (positions utilisateurs)  =  Σ ledger_entries nettes par wallet
```

Vérifié par construction (chaque mouvement wallet écrit une écriture ledger, idempotence partout). **MAIS** l'invariant visé par l'audit :

```
Σ positions utilisateurs + Nexus-owned + suspense  =  Σ positions externes provider
```

**ne peut pas être vérifié** : le membre droit n'existe nulle part (pas de table de positions provider). Le risque de **création artificielle de valeur** est aujourd'hui nul *en production* parce qu'il n'y a **aucun chemin de création de fonds** (pas de funding endpoint ; le seed de dev est le seul créditeur) — mais il est **structurellement non démontrable**.

---

## F. Source of truth — matrice (§22)

| Donnée | Source de vérité | État |
|---|---|---|
| Position utilisateur | Ledger Nexus | ✅ réel |
| Disponibilité (wallet) | Dérivée du ledger (hold) | ✅ réel |
| Provider balance | Provider | ❌ **jamais stockée ni interrogée** |
| Transaction provider | Provider (webhook + polling) | ✅ réel (pawapay) |
| Transaction Nexus | Nexus | ✅ réel |
| Settlement | Webhook + polling | ⚠️ webhook `completed` fait foi ; pas de check de solde |
| FX final | Quote/manuel | ⚠️ `fx_manual` démo seulement |
| Fees Nexus | Ledger | ❌ bundlées dans le debit, pas isolables |
| Provider fees | Provider | ❌ non collectées |
| Statut final | Règle documentée (STATUS_MAP) | ✅ réel |

---

## G. Réponses aux deux questions du §28

**Q1 : « Un utilisateur voit 1 000 EUR. Où sont réellement ces 1 000 EUR ? »**
> **Non répondable aujourd'hui.** Nexus sait que la *position* vaut 1 000 EUR (ledger). Il ne sait pas chez quel provider, sur quel compte, dans quelle devise la contrepartie existe — cette information n'est stockée nulle part et aucun processus ne la vérifie.

**Q2 : « Provider A détient la source, Provider B fait le payout : comment chaque centime est-il représenté ? »**
> **Non représentable.** Le modèle ne connaît qu'UN provider par transaction (`transactions.provider`). Il n'existe ni funding (pas de Provider A), ni comptes de position par provider, ni écritures inter-provider. Le chemin Provider A → FX → Provider B n'a aucun support en base.

---

## H. Gaps architecturaux (ce qu'il manque pour une comptabilité provider robuste)

| # | Gap | Impact | Sévérité |
|---|---|---|---|
| G1 | **Aucune table provider account / balance** (wallet → provider → external account) | Q1 impossible, pas d'inventory, pas de daily recon | **P0** |
| G2 | **Pas de chemin de dépôt/funding en production** (seul le seed crédite) | Produit non utilisable, invariant non démontrable en réel | **P0** |
| G3 | **Ledger à écriture unique** (debit/credit sans contrepartie) | Pas de double entrée, pas de P&L, pas de revenue traçable | **P1** |
| G4 | **Frais bundlés** (pas d'écriture fee, pas de revenue account) | Revenue Nexus indémontrable, audit fee impossible | **P1** |
| G5 | **Pas de chart of accounts** | Aucune catégorisation comptable | **P1** |
| G6 | **Soldes pending/in_transit/settlement morts** | États d'argent en vol non représentés | **P2** |
| G7 | **Pas de suspense réel** (inconnu non attribué mais non enregistré) | Transactions inconnues non traçables dans un état dédié | **P2** |
| G8 | **Pas de daily reconciliation** (opening/closing), `getBalance()` jamais utilisé | Écarts de solde indétectables | **P2** |
| G9 | **Pas de machine à états reversal/chargeback** (REVERSED → FAILED compensé) | completed ≠ définitif non représentable | **P2** |
| G10 | **Pas de FX provider** (taux manuel) | Spread/gain/loss FX non comptabilisés | **P3** |
| G11 | **Deux mécanismes de recon non reliés** (polling vs relevé manuel) | Un écart détecté par l'un invisible à l'autre | **P3** |

---

## I. Ce qui est solide (non remis en cause)

- Invariant `balance = available + hold` maintenu en toutes circonstances (verrous FOR UPDATE, bcmath).
- Idempotence complète (hold/capture/refund/webhook replay → aucun double mouvement, prouvé par test).
- Pas d'attribution automatique d'argent inconnu (l'opération sans transaction Nexus n'est jamais créditée).
- Isolation sandbox/production partout (y compris sur le rapprochement, après correctifs antérieurs).
- Le seul rail réel (pawaPay) refuse honnêtement plutôt que de simuler.

## J. Tests ajoutés

`tests/ProviderAccountingModelTest.php` — **11 tests / 44 assertions** (§26, tests 3+4 fusionnés) : entrée de fonds (écriture unique), sortie (débit), frais bundlés, FX reconstruisible, webhook dupliqué idempotent, webhook perdu détecté par recon, écart montant détecté + **jamais auto-corrigé**, opération inconnue non attribuée, refund cohérent, reversal absent, absence de `provider_accounts` vérifiée. Fixture `ScriptedProviderAdapter` dé-finalisée pour permettre la surcharge de `getPaymentStatus`.

Suite complète : **615 tests / 2545 assertions — 0 échec** (baseline 587/2442 + Phase 2).
