# NEXUS — Boucle 13 : audit de `CapabilityEngine::PERFORMANCE_SCORES`

Audit mené avant toute écriture de code (directive 24). Le dépôt et la base
sont la source de vérité : chaque affirmation ci-dessous a été obtenue par
exécution, pas par lecture.

## Baseline vérifiée

| Contrôle | Résultat |
|---|---|
| Branche / arbre | `chore/repo-hygiene-and-ci`, propre |
| Suite PHPUnit | **484 tests, 2141 assertions, OK** |
| Base `nexus_test` | 21 tables |
| API HTTP | `/api/health` → 200, `db: connected` |

## 1. Le score est-il réellement mesuré ?

**Non.** `CapabilityEngine::PERFORMANCE_SCORES` est une constante PHP de 20
entrées codées en dur (`pawapay => 0.97`, `stripe => 0.99`, …), avec un
`DEFAULT_RELIABILITY = 0.95` pour tout provider absent de la liste.

Le commentaire du code l'admet : « simulation démo. En production, ces valeurs
viendraient de la table providers ou d'un service de métriques. »

## 2. Origine : aucune source de mesure n'existe

Inventaire des tables de `nexus_test` : **aucune table `providers`, `metrics`
ou `health`**. La table `provider_credentials` ne stocke que des secrets.
Il n'existe donc, à ce jour, ni table ni service d'où ces scores pourraient
provenir. Ce ne sont pas des valeurs périmées : ce sont des valeurs inventées.

## 3. Ce n'est pas un score interne : il est exposé et il décide

Le score traverse toute la chaîne :

```
CapabilityEngine (constante)
  → QuoteEngine        (recopie 'reliability')
  → RoutingEngine      (pondère le classement, 15 % à 55 % du score)
  → réponse HTTP       (reliability, reliabilityNum, reliabilityColor, badge)
```

Deux usages, tous deux problématiques :

1. **Il classe les routes.** L'objectif `most_reliable` accorde **55 %** du
   poids de scoring à ce nombre inventé. Le client qui demande « la route la
   plus fiable » reçoit un classement dicté par une constante.
2. **Il est affirmé au client.** L'API renvoie `reliability: "Élevée"`,
   `reliabilityNum: 0.97` et le badge `🛡️ PLUS FIABLE`.

## 4. Preuve HTTP : le score est insensible à la réalité

Sur une base **sans aucun historique**, l'API affirme déjà une fiabilité
mesurée :

```
pawapay        badge=🛡️ PLUS FIABLE   reliability=Élevée   num=0.97
orange_money   badge=ALTERNATIVE      reliability=Bonne    num=0.95
mtn_momo       badge=ALTERNATIVE      reliability=Bonne    num=0.94
```

Puis **10 paiements pawaPay en `status='failed'`** ont été insérés en base
(10 échecs sur 10, taux de succès réel : 0 %). Nouvelle requête, réponse
strictement identique :

```
pawapay        badge=🛡️ PLUS FIABLE   reliability=Élevée   num=0.97
```

Un provider qui échoue systématiquement reste présenté comme « le plus
fiable ». Le nombre ne décrit rien.

## 5. Contradiction formelle avec la doctrine du projet

`ControlCenterService` déclare en en-tête :

> « toute valeur exposée provient d'une mesure réelle […] **aucun score de
> fiabilité fabriqué**, aucun taux de succès simulé. »

Et l'interface affiche à l'utilisateur :

> « Chaque valeur affichée est mesurée sur le système réel. Aucune donnée
> n'est simulée. »

Ces deux affirmations sont fausses tant que `reliabilityNum` sort d'une
constante.

## 6. Aucun test ne couvre le sujet

`grep -rn "reliability" tests/` → **aucune assertion**. Comme pour les
sanctions en boucle 12, les 484 tests verts ne disent rien de ce trou.

## Verdict

**CRITICAL — faux succès de mesure (§12, §17).** Ce n'est pas un score
approximatif : c'est une valeur inventée présentée comme une mesure, qui
oriente une décision financière et s'affiche au client. La règle 17 s'applique
directement : *une absence de données ne doit jamais être interprétée comme un
résultat.*

## Plan retenu

Appliquer la doctrine `SanctionsScreening` (boucle 12), déjà éprouvée :
distinguer **mesuré** / **non mesuré**, ne jamais présenter le second comme le
premier.

1. **`ProviderReliability`** — service de mesure calculant le taux de succès
   réel par provider depuis `payments` (`completed` vs `failed`), scopé par
   environnement (une exécution sandbox ne dit rien de la production).
2. **Trois états explicites**, comme pour le screening :
   - `MEASURED` — assez d'observations réelles, score calculé ;
   - `INSUFFICIENT_DATA` — moins que le seuil minimal d'observations ;
   - `UNAVAILABLE` — aucune donnée.
3. **Fail-closed sur l'affichage, pas sur l'exécution** (§13). Un score non
   mesuré ne bloque pas le devis — il n'y a pas de risque financier direct à
   coter — mais il ne doit **jamais** être présenté comme mesuré : ni label
   « Élevée », ni badge « PLUS FIABLE », ni nombre inventé.
4. **Neutralité du classement** quand rien n'est mesuré : à défaut de mesure,
   la composante fiabilité doit être neutre pour tous les providers plutôt que
   de faire gagner un favori arbitraire.
5. Le contrat HTTP porte l'état (`reliabilityStatus`, `reliabilityMeasured`)
   pour que l'interface puisse afficher « non mesuré » honnêtement.

**Pas de table nouvelle si `payments` suffit** (directive 1 : vérifier avant de
créer). L'audit confirme que `payments` porte déjà `provider`, `status`,
`environment` et `executed_at` — la matière première d'une mesure réelle.

---

# Correctif appliqué

## `ProviderReliability` — mesurer, ou dire qu'on ne mesure pas

La constante `PERFORMANCE_SCORES` est **supprimée**. La fiabilité provient
désormais des exécutions réelles, agrégées depuis `transactions` et `payments`
(aucune table nouvelle : l'audit a confirmé que la matière première existait
déjà, cf. directive 1).

Trois états, même doctrine que `SanctionsScreening` :

| État | Signification | `score` |
|---|---|---|
| `MEASURED` | ≥ 20 exécutions terminées observées | taux de succès réel |
| `INSUFFICIENT_DATA` | des exécutions, mais trop peu | `null` |
| `UNAVAILABLE` | aucune exécution | `null` |

`score` vaut `null` dès que la mesure est absente : aucun appelant ne peut
publier un nombre non mesuré par inadvertance.

**Pourquoi un seuil de 20.** Un provider ayant réussi son unique transfert
afficherait 100 % et dominerait le classement. Une observation n'est pas une
statistique.

**Isolation par environnement.** Des succès en sandbox ne disent rien de la
production. Vérifié en HTTP : orange_money mesuré à 0.9 en sandbox redevient
« Non mesurée » en production.

## Ce qui change pour le client

| Avant | Après |
|---|---|
| `reliability: "Élevée"` toujours | `"Non mesurée"` tant que rien n'est observé |
| `reliabilityNum: 0.97` inventé | `null`, ou le taux réel |
| badge `🛡️ PLUS FIABLE` sans mesure | badge neutre `⭐ RECOMMANDÉE` |
| — | `reliabilityStatus`, `reliabilityMeasured`, `reliabilityObs` |

## Trois pièges traités

1. **`(float) null === 0.0`.** Sans garde, « non mesuré » devenait 0.0, donc le
   label « Modérée » en orange — une note inventée pour une absence de donnée.
2. **Deux sources de badge.** Corriger le badge contextuel ne suffisait pas :
   `OBJECTIVE_BADGES` décernait « PLUS FIABLE » sur le seul objectif demandé.
   **Ce second cas n'a été vu qu'en rejouant la requête HTTP réelle**, alors que
   le score affichait déjà « Non mesurée » — les tests unitaires initiaux le
   manquaient.
3. **Identité du provider.** L'ExecutionEngine enregistre le NOM d'affichage
   (`pawaPay`), le Core raisonne en slug (`pawapay`). Vérifié en base : les deux
   formes coexistent. Ne compter que le slug amputerait la mesure — et une
   mesure incomplète est une mesure fausse.

## Neutralité du classement

Sans mesure, la composante fiabilité vaut `0.5` pour tous : ni bonus ni malus.
Départager des routes sur un score absent reviendrait à inventer un ordre.
`most_reliable` (55 % du poids) laisse alors décider les critères réels.

# Vérification

| Étape | Résultat |
|---|---|
| Tests | **502** (484 → 502, +18) |
| Mutation | **8 lancées, 8 tuées** |
| HTTP | 4 scénarios réels |
| Base | mesure confrontée au `SELECT` réel |
| SQL | `database/sql/` généré, reproductibilité prouvée |
| Lint | PASS |
| Équivalence schéma | PASS |

## Mutations

| # | Mutation | Tuée par |
|---|---|---|
| M1 | `UNAVAILABLE` renvoie 0.95 (le bug d'origine) | 3 tests |
| M2 | seuil minimal supprimé | 1 test |
| M3 | échecs non comptés | 3 tests |
| M4 | isolation d'environnement supprimée | 1 test |
| M5 | retour au `(float) null` d'affichage | 4 tests |
| M6 | badge contextuel sans vérification | 1 test |
| M7 | badge d'objectif sans vérification | 1 test |
| M8 | nom catalogue ignoré | 1 test |

**M8 a d'abord survécu deux fois.** Le premier test utilisait `pawapay` /
`pawaPay`, un écart que la collation `utf8mb4_unicode_ci` rattrape seule ; le
second était pollué par 20 lignes laissées par mes propres essais HTTP. Il a
fallu choisir `orange_money` / « Orange Money » (écart underscore/espace, non
rattrapable) et comparer un AVANT/APRÈS plutôt qu'un total absolu. *Un test qui
survit à sa mutation ne protège rien.*

## Preuve HTTP

Sans historique — plus aucune affirmation :
```
mtn_momo      badge=⭐ RECOMMANDÉE   reliability=Non mesurée  num=None  measured=False
```

Avec historique réel (pawapay 0/20, orange_money 18/20) :
```
orange_money  badge=🛡️ PLUS FIABLE  reliability=Bonne  num=0.9  obs=20  measured=True
```
pawaPay, 0 % de succès, **disparaît du top 3** — alors qu'il était premier avec
0.97 avant le correctif. Base confirmée : `taux_reel = 0.9000` pour
orange_money, identique à la valeur servie.

# SQL de référence (directives 8-10)

`database/sql/` est créé, avec `nexus_schema.sql`, `nexus_seed.sql` et
`nexus_full.sql`, **générés** par `scripts/export_sql_reference.sh` depuis la
base reconstruite par les migrations.

Reproductibilité vérifiée réellement : une base vierge reconstruite depuis
`nexus_schema.sql` présente les mêmes **264 colonnes**, et les **502 tests
passent dessus**.

Un job CI régénère l'export et échoue si un écart apparaît : c'est ainsi que
`full_schema.sql` avait divergé en boucle 12, en restant plausible tout en
décrivant un schéma périmé.

# Limitations connues

- **Le seuil de 20 observations est un choix, pas une mesure.** Il est
  documenté et centralisé (`MIN_OBSERVATIONS`), donc discutable et modifiable
  sans toucher au reste.
- **La colonne `provider` n'est pas normalisée** en base (nom d'affichage vs
  slug). Contourné et testé, mais la vraie correction serait une migration
  normalisant les valeurs existantes — hors périmètre de cette boucle, car
  elle réécrirait des données historiques.
- **Aucune mesure de latence** : `transactions.execution_time_seconds` existe
  et permettrait de mesurer les délais, aujourd'hui encore estimés par
  `CATEGORY_DELAYS` (constante). **Même nature de défaut que celui corrigé
  ici** — prochain point d'audit identifié.
- Mesures relevées sur données de test injectées : aucun trafic de production
  réel n'existe dans cet environnement.
