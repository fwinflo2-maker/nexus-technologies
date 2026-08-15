# NEXUS — Boucle 16 : isolation SANDBOX / PRODUCTION du cache FX

Audit mené **avant toute écriture de code**. Chaque affirmation a été obtenue
par exécution réelle (HTTP + base), jamais par lecture seule.

## Baseline vérifiée

| Contrôle | Résultat |
|---|---|
| Branche / arbre | `chore/repo-hygiene-and-ci`, propre |
| Remote | `HEAD == remote` |
| Suite PHPUnit | **537 tests, 2289 assertions, 0 failure** |
| Base `nexus_test` | 21 tables |

---

# Le problème

`fx_rates_cache` était la **dernière source financière non isolée**. Toutes les
autres couches le sont pourtant : `ledger_entries`, `quotes`, `payments`,
`transactions`, `wallet_operations`, fiabilité (boucle 13), latence
(boucle 14).

```sql
CREATE TABLE fx_rates_cache (
  base_currency, quote_currency, rate, spread_pct, source,
  fetched_at, expires_at,
  PRIMARY KEY (id),
  KEY idx_fx_pair (base_currency, quote_currency, fetched_at)  -- non unique
)
```

Aucune colonne `environment`, aucune contrainte d'unicité.
`FXRateCache::lookup()` filtrait sur la seule paire de devises et prenait
l'entrée la plus récente non expirée.

## Preuves de contamination (HTTP + DB, avant correctif)

| # | En base | Appel | Résultat |
|---|---|---|---|
| A | `EUR→XAF=100` source `audit_sandbox` | sandbox | `100` ✓ |
| **B** | idem (**taux de test seul**) | **production** | **`100` / `audit_sandbox`** ❌ |
| C | `EUR→XAF=200` source `audit_production` | sandbox | **`200`** ❌ |
| D | idem, via **Convert** | sandbox | **`200`** ❌ |
| E | cache **vide** | production | **`655.957` / `manual`** ❌ |

**Contamination bidirectionnelle**, sur **Send et Convert**. En B, une quote en
argent réel a été produite à partir d'un taux de test. En E, le
`ManualRateProvider` — des taux **codés en dur** — cotait en production.

Aggravant : `database/seeds/demo_fx_rates.sql` alimente cette même table avec
des taux de **démonstration**.

## Tests existants

`FXServiceTest` : **0 occurrence** de `environment`. Aucun test d'isolation.

---

# Correctif

## 1. Migration 0.20 — `2026_08_15_fx_rates_environment.sql`

- colonne `environment ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox'`
  — **défaut sûr**, comme la migration 0.19 : un oubli produit une donnée de
  test, jamais de l'argent réel ;
- index remplacé par `idx_fx_pair_env` (scopé) — l'ancien laissait la recherche
  traverser les environnements ;
- unicité `uq_fx_pair_env_fetched (base, quote, environment, fetched_at)` — la
  table n'avait aucune contrainte, deux taux au même instant pouvaient
  coexister et le « dernier » gagnait arbitrairement ;
- lignes existantes → `sandbox` (choix prudent : un taux inséré sans intention
  d'environnement ne doit pas être promu en argent réel).

Motif `information_schema` + `PREPARE`, **portable MariaDB / MySQL 8**,
idempotente (rejeu vérifié deux fois).

## 2. Code

| Composant | Changement |
|---|---|
| `FXRateCache::lookup/store` | environnement **obligatoire**, sans défaut |
| `FXService::resolve` | scopé + **repli manuel interdit en production** |
| `QuotePricing` | environnement propagé ; cache par requête **clé par env** |
| `QuoteEngine` | environnement transmis à `resolveRate` et `rateToEur` |
| `WalletService` (Convert) | taux résolu dans l'environnement de l'opération |
| `demo_fx_rates.sql` | `environment = 'sandbox'` explicite |

**Pourquoi le repli manuel est interdit en production** : `ManualRateProvider`
porte des taux écrits dans le code, sans horodatage réel ni provenance externe.
En production, l'absence de taux doit produire un **refus visible**, pas une
valeur silencieuse.

La sandbox conserve ce repli : elle ne déplace aucun argent réel et doit rester
utilisable sans configuration préalable.

---

# Preuves après correctif

## HTTP — les trois scénarios

```
DB : EUR→XAF = 100 (sandbox) ET 200 (production) coexistent

A  SEND sandbox      rate=100.0  source=audit_sandbox      received=710 XAF
B  SEND production   rate=200.0  source=audit_production   received=1 420 XAF
C  SEND sandbox      rate=100.0  source=audit_sandbox      received=710 XAF
```

## HTTP — fail-closed (le cas critique)

```
DB : seul un taux SANDBOX existe
PRODUCTION → 503 FX_RATE_UNAVAILABLE
             « Aucun taux de change disponible pour EUR → XAF en production. »

AVANT : le taux sandbox 100 était servi en production.
```

## HTTP — cohérence Send / Convert

```
SANDBOX     SEND    rate=100.0  source=iso_sandbox
            CONVERT rate=100.0  source=iso_sandbox
PRODUCTION  SEND    rate=200.0  source=iso_production
            CONVERT rate=200.0  source=iso_production
```

Même taux, même source, même environnement — sans fuite dans aucun sens.

---

# Vérification

| Étape | Résultat |
|---|---|
| Tests | **550** (537 → 550, +13) |
| Mutation | **7 lancées, 7 tuées, 0 survivante** |
| HTTP | 3 scénarios + fail-closed + cohérence Send/Convert |
| Base | deux taux coexistants vérifiés par `SELECT` |
| Migration | idempotente (rejouée 2×) |
| Reconstruction | base vierge depuis `database/sql/` → **550 tests verts** |
| Équivalence schéma | PASS (37 contraintes uniques) |
| Lint | PASS |

## Mutations

| # | Mutation | Tuée par |
|---|---|---|
| M1 | `environment` retiré du lookup | 7 tests |
| M2 | `environment` forcé à sandbox | 6 tests |
| M3 | `environment` forcé à production | 4 tests |
| M4 | production retombe sur le repli manuel | 4 tests |
| M5 | production retombe sur le cache sandbox | 3 tests |
| M6 | cache par requête non scopé | 1 test |
| M7 | `store()` écrit toujours en production | 2 tests |

**M7 a d'abord survécu** : `store()` n'était couvert par aucun test. Écrire un
taux dans le mauvais monde est pourtant aussi grave que le lire — c'est même la
façon la plus directe de faire coter un taux de test en argent réel. Deux tests
ont été ajoutés plutôt que la mutation écartée.

**Un test mal conçu a aussi été corrigé** : il comparait le repli manuel
légitime (`655.957`) à un taux de production de même valeur, rendant la
contamination indiscernable du comportement normal. Les valeurs de test sont
désormais reconnaissables (`777`, `123`).

## Tests existants adaptés

- `FXServiceTest` : signature à trois arguments, insertions avec environnement.
- `QuotePricingTest` : idem.
- `ContextCompletenessTest` : ces tests convertissent EUR→USD dans les deux
  environnements ; la production exige désormais un taux réel. Un taux est posé
  dans **chaque** environnement — ce qui reflète la configuration réelle
  attendue — pour qu'ils continuent de tester ce qu'ils annoncent (propagation
  du contexte, idempotence scopée) et non la disponibilité FX.

---

# Limitations connues

- **Les corridors de production doivent être configurés.** Aucun taux de
  production n'existe par défaut : toute quote production est refusée tant
  qu'un taux réel n'est pas inséré dans `fx_rates_cache`. C'est le
  comportement voulu, mais c'est un **changement opérationnel** : la
  production n'est plus utilisable « par défaut ».
- **Aucun provider FX externe temps réel** n'est branché. Le peuplement des
  taux de production reste un acte d'exploitation manuel.
- **Les lignes préexistantes ont été classées `sandbox`.** Sur une base de
  production réelle, cela signifie que les taux en cache devront être
  re-qualifiés explicitement — refus visible plutôt que promotion silencieuse.
- **`ManualRateProvider` reste un jeu de taux en dur** (6 paires). Il n'est
  plus atteignable en production, mais demeure la source par défaut de la
  sandbox.
- **`spread_pct` du cache** n'est pas isolé différemment : il suit la ligne, donc
  l'environnement de celle-ci.

# Prochain point d'audit identifié

**`Currency::RATE_TO_EUR` et `Currency::RATE_TO_XAF`** — deux tables de taux
codées en dur, encore utilisées pour les agrégats (`amount_ref`, `amount_xaf`,
totaux du dashboard, plafonds du PolicyEngine). Elles échappent entièrement à
`FXService` : les montants de référence et les contrôles de plafond reposent
donc toujours sur des taux figés, dont l'un (`USD → 0.92`) contredit déjà
`QuoteEngine::rateToEur` (`1.0870`). Même nature de risque que les boucles 13
à 16.
