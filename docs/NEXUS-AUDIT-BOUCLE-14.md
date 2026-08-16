# NEXUS — Boucle 14 : audit de `CapabilityEngine::CATEGORY_DELAYS`

Audit mené **avant toute écriture de code**. Chaque affirmation ci-dessous a
été obtenue par exécution réelle, jamais par lecture seule.

## Baseline vérifiée

| Contrôle | Résultat |
|---|---|
| Branche / arbre | `chore/repo-hygiene-and-ci`, propre |
| Remote | `HEAD == remote` |
| Suite PHPUnit | **502 tests, 2195 assertions, OK** |
| Base `nexus_test` | 21 tables |
| API | `/api/health` → 200, `db: connected` |

---

# VERDICT : `CATEGORY_DELAYS` = **FABRICATED**

## 1. Où la constante est définie

`CapabilityEngine`, six entrées, auto-documentée « simulation démo » :

```php
private const CATEGORY_DELAYS = [
    'mobile_money'  => [60, 300],    // 1-5 min
    'banking'       => [180, 600],   // 3-10 min
    ...
];
```

Indexée **par CATÉGORIE, pas par provider**. Les trois providers Mobile Money
d'un corridor recevaient donc rigoureusement la même valeur.

## 2 à 5. Chemin complet

```
CATEGORY_DELAYS            constante, par catégorie
  → CapabilityEngine       delay_min / delay_max
  → QuoteEngine            delay_avg = moyenne de la fourchette / 60
  → RoutingEngine          speed_inv dans le scoring + badge + label
  → API                    delay: "~3 min", delayMinutes: 3
  → Frontend               RouteSelectionStep.tsx : ['Délai', route.delay]
```

## 6 et 7. Exposition et affichage

L'API renvoie `delay` et `delayMinutes`. Le frontend affiche `route.delay`
verbatim sous le libellé « Délai ».

## 8, 9, 10. Influence sur le routing

`speed_inv` dans `SCORING_WEIGHTS` :

| Objectif | Poids de la vitesse |
|---|---|
| **`fastest`** | **50 %** |
| `optimized` | 15 % |
| `cheapest` / `max_received` / `most_reliable` | 10 % |

Plus le badge contextuel « ⚡ PLUS RAPIDE » et le badge d'objectif `fastest`.

Effet pervers : tous les providers d'une même catégorie partageant la même
valeur, la composante vitesse était **neutralisée entre eux** — mais elle
départageait bel et bien les catégories, sur des chiffres inventés.

## Preuve HTTP (Phases 3 et 4)

**A — base sans aucune transaction :**
```
mtn_momo      badge=⚡ PLUS RAPIDE   delay=~3 min
orange_money  badge=ALTERNATIVE      delay=~3 min
pawapay       badge=ALTERNATIVE      delay=~3 min
```

**B — après 40 exécutions réelles chronométrées** (pawapay 600 s, mtn_momo
30 s — un écart mesuré de **20×**) :
```
mtn_momo      delay=~3 min
pawapay       delay=~3 min     ← 10 minutes réelles
```

Le délai affiché est **totalement déconnecté** des temps réellement observés.

## Source de vérité disponible (Phase 2)

`transactions.execution_time_seconds` :

- **déjà mesuré réellement** par `ExecutionEngine`
  (`max(1, round(microtime(true) - $startedAt))`) ;
- **déjà agrégé ailleurs** par `DashboardController` et `BusinessService`.

La donnée existait ; seul le routing l'ignorait. **Aucune table nouvelle
n'était nécessaire.**

`payments` a été écarté : la table ne porte aucune durée d'exécution. Calculer
`executed_at - created_at` mêlerait le temps d'approbation humaine au temps du
provider — ce serait mesurer autre chose.

## Tests existants / manquants

`grep -rn "CATEGORY_DELAYS\|delay_avg" tests/` → `RouteReliabilityDisplayTest`
utilise `delay_avg` comme simple **fixture**, sans jamais assiéger sa
véracité. **Aucun test ne couvrait les délais.**

## Risques

Faux affichage mesuré (§12) ; contradiction avec la promesse du Control Center
(« aucune donnée simulée ») ; décision de routing sur donnée fictive ; ETA
trompeur sur un produit financier.

---

# Correctif appliqué

## `ProviderLatency` — mesurer, ou dire qu'on ne mesure pas

Doctrine identique à `ProviderReliability` (boucle 13) :

| État | Signification | `seconds` |
|---|---|---|
| `MEASURED` | ≥ 20 exécutions chronométrées | médiane réelle |
| `INSUFFICIENT_DATA` | des exécutions, mais trop peu | `null` |
| `UNAVAILABLE` | aucune exécution chronométrée | `null` |

**Médiane, pas moyenne.** Un transfert bloqué 40 minutes suffirait à faire
passer un provider habituellement instantané pour lent. La médiane décrit ce
que l'utilisateur rencontre réellement — ce qu'un ETA doit annoncer. Le champ
`p90_seconds` expose la traîne lente que la médiane masque.

**Percentile arrondi vers le haut.** Avec `floor`, une série de 20 valeurs dont
18 à 60 s et deux lentes donnait un p90 à 60 s : la traîne disparaissait et le
p90 devenait indiscernable de la médiane. `ceil` est le choix prudent pour une
borne haute annoncée à un client. *Défaut trouvé par un test qui a échoué.*

## Ce qui change pour le client

| Avant | Après |
|---|---|
| `delay: "~3 min"` toujours | `"Non mesuré"` tant que rien n'est chronométré |
| `delayMinutes: 3` inventé | `null`, ou la médiane réelle |
| badge `⚡ PLUS RAPIDE` sans mesure | badge neutre `⭐ RECOMMANDÉE` |
| — | `delayStatus`, `delayMeasured`, `delayObs` |

## Deux pièges traités

1. **`(int) null === 0`** — pire que pour la fiabilité : un provider jamais
   chronométré valait « zéro minute », donc **le plus rapide de tous**. Une
   absence de mesure devenait un avantage au classement.
2. **Badge d'objectif** — `fastest` décernait « ⚡ PLUS RAPIDE » sur le seul
   objectif demandé. Le garde-fou introduit en boucle 13 pour `most_reliable`
   a été **généralisé** : une table associe chaque objectif au champ de preuve
   qu'il exige (`most_reliable` → `reliability`, `fastest` → `delay_avg`).

## Neutralité du classement

Sans mesure, `speedScore = 0.5` : ni bonus ni malus. Classer sur un délai
inconnu reviendrait à inventer un ordre de rapidité.

---

# Vérification

| Étape | Résultat |
|---|---|
| Tests | **524** (502 → 524, +22) |
| Mutation | **10 lancées, 10 tuées, 0 survivante** |
| HTTP | 3 scénarios réels |
| Base | mesure confrontée au `SELECT` réel |
| SQL | aucun changement de schéma ; `database/sql/` vérifié synchronisé |
| Lint | PASS |
| Équivalence schéma | PASS |

## Mutations

| # | Mutation | Tuée par |
|---|---|---|
| M1 | `null` → valeur artificielle (180 s) | 4 tests |
| M2 | constante fixe au lieu de la mesure | 6 tests |
| M3 | seuil minimal supprimé | 1 test |
| M4 | isolation d'environnement supprimée | 1 test |
| M5 | exécutions non terminées comptées | 1 test |
| M6 | moyenne au lieu de médiane | 2 tests |
| M7 | retour au `(int) null` d'affichage | 4 tests |
| M8 | badge contextuel sans vérification | 2 tests |
| M9 | badge d'objectif sans vérification | 1 test |
| M10 | score neutre remplacé par un bonus | 1 test |

## Preuve HTTP après correctif

Sans historique :
```
mtn_momo   badge=⭐ RECOMMANDÉE   delay=Non mesuré   measured=False
```

Avec mesures réelles (pawapay 600 s, mtn_momo 30 s) :
```
mtn_momo   badge=⚡ PLUS RAPIDE   delay=~1 min   obs=20   measured=True
```
pawaPay, mesuré à 10 minutes, **disparaît du top 3** pour l'objectif `fastest`
— lui qui affichait auparavant le même `~3 min` que les autres.

Production (aucune exécution réelle) : `Non mesuré` pour tous — les mesures
sandbox ne fuient pas.

---

# Limitations connues

- **Le seuil de 20 observations est un choix**, pas une mesure. Documenté et
  centralisé (`MIN_OBSERVATIONS`), aligné sur `ProviderReliability`.
- **Un seul délai mesuré ne suffit pas à classer.** Avec une unique valeur,
  `min == max` et la normalisation retourne 0.5 : le provider mesuré se
  retrouve à égalité avec un provider non mesuré. C'est délibéré — avec une
  seule mesure, rien ne permet d'affirmer qu'elle est « rapide » dans l'absolu.
  Documenté dans `RouteDelayDisplayTest`.
- **`p90_seconds` est calculé et exposé au Quote Engine, mais pas encore
  affiché** au client. La fourchette honnête (« ~1 min, jusqu'à 5 min ») est
  une amélioration d'interface, hors périmètre backend de cette boucle.
- **La colonne `provider` reste non normalisée** en base (nom d'affichage vs
  slug). Contourné et testé ici comme pour la fiabilité, mais la vraie
  correction serait une migration réécrivant l'historique.
- Mesures relevées sur données de test injectées : aucun trafic de production
  réel n'existe dans cet environnement.

# Prochain point d'audit identifié

`QuoteEngine::FIXED_RATE_EUR_TO_XAF` et le calcul des frais. Les montants
reçus et les frais affichés (`received`, `fees`, `spread_pct`) sont des
**promesses financières chiffrées** faites au client. Vérifier s'ils
proviennent de taux réels (`fx_rates_cache`, providers) ou de constantes —
même nature de risque que les deux défauts corrigés en boucles 13 et 14.
