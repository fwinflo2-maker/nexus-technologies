# Propagation de l'environnement & cohérence financière

Phase : *Environment Propagation & Financial Consistency*
Base de départ : `48034d3` — 276 tests / 1261 assertions.
État à la fin de la phase : **294 tests / 1323 assertions**, 0 régression.

---

## 1. Schéma avant / après

L'environnement s'arrêtait au milieu du cycle financier :

```text
AVANT                                APRÈS
quotes             → absent          quotes             → ENUM NOT NULL
wallet_operations  → absent          wallet_operations  → ENUM NOT NULL
ledger_entries     → absent          ledger_entries     → ENUM NOT NULL
transactions       → présent         transactions       → présent
payments           → présent         payments           → présent
idempotency_keys   → absent          idempotency_keys   → ENUM NOT NULL  (+ unicité scopée)
audit_logs         → absent          audit_logs         → ENUM NULLABLE
```

Migration : `2026_08_14_environment_propagation.sql` (manifeste `# 0.14`),
idempotente — rejouée 3 fois, `SCHEMA EQUIVALENCE PASS` après chaque passage.

### Tables retenues, et pourquoi

| Table | Justification |
|---|---|
| `quotes` | Une quote est une **décision financière persistante** (taux, frais, route). Elle engage l'exécution qui la consomme. |
| `wallet_operations` | État financier à **cycle de vie long** (hold → capture). L'environnement doit être relu, pas recalculé. |
| `ledger_entries` | **Source de vérité comptable.** Sans environnement, aucun total n'est fiable. |
| `idempotency_keys` | Collision inter-environnement réelle — voir §4. |
| `audit_logs` | Permet de filtrer et prouver les décisions par environnement. |

### Tables écartées, et pourquoi

| Table | Raison du refus |
|---|---|
| `wallets` | Un wallet est un **contenant de solde**, pas une opération. Lui donner un environnement impliquerait des soldes séparés sandbox/production : changement de modèle majeur, non demandé, et dangereux à moitié fait. |
| `users`, `beneficiaries`, `team_members` | Identité et configuration, aucun état financier. |
| `provider_credentials`, `kyc_verifications`, `kyc_webhook_events` | Portent **déjà** `environment`. |

La colonne n'a donc pas été ajoutée partout « pour faire propre ».

### Index créés

Trois seulement, correspondant à des accès réels (audit, réconciliation,
séries temporelles) : `(environment, created_at)` sur `quotes`,
`wallet_operations`, `ledger_entries` et `audit_logs`. Aucun index spéculatif.

---

## 2. Données historiques : reconstruire plutôt que supposer

Deux cas ont été traités **différemment**, car les confondre reviendrait à
inventer de l'information :

**Reconstructible.** `ledger_entries.operation_id` référence
`wallet_operations.id`. L'environnement d'une écriture ancienne est donc
**déductible** de son opération source. La migration effectue ce backfill par
jointure — aucune supposition.

**Non reconstructible.** Pour le reste, `DEFAULT 'production'`, comme en 0.13.
Les lignes existantes proviennent d'un système sans notion d'environnement,
dont l'usage nominal est réel. Les marquer `sandbox` déclarerait
rétroactivement « ceci n'était pas de l'argent réel » : invérifiable, et faux
dans le sens dangereux. `production` ne minimise jamais un mouvement réel.

Toutes les colonnes financières sont `NOT NULL`. Seule `audit_logs.environment`
est nullable, délibérément : un refus `ENVIRONMENT_INVALID` journalise une
demande dont la valeur **n'appartient pas à l'ENUM**. Forcer une valeur
fabriquerait l'information que le refus constate absente ; la demande brute est
conservée dans `metadata`.

---

## 3. Propagation démontrée

```text
ExecutionContext
      ↓  QuoteController::create
   Quote
      ↓  ExecutionEngine::execute  → EnvironmentGuard (409 si divergence)
WalletOperation
      ↓  WalletService::createHold / LedgerService
Transaction / Payment
      ↓  LedgerService::insertLedgerEntry
  LedgerEntry
```

L'invariant `quote == wallet_operation == transaction == ledger_entry` est
vérifié en base par requête `SELECT DISTINCT` sur la jointure du cycle : une
seule valeur doit en sortir.

Deux scénarios end-to-end (§16) le prouvent, sandbox et production, sur des
données de test contrôlées : aucun adaptateur provider n'est appelé, les
opérations restant non implémentées.

**L'antériorité fait autorité.** Pour tout objet déjà persisté, c'est la valeur
en base qui décide, jamais la configuration courante. Deux tests basculent
`PROVIDERS_ENV` *après* l'exécution : la transaction ne change pas. Un hold posé
en sandbox se capture en sandbox même si le serveur a basculé — l'environnement
est relu depuis `wallet_operations`, pas recalculé.

---

## 4. Un défaut trouvé au passage : l'idempotence traversait la frontière

La contrainte était `UNIQUE(idempotency_key, user_id)` — **sans environnement**.

```text
1. appel SANDBOX     clé K → exécuté, réponse mise en cache
2. appel PRODUCTION  clé K → collision : la réponse SANDBOX est renvoyée
                             et l'opération réelle n'est JAMAIS exécutée
```

Le client recevait un succès pour une opération de production qui n'avait pas eu
lieu. La clé est désormais scopée : `UNIQUE(idempotency_key, user_id, environment)`.
Un test le démontre — la même clé produit deux transactions distinctes, l'une
sandbox, l'autre production.

Ce point n'était pas demandé comme un correctif, seulement comme un audit (§22) ;
l'audit a révélé un défaut exploitable, il a donc été corrigé, avec test.

---

## 5. Mismatches : refus, jamais correction

| Situation | Résultat |
|---|---|
| Quote sandbox + contexte production | `409 ENVIRONMENT_MISMATCH` |
| Quote production + contexte sandbox | `409 ENVIRONMENT_MISMATCH` |
| Opération sandbox ↔ transaction production | `409 ENVIRONMENT_MISMATCH` |
| Paiement production ↔ ledger sandbox | `409 ENVIRONMENT_MISMATCH` |
| Paiement exécuté dans un autre environnement | `409 ENVIRONMENT_MISMATCH` |

La règle est centralisée dans `EnvironmentGuard` — point de passage unique.
Dupliquer la comparaison dans chaque contrôleur produirait tôt ou tard une
variante qui oublie un cas.

Aucune correction automatique : la quote n'est **ni réalignée, ni régénérée**
silencieusement dans l'autre environnement. Un test vérifie qu'après refus la
quote reste `sandbox`/`QUOTED` et qu'**aucune** transaction n'a été créée.

---

## 6. Audit (§17–§19)

`audit_logs` est **réutilisée** — aucune seconde table d'audit : deux journaux
concurrents finiraient par se contredire.

Reconstructible depuis une ligne : qui (`user_id`), quel compte (`account_id`),
quel provider, quelle opération, quel environnement (colonne dédiée), quelle
source de décision, quel `request_id`, quelle décision (`granted` / `denied` +
`error_code`).

Journalisés : `ENVIRONMENT_INVALID`, `ENVIRONMENT_NOT_ALLOWED`,
`ENVIRONMENT_MISMATCH`, `PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT`.

Deux garde-fous vérifiés par test :

- **Aucun secret** n'atteint la table (`SecretRedactor`) : `secret_key`,
  `api_token`, `webhook_secret`, `private_key` sont expurgés.
- **Le client en apprend moins que le journal** : un utilisateur non autorisé ne
  peut pas déduire du message qu'une credential de production *existe*. Le
  journal interne, lui, conserve de quoi enquêter.

L'écriture est best-effort : une panne du journal ne doit jamais empêcher un
refus d'être prononcé — la décision prime sur sa trace.

---

## 7. Réconciliation & reporting

Adaptations **minimales**, sans réécrire les moteurs :

- `ReconciliationController` filtre sur l'environnement du contexte : un écart
  de test n'apparaît plus dans un rapprochement d'argent réel.
- `DashboardController` (KPIs et séries temporelles) est scopé : plus aucun
  total n'additionne montants fictifs et montants réels.

Le frontend n'a pas été modifié (hors périmètre) ; c'est le backend qui garantit
la séparation.

---

## 8. Validation par mutation (§24)

| Mutation | Tests en échec |
|---|---|
| `transaction.environment` forcé à `production` | **3** |
| `ledger.environment` forcé à `sandbox` | **1** |
| `quote.environment` ignoré | **1** |

La 3ᵉ mutation a d'abord **survécu** : la garde n'était exercée que par appel
direct, jamais par le vrai chemin `ExecutionEngine::execute()`. Un test manquait
donc, pas la protection. Il a été ajouté (quote sandbox persistée + contexte
production via le chemin réel), et la mutation est désormais détectée.

Sources restaurées après chaque mutation (`md5sum -c` : OK).

---

## 9. Chiffres réels

| Contrôle | Résultat |
|---|---|
| Tests | **294** (baseline 276, +18) |
| Assertions | **1323** (baseline 1261) |
| Régressions | **0** |
| Tests de mutation | 3 / 3 détectées, sources restaurées |
| `sql_contract_audit` | PASS |
| `compare_schemas` | SCHEMA EQUIVALENCE PASS |
| Migration | idempotente (5 passages cumulés) |
| Opérations providers | **0/6** — `ProviderOperationNotImplemented` intact |
| Control Center | 22 providers, `with_operations = 0` |
| Route `POST /api/execute` | absente (conforme §27) |

---

## 10. Ce qui reste ouvert — état honnête

1. **`transferMultiCurrency` n'est pas relié au contexte.** Cette API n'a pas de
   `ExecutionContext` dans sa signature (elle reçoit un `TransferRequest`) ; ses
   clés d'idempotence retombent donc sur le défaut serveur. Ce n'est pas une
   incohérence — le défaut est cohérent avec l'environnement du déploiement —
   mais ce n'est pas non plus une propagation explicite. À traiter avec le
   modèle `TransferRequest`.
2. **Les anciennes écritures d'audit** (`AuthController`, `UserController`,
   `ProviderCredentialController`) n'écrivent pas `environment` et dupliquent
   toujours leur `INSERT`. Elles n'entrent pas dans le cycle financier ; leur
   unification reste un chantier distinct.
3. **`reconciliation_items` ne porte pas d'environnement.** Il est déduit par
   jointure sur `transactions`, ce qui suffit aujourd'hui, mais une ligne
   orpheline ne serait pas classable.
4. **Aucune contrainte SQL ne force l'invariant** entre tables (pas de trigger,
   pas de FK composite). L'invariant est garanti par le code et vérifié par les
   tests — pas par le moteur de base de données.
5. **Les opérations réelles des providers restent non implémentées** (0/6),
   conformément au périmètre.

---

## Roadmap backend — phases 1 à 3

Suite donnée à la propagation d'environnement. Chaque phase est validée par
PHPUnit complet, audit du contrat SQL, comparaison de schéma, test de mutation
et recherche de secrets avant d'être commitée.

### Phase 1 — Complétude du contexte

`transferMultiCurrency()` était le dernier chemin financier à résoudre son
environnement implicitement : il lisait la configuration du serveur au moment
de l'exécution, et non le contexte de la requête.

`TransferRequest` transporte désormais un `ExecutionContext` optionnel
(12ᵉ paramètre, rétrocompatible). L'environnement voyage avec la requête
jusqu'au ledger et jusqu'aux quatre appels d'idempotence. **Aucun second
resolver n'a été créé** : `ExecutionContext` reste la source unique.

État constaté et assumé : cette méthode n'a aujourd'hui **aucun appelant de
production** — ses seuls appels sont dans les tests. Le correctif la rend sûre
avant qu'un appelant n'existe, plutôt qu'après.

### Correctif HIGH découvert pendant l'audit

La migration 0.14 avait scopé `idempotency_keys`. Un **second** espace de noms
d'idempotence subsistait, non corrigé :

    wallet_operations : UNIQUE (idempotency_key)

Index global. Conséquence, reproduite par test avant correction :

1. opération **sandbox** avec la clé K → opération créée ;
2. opération **production** avec la clé K → `Duplicate entry`.

Une opération de test rendait donc définitivement impossible l'exécution de la
même clé en argent réel. La frontière d'environnement était franchie par la
contrainte elle-même : un objet sandbox produisait un effet observable, et
bloquant, sur la production.

Migration **0.15** (idempotente) : `UNIQUE (idempotency_key, environment)`.

### Phase 2 — Idempotence

Tous les index UNIQUE de la base ont été passés en revue : après 0.15, plus
aucun espace de noms d'idempotence n'ignore l'environnement.

Les **caches** ont été vérifiés séparément, comme l'exige la phase.
`ProviderRegistry` met les adaptateurs en cache par slug ; un adaptateur ne
capture ni environnement ni credential à la construction et relit
l'environnement à chaque appel. Le premier appelant ne fige donc pas
l'environnement des suivants — comportement couvert par un test.

Un **test-filet structurel** interroge `information_schema` et échoue si une
migration future réintroduit un index d'idempotence global. Sa capacité à
échouer a été prouvée en créant réellement un tel index.

### Phase 3 — Journalisation

Les événements du credential manager portaient leur environnement dans le JSON
de métadonnées uniquement : lisible, mais ni filtrable ni ventilable. Ils
alimentent désormais la **colonne** `environment`. Une valeur hors ENUM laisse
la colonne à `NULL` plutôt que d'être remplacée par une valeur inventée.

`SecretRedactor` est appliqué en défense en profondeur : aucun secret ne
transite par ces métadonnées aujourd'hui, mais un ajout futur de champ ne
pourra pas en faire fuiter un.

Les événements `auth.*` restent **délibérément sans environnement**. Une
authentification n'appartient à aucun environnement d'exécution ; lui en
attribuer un artificiellement donnerait une information fausse à quiconque
filtre le journal.

### Chiffres

| Élément | Avant | Après |
|---|---|---|
| Tests / assertions | 294 / 1323 | **310 / 1363** |
| Espaces de noms d'idempotence globaux | 1 (`wallet_operations`) | **0** |
| Chemins financiers à environnement implicite | 1 (`transferMultiCurrency`) | **0** |
| Migrations idempotentes | 16 | **17** |
| Opérations provider implémentées | 0/6 | **0/6** (inchangé, conforme §24) |

Mutations vérifiées puis restaurées (`md5sum -c`) : fallback `PROVIDERS_ENV`
dans `transferMultiCurrency`, contexte ignoré vers le ledger, redaction retirée
du journal de credentials, index d'idempotence global réintroduit. Chacune fait
échouer au moins un test.
