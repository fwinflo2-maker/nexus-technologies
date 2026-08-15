# NEXUS — Boucle 15 : audit du taux FX, des frais, du spread et du montant reçu

Audit mené **avant toute écriture de code**. Chaque affirmation a été obtenue
par exécution réelle.

## Baseline vérifiée

| Contrôle | Résultat |
|---|---|
| Branche / arbre | `chore/repo-hygiene-and-ci`, propre |
| Remote | `HEAD == remote` |
| Suite PHPUnit | **524 tests, 2257 assertions, OK** |
| API | `/api/health` → 200 |

---

# VERDICT

```
FX RATE:   FABRICATED
FEES:      FABRICATED
SPREAD:    FABRICATED
RECEIVED:  INCORRECT (hors corridor XAF/XOF)
```

## Preuve 1 — le taux ignorait la source FX réelle

Injection de `EUR→XAF = 100` dans `fx_rates_cache` (source `audit_test`), puis
appel des deux chemins **au même instant** :

| Chemin | Taux appliqué | Source tracée |
|---|---|---|
| **Convert** (`FXService`) | `100.00` ✅ | `fx_source: audit_test` ✅ |
| **Send** (`QuoteEngine`) | `650.17` ❌ | aucune ❌ |

Deux chemins du même produit, deux taux différents pour la même paire.

## Preuve 2 — `received` faux hors XAF

`$effectiveRate = FIXED_RATE_EUR_TO_XAF * (1 - $spread)` était appliqué **quelle
que soit `destCurrency`**. Mesuré en HTTP, 100 EUR :

| Destination | Nexus annonçait | Réel attendu | Écart |
|---|---|---|---|
| Ghana (GHS) | 63 027 | ~1 435 | **44× trop** |
| Kenya (KES) | 63 566 | ~13 700 | **4,6× trop** |
| Nigeria (NGN) | 63 534 | ~160 000 | **2,6× trop bas** |

`655.957` est la parité fixe officielle EUR/CFA : la constante n'était pas
fausse en soi, elle était appliquée **hors de son domaine de validité**.

## Preuve 3 — frais et spread aléatoires

L'en-tête du fichier l'admettait : « variation **aléatoire** bornée pour
**simuler** la concurrence entre providers ». `mt_rand()` produisait le spread
(0,1–1,0 %) et faisait varier les frais de ±10 % — sur des montants facturés.

## Preuve 4 — frais non proportionnels

2,97 € de frais pour 100 € **comme pour 5000 €**. Aucune composante `%`.

## Preuve 5 — taux en dur dupliqués et incohérents

Six emplacements. `QuoteEngine::rateToEur` dit USD→EUR = `1.0870`,
`Currency::RATE_TO_EUR` dit `0.92` : conventions inversées.

## Preuve 6 — aucun test

`grep QuoteEngine:: tests/` → **vide**. Les occurrences de `fees`/`received`
sont des fixtures.

## Source de vérité disponible

Une infrastructure FX **complète existait déjà** : `FXService` →
`FXRateCache` → `ManualRateProvider`, table `fx_rates_cache` (`rate`,
`spread_pct`, **`source`**, `fetched_at`, `expires_at`), modèle `FXRate`
exposant `getSource()`. `FXService` **lève une exception sur paire inconnue** —
le fail-closed était déjà en place. Seul Send l'ignorait.

## Classement

| Sévérité | Problème |
|---|---|
| **CRITICAL** | `received` faux hors XAF/XOF (jusqu'à 44×) |
| **CRITICAL** | Taux ignorant `fx_rates_cache` — Send ≠ Convert |
| **HIGH** | Frais et spread aléatoires |
| **HIGH** | Aucune traçabilité du taux exposée |
| **MEDIUM** | Frais sans composante proportionnelle |
| **MEDIUM** | Taux en dur dupliqués (6 emplacements) |
| **LOW** | `fx_rates_cache` non isolée par environnement |

---

# Correctif appliqué

## `QuotePricing` — taux réel et traçable, ou pas de quote

Deux états seulement, car un taux manquant rend la quote **impossible** :

| État | Signification | `rate` |
|---|---|---|
| `RESOLVED` | source identifiable (cache FX ou provider) | taux réel |
| `UNAVAILABLE` | aucune source ne connaît la paire | `null` → refus |

Contrairement à la fiabilité ou à la latence — dont l'absence est une
information affichable —, le montant reçu est le cœur de la promesse
financière : on refuse de coter (`503 FX_RATE_UNAVAILABLE`).

## Ce qui change

| Avant | Après |
|---|---|
| taux XAF pour toute devise | taux de la paire réellement demandée |
| `fx_rates_cache` ignoré | source consultée, Send = Convert |
| spread `mt_rand()` 0,1–1,0 % | `spread_pct` de la source (0 si non déclaré) |
| frais ±10 % aléatoires | barème fixe, reproductible |
| aucune traçabilité | `rateSource`, `rateFetchedAt`, `rateExpiresAt`, `feeSource` |
| montant faux annoncé | refus explicite si pas de taux |

## Preuve HTTP après correctif

```
EUR→XAF : received=63 693  rate=655.957  rateSource=manual
          rateFetchedAt=2026-08-15T10:06:59+00:00
          rateExpiresAt=2026-08-16T10:06:59+00:00  feeSource=nexus_schedule

EUR→GHS : 503 FX_RATE_UNAVAILABLE
          « Aucun taux de change disponible pour EUR → GHS. »
          (annonçait 63 027 GHS avant — 44× le montant réel)

Après injection EUR→GHS = 14.80 :
          received=1 437 GHS  rate=14.8  rateSource=audit_ghs
          vérif : (100 - 2.90) × 14.80 = 1437.08 ✓

Cohérence Send/Convert (taux injecté 100) :
          SEND    rate=100.0  source=audit_coherence  received=710 XAF
          CONVERT rate=100.0  source=audit_coherence  dest=1000.00
          (écart normal : Send déduit 2,90 € de frais, Convert n'en applique pas)
```

---

# Vérification

| Étape | Résultat |
|---|---|
| Tests | **537** (524 → 537, +13) |
| Mutation | **10 lancées, 10 tuées, 0 survivante** |
| HTTP | 4 scénarios réels |
| Base | quotes persistées vérifiées ; taux confrontés au `SELECT` |
| Math | 100 / 1000 / 5000 EUR recalculés |
| SQL | aucun changement de schéma ; `database/sql/` synchronisé |
| Lint | PASS |

## Mutations

| # | Mutation | Tuée par |
|---|---|---|
| M1 | réintroduction du taux fixe | 1 test |
| M2 | `UNAVAILABLE` renvoie un taux artificiel | 2 tests |
| M3 | cache FX ignoré | 7 tests |
| M4 | spread forcé à 0 | 1 test |
| M5 | retour au spread aléatoire | 3 tests |
| M6 | retour aux frais aléatoires | 3 tests |
| M7 | `received` ne déduit plus les frais | 2 tests |
| M8 | plus de refus sans taux | 1 test |
| M9 | conversion des frais inversée | 1 test |
| M10 | provenance du taux masquée | 1 test |

**M9 a d'abord survécu.** Tous mes tests utilisaient EUR en source, où
`sourceToEur = 1.0` : multiplier ou diviser donnait le même résultat. Il a
fallu un test partant de XAF pour que l'erreur devienne visible. *Un test qui
survit à sa mutation ne protège rien.*

---

# Limitations connues

- **Les frais restent un barème Nexus**, pas un frais provider réel : les
  intégrations providers ne sont pas branchées. C'est désormais **explicite**
  via `fee_source: nexus_schedule`, au lieu d'être présenté comme un prix de
  marché.
- **Les frais n'ont pas de composante proportionnelle.** Le barème reste fixe
  par méthode ; ajouter un pourcentage serait une décision produit, pas une
  correction de défaut.
- **`ManualRateProvider` ne couvre que 6 paires** (USD, GBP, XAF, XOF, USDT,
  USDC). Les corridors GHS/KES/NGN sont désormais **refusés honnêtement**
  plutôt que faussement cotés — c'est le comportement correct tant qu'aucune
  source réelle n'est configurée, mais cela **restreint les corridors
  utilisables** par rapport à l'état antérieur (qui « fonctionnait » en
  mentant).
- **`fx_rates_cache` n'est pas isolée par environnement** : pas de colonne
  `environment`. Un taux sandbox et un taux production ne sont pas
  distinguables. Correction = migration, hors périmètre de cette boucle ;
  signalé comme prochain point d'audit.
- **`rateToEur` conserve une table de repli en dur**, utilisée uniquement pour
  convertir les *frais* en devise source — jamais pour le montant reçu, qui
  exige un taux réel.
- Aucun provider FX externe temps réel n'est branché : les taux proviennent du
  cache ou du provider manuel.

# Prochain point d'audit identifié

**`fx_rates_cache` sans colonne `environment`.** Un taux injecté en sandbox
sert aussi la production, ce qui contredit l'isolation appliquée partout
ailleurs (fiabilité, latence, ledger, quotes). C'est le dernier maillon FX non
isolé.
