# NEXUS — Boucle 17 : unification du référentiel FX

Audit mené **avant toute écriture de code**. Chaque affirmation a été obtenue
par exécution réelle (PHP, base, HTTP), jamais par lecture seule.

## Baseline vérifiée

| Contrôle | Résultat |
|---|---|
| Branche / arbre | `chore/repo-hygiene-and-ci`, propre |
| Suite PHPUnit | **550 tests, 2311 assertions, 0 failure** |
| Base `nexus_test` | 21 tables |

> L'environnement (PHP, client et serveur MariaDB) avait disparu entre les
> sessions et a été réinstallé ; `nexus_test` a été reconstruite par le runner
> de migrations — ce qui a validé au passage la reproductibilité.

---

# PHASE 2 — Matrice des sources de taux

| Source | USD | Env. | Consommateurs | Prod ? | Via FXService ? |
|---|---|---|---|---|---|
| `Currency::RATE_TO_EUR` | `0.92` | ✗ | ExecutionEngine, Dashboard, BusinessService, AuthController, WalletController, PaymentController | **Oui** | **Non** |
| `Currency::RATE_TO_XAF` | `603.0` | ✗ | ExecutionEngine (`amount_xaf`) | **Oui** | **Non** |
| `QuoteEngine::rateToEur` | `1.0870` | ✗ | conversion des frais | Oui (repli) | Partiel |
| `QuoteService::rateToEur` | `1.0870` | ✗ | **PolicyEngine** | **Oui** | **Non** |
| `QuoteController::rateToEur` | `1.0870` | ✗ | **PolicyEngine** | **Oui** | **Non** |
| `ManualRateProvider` | `1.0870` | sandbox | repli FXService | Non (b.16) | — |
| `FXService` | dynamique | ✓ | Send, Convert | Oui | — |

## Rectification d'une hypothèse de la boucle 16

La « divergence `0.92` vs `1.0870` » **n'existait pas** : deux conventions
inverses numériquement équivalentes (`1 / 1.0870 = 0.9200`), soit **0,004 %**
d'écart. Je n'ai pas corrigé un faux problème.

`Currency` est en revanche légèrement incohérent avec lui-même :
`RATE_TO_EUR['USD'] × 655.957 = 603.48` contre `RATE_TO_XAF['USD'] = 603.0`
(0,1 %). Documenté, non bloquant.

## Le défaut réel : aucun lien avec `FXService`

**Scénario E/F** — taux injecté `1 EUR = 5 USD` :
```
FXService                  : 5.00000000  [source=proof17]
Currency::rateToRef('USD') : 0.9200      (figé)
1000 USD -> 920 EUR au lieu de 200 EUR — écart de 4,6×
```

**Scénario C (HTTP)** — le PolicyEngine ne voyait pas le taux :
```
taux réel 1.10 -> refus, « il vous reste 750 EUR »
taux réel 5.00 -> refus, « il vous reste 750 EUR »   ← identique
```
Le taux varie de ×4,5, le verdict de sécurité ne bouge pas.

## Classification

| Gravité | Usage | Impact |
|---|---|---|
| **CRITICAL** | `ExecutionEngine` : frais convertis, **débités au client** | Argent réel |
| **CRITICAL** | `ExecutionEngine` : `amount_ref`/`amount_xaf` **persistés** | Ledger, rapports |
| **HIGH** | `PolicyEngine` (via QuoteService/QuoteController) | Plafonds KYC |
| **MEDIUM** | Dashboard, BusinessService | Totaux affichés |
| **LOW** | `AuthController` (seed démo, déjà sandbox) | Démonstration |

---

# PHASE 5-6 — Architecture retenue

**`FXService` est l'autorité unique.** Aucun second système FX n'a été créé.

`ReferenceConverter` est un **adaptateur**, pas une source : il ne connaît
aucun taux et délègue à `QuotePricing` → `FXService` → `FXRateCache` →
`ManualRateProvider`. Son rôle est d'exprimer un besoin récurrent (« combien
vaut ce montant en EUR / XAF ? ») sans que chaque appelant réimplémente la
résolution.

| État | Signification | `rate` |
|---|---|---|
| `RESOLVED` | source réelle identifiable | taux |
| `FALLBACK` | constantes `Currency` — **sandbox uniquement** | constante |
| `UNAVAILABLE` | aucun taux, aucun repli autorisé | `null` |

## Traitement de `Currency` (non supprimé aveuglément)

Les constantes sont **conservées** comme repli sandbox documenté et pour la
compatibilité des usages de présentation. Constat vérifié : en pratique ce
repli est **inatteignable**, `ManualRateProvider` couvrant déjà toutes les
devises connues de `Currency` (EUR, USD, GBP, XAF, USDT, USDC). Il reste un
filet pour une devise ajoutée à `Currency` sans l'être au provider — et un
test fige le fait qu'il ne masque rien aujourd'hui.

---

# Preuves après correctif

## HTTP — le plafond suit désormais le taux

```
1 USD = 0.90 EUR -> 2400 USD = 2160 EUR -> REFUSÉ (plafond 2000)
1 USD = 0.20 EUR -> 2400 USD =  480 EUR -> plafond franchi, contrôle suivant

AVANT : les deux cas donnaient le même verdict.
```

## HTTP — fail-closed production

```
PRODUCTION sans taux USD→EUR :
  503 FX_RATE_UNAVAILABLE
  « Aucun taux USD → EUR disponible en production : le plafond
    réglementaire ne peut pas être vérifié. »
```

## Base — `amount_ref` persisté

Vérifié par test sur la ligne réellement écrite : `1000 USD` avec
`1 USD = 0.25 EUR` persiste `250.00`, et non `920.00`.

---

# Vérification

| Étape | Avant | Après |
|---|---|---|
| Tests | 550 | **570** |
| Assertions | 2311 | **2371** |
| Mutation | — | **7 lancées, 7 tuées** |
| Lint | PASS | PASS |
| Schéma | 21 tables | 21 tables (inchangé) |
| `database/sql/` | sync | sync |
| Équivalence schéma | PASS | PASS |

## Mutations

| # | Mutation | Tuée par |
|---|---|---|
| M1 | retour aux constantes (ignorer FXService) | 11 tests |
| M2 | production autorise le repli constantes | 3 tests |
| M3 | environnement forcé à sandbox | 6 tests |
| M4 | QuoteService revient à `1.0870` | 1 test |
| M5 | production sans taux ne refuse plus | 1 test |
| M6 | `amount_ref` revient aux constantes | 2 tests |
| M7 | `amount_xaf` revient aux constantes | 1 test |

**M6 a d'abord survécu** : aucun test ne vérifiait la valeur réellement écrite
en base. `TransactionReferenceAmountTest` a été ajouté pour tester l'effet
observable — la ligne persistée — plutôt que l'implémentation.

**Deux tests mal conçus ont été corrigés** : ils utilisaient `USD`, que
`ManualRateProvider` résout directement, si bien que les branches « inverse »
et « repli » n'étaient jamais atteintes et ne prouvaient rien.

---

# PHASE 8 — Dashboard / agrégats : décision documentée

Les consommateurs `DashboardController`, `BusinessService`, `WalletController`
et `PaymentController` utilisent encore `Currency::rateToRef()`.
**Volontairement non modifiés dans cette boucle** :

- ils produisent une **valorisation d'affichage**, pas une écriture comptable ;
- ils n'ont pas d'`ExecutionContext` sous la main : leur passer un
  environnement demanderait de modifier plusieurs signatures publiques, donc
  un périmètre distinct ;
- surtout, **le contrat fonctionnel est ambigu** : un total de portefeuille
  doit-il être valorisé au taux courant ou au taux historique de chaque
  mouvement ? Les deux réponses sont défendables et la consigne interdit de
  trancher sans preuve. Ce point est explicitement laissé ouvert.

En revanche, la donnée **persistée** (`amount_ref`, `amount_xaf`) est figée au
taux du moment de l'exécution : c'est la valeur de référence de la
transaction, et elle n'est jamais recalculée après coup.

---

# Risques résiduels

- **Dashboard et agrégats** utilisent toujours les constantes (voir ci-dessus).
  Impact : totaux affichés, jamais un débit ni un contrôle de sécurité.
- **Corridors de production** : toute paire sans taux production configuré est
  refusée, y compris pour la simple vérification de plafond. Conséquence
  opérationnelle directe du fail-closed.
- **`amount_ref` vaut `0.00`** si aucune référence n'est calculable : les
  colonnes sont `NOT NULL DEFAULT 0.00` (vérifié en base) et changer leur
  nullabilité serait une migration à part. Ce cas ne survient qu'en production
  sans taux, où le devis est déjà refusé en amont.
- **Le repli `FALLBACK` est du code mort** en pratique — conservé comme filet,
  et couvert par un test qui documente cet état.
- **`QuoteEngine::rateToEur`** conserve sa table de repli, utilisée uniquement
  pour convertir les frais, jamais le montant reçu.

# Prochain point d'audit

**`DashboardController` / `BusinessService`** — trancher le contrat de
valorisation des agrégats (taux courant vs taux historique), puis brancher ces
chemins sur `ReferenceConverter`. Nécessite une décision produit avant toute
implémentation : c'est précisément le type d'ambiguïté qu'il ne faut pas
résoudre par supposition.
