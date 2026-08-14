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

---

## Phase 4 — EnvironmentGuard : intégrité du cycle financier

Invariant vérifié : `Quote → WalletOperation → Transaction/Payment →
LedgerEntry` portent exactement le même environnement sur un même cycle. Une
valeur déjà persistée fait autorité ; la configuration courante du serveur ne
peut jamais la remplacer. Toute divergence est terminale : **409
`ENVIRONMENT_MISMATCH`**, sans repli ni correction automatique.

### Un seul guard

Il existait **deux** mécanismes de comparaison : `EnvironmentGuard` (central,
audité) et une vérification réimplémentée à la main dans
`PaymentController::execute`. Même verdict, mais sans trace d'audit et avec un
message divergent — deux implémentations d'une même règle de sécurité finissent
par diverger. `PaymentController` utilise désormais le guard central. **Aucun
troisième guard n'a été créé.** Nombre de politiques après refactor : **1**.

### Découvertes

| Sévérité | Constat | Correctif |
|---|---|---|
| **HIGH** | `captureHold()` / `releaseHold()` lisaient l'environnement de l'opération pour scoper l'idempotence mais ne le **comparaient** jamais au contexte : un hold sandbox pouvait être capturé sous un contexte production. | L'environnement persisté fait autorité, dans les deux sens. |
| **MEDIUM** | Les endpoints hold enveloppaient tout dans `catch (\Throwable)` → `Response::error(…, 400)`. Un `ENVIRONMENT_MISMATCH` était donc rendu au client comme une requête malformée, masquant un conflit d'environnement. | `HttpException` propagée avec son statut et son code. |

### Atomicité du refus

Le guard s'exécute **avant** toute mutation financière. Après un refus :
aucune transaction, aucun payment, aucune écriture ledger, aucun hold capturé,
aucun changement de solde, aucune modification de quote — vérifié par
comparaison d'un instantané de la base avant/après chaque 409.

Nuance relevée pendant le test de mutation : dans `ExecutionEngine`, déplacer
le guard juste avant `commit()` ne casse pas l'atomicité, la transaction SQL
annulant les écritures. Le risque réel est sur le chemin **paiement**, où le
passage en `executing` a lieu hors transaction : la mutation correspondante
laisse un paiement bloqué dans cet état, et le test l'attrape.

### Données historiques

Aucune divergence existante (`ledger↔operation`, `transaction↔quote`,
`payment↔transaction` = 0). **Aucune migration, aucune donnée réécrite.**
Conformément à §12, aucune contrainte SQL n'a été ajoutée : l'invariant relie
des tables via des chaînes de jointure optionnelles qu'une FK ne peut pas
exprimer sans dénormaliser. Il reste garanti par le code, les tests et une
requête-filet qui échoue à la moindre divergence.

### Chiffres

| Élément | Avant | Après |
|---|---|---|
| Tests / assertions | 310 / 1363 | **325 / 1422** |
| Politiques de comparaison d'environnement | 2 | **1** |
| Chemins financiers gardés | 1 (quote) | **4** (quote, payment, capture, release) |
| Tests HTTP réels | 0 sur ce chemin | **6** |
| Mutations détectées | — | **6/6** |
| Opérations provider | 0/6 | **0/6** (vérifié en HTTP réel) |

---

## Boucle de résolution continue — boucles 1 à 6

Reprise après réinitialisation complète de l'environnement d'exécution (PHP,
MariaDB et le répertoire de données avaient disparu ; seul le dépôt a
survécu). Toolchain reconstruite, migrations rejouées depuis le manifeste,
baseline **re-vérifiée depuis zéro** : 325 tests / 1422 assertions, conforme
au rapport précédent. Seule divergence constatée : le bit exécutable de deux
scripts (755 → 644), sans changement de contenu — restauré.

### Découvertes et corrections

| # | Sévérité | Constat | État |
|---|---|---|---|
| 1 | **CRITICAL** | `createHold()` ne vérifiait pas la propriété du wallet. `wallet_id` venant du client, un utilisateur authentifié pouvait geler les fonds d'autrui. **Exploitation reproduite** : solde disponible de la victime 1000 → 500. | Corrigé |
| 2 | **HIGH** | `WalletController::hold` ne construisait aucun `ExecutionContext` : l'environnement du hold venait de la configuration serveur. | Corrigé |
| 3 | **HIGH** | `PaymentController::execute` lisait le paiement sans verrou puis écrivait `executing` sans condition : deux requêtes concurrentes exécutaient **deux fois** le même ordre approuvé. | Corrigé |
| 4 | **MEDIUM→HIGH** | `SecretRedactor::redactArray()` ne descendait pas dans les tableaux : tout secret imbriqué (réponse provider, corps de webhook) traversait la redaction intact. | Corrigé |
| 5 | MEDIUM | `approve`, `reject` et la transition générique présentaient le même motif lecture-puis-écriture. | Corrigé |
| 6 | MEDIUM | Aucun test ne verrouillait l'absence de secret dans les réponses HTTP, ni l'isolation des quotes, ni l'honnêteté des 6 opérations provider. | Comblé |

### Faux positifs écartés (vérifiés, non corrigés)

`TransferController`, `NotificationController` et `ReconciliationController`
construisent leur clause `WHERE` dynamiquement : l'inspection montre que le
filtre `user_id` y est toujours présent. Les webhooks KYC étaient déjà
couverts (signature, rejeu, isolation d'environnement, audit sans secret) —
23 tests existants que mon premier `grep` avait manqués.

### Matrice de mutations (phase 20)

Six classes de protection neutralisées une à une, chacune restaurée et
vérifiée par `md5sum` :

| Mutation | Tests en échec |
|---|---|
| Environnement ignoré (guard neutralisé) | 12 |
| Autorisation production supprimée | 4 |
| Idempotence sans environnement | 3 |
| Contrôle tenant supprimé | 1 |
| Redaction récursive supprimée | 1 |
| Faux succès d'un adaptateur provider | 2 |

### Phase 15 — premier provider réel : BLOCKED

`PROVIDER_STRIPE_{SANDBOX,PRODUCTION}_API_KEY` existent mais sont **vides**,
et aucune credential n'est en base. Implémenter une opération réelle
supposerait d'inventer une clé ou de simuler la réponse du provider : les deux
sont exclus. Le contrat d'adaptateur, le resolver, la garde d'environnement et
les tests d'erreur sont en place et attendent une credential réelle.

**22 providers / 0 opération implémentée** — vérifié en HTTP réel, et
désormais verrouillé par 24 tests qui échoueraient si un adaptateur se mettait
à répondre sans implémentation.

### Chiffres

| Élément | Début de boucle | Fin |
|---|---|---|
| Tests / assertions | 325 / 1422 | **367 / 1613** |
| CRITICAL ouverts | 1 (non détecté) | **0** |
| HIGH ouverts | 3 (non détectés) | **0** |
| Chemins financiers sans contexte | 1 | **0** |
| Courses critiques sur les statuts | 4 | **0** |

---

## Boucles 7 à 9 — privilège, reprise, divulgation

Trois défauts qu'aucun test ne couvrait, chacun trouvé en cherchant autre
chose. Le fil conducteur : **un mécanisme de sécurité qui repose sur une
valeur contrôlée par le client, ou qu'un fourre-tout masque, ne protège
rien.**

### Boucle 7 — `account_type` n'est pas un privilège (CRITICAL)

L'administration des credentials providers et le Control Center étaient
gardés par `account_type === 'business'`. Or ce champ est **choisi librement
par l'utilisateur au moment de l'inscription**.

Exploitation reproduite en HTTP réel avant correctif :

```
POST /register  { account_type: "business" }                    -> 200 + jeton
PUT  /providers/stripe/credentials
     { environment: "production", secret_key: "sk_live_…" }     -> 200 OK
```

N'importe qui pouvait injecter une credential de **production** et lire l'état
complet de l'infrastructure. La cause est une confusion de deux notions :

| Champ | Répond à la question |
|---|---|
| `account_type` | **qui est le client** (personal / business) |
| `platform_role` | **qui exploite Nexus** (user … superadmin) |

Un client business est un client : il n'hérite d'aucun privilège d'exploitant.

Correctif : colonne `users.platform_role` (migration 0.16, aucun compte
promu), et `PlatformRole` comme autorité unique — deny-by-default, capacités
granulaires (`credentials`, `operations`, `maintenance`, `superadmin`), rôle
inconnu ramené à `user`, capacité inconnue refusée. Les rôles internes
déclarés mais non implémentés se comportent exactement comme `user` : un rôle
n'accorde jamais un pouvoir qui n'existe pas encore.

**Aucun chemin d'auto-promotion** : la promotion se fait en base. Un test
vérifie qu'`AuthController` et `UserController` n'écrivent jamais ce champ.

Piège rencontré : sans ajouter `platform_role` au `SELECT` d'`AuthMiddleware`,
la garde voyait toujours `user` et refusait **même un superadmin**.

### Boucle 8 — un paiement interrompu restait bloqué à vie (HIGH)

`PaymentController::execute` réserve le paiement (`executing`) hors
transaction SQL puis lance la saga. Le `catch` couvre les exceptions, pas un
arrêt brutal du processus. Le paiement devenait alors **définitivement**
inexécutable (`execute` exige `approved`), inannulable (`cancel` part de
`draft`), et gonflait les payables pour toujours.

La reprise est sûre parce que la clé d'idempotence est **déterministe**
(`payment:{id}:execute`) : `idempotency_keys` est le journal d'exécution de la
saga. L'issue réelle se **lit**, elle ne se devine pas.

| Journal | Fait établi | Statut |
|---|---|---|
| `completed` | l'argent est parti | `completed` |
| `error` | échec propre | `failed` |
| `processing` | **issue inconnue** | on ne touche à rien |
| aucune clé | saga jamais démarrée | `approved` |

Aucune opération financière n'est rejouée : le service réconcilie un statut
avec un fait déjà écrit. Le cas `processing` est le cœur du sujet — c'est là
qu'un balayage naïf trancherait le sort d'un transfert peut-être vivant. Face
à l'incertitude, le service **s'abstient**, et l'anomalie reste visible plutôt
que faussement résolue.

**BLOCKED (non simulé)** : une saga `processing` depuis très longtemps est
morte en vol. Statuer exige d'interroger le provider — l'argent est-il parti ?
Les opérations métier des adaptateurs n'étant pas implémentées, ce cas est
signalé (`requires_provider_reconciliation`), jamais deviné.

Surface d'exploitation, avec **voir ≠ agir** :

| Route | Capacité | Effet |
|---|---|---|
| `GET /control/maintenance/stuck-payments` | `operations` | diagnostic, lecture seule |
| `POST /control/maintenance/recover-payments` | `maintenance` | écriture, `confirm: true` requis, plancher 300 s |

### Boucle 9 — le fourre-tout `catch → 400` (HIGH, puis CRITICAL)

Cinq sites renvoyaient au client `$e->getMessage()` d'une exception non
maîtrisée, soit sur erreur PDO : `SQLSTATE[42S22]: … Unknown column 'x'`.
Le SGBD, les tables et les colonnes, offerts sur les routes de hold et de
solde. Et un `400` faisait porter au client la faute d'une panne serveur.

**En corrigeant, un défaut plus grave est apparu** : le `400` uniforme
masquait un oracle. Une fois les statuts réels visibles —

```
wallet inexistant  -> 500        wallet d'autrui -> 404 WALLET_NOT_FOUND
```

— alors que le contrôle de propriété répond 404 **précisément** pour ne pas
confirmer l'existence du wallet visé. La différence de réponse annulait cette
précaution : comparer les deux énumérait les wallets existants. Les deux cas
sont désormais strictement identiques.

Corollaire : des refus légitimes remontaient en 500. `HttpException` étendant
`RuntimeException`, ils ont été typés sans casser les `catch` existants —
`422 INVALID_WALLET_ID`, `CURRENCY_MISMATCH`, `CURRENCY_NOT_SUPPORTED`,
`INSUFFICIENT_AVAILABLE_BALANCE`.

Un test verrouille l'ordre des blocs : `HttpException` **avant** `Throwable`.
Les inverser transformerait chaque refus métier en erreur générique sans
qu'aucun test fonctionnel ne bronche.

### Deux mutations ont survécu — et ont révélé de vrais trous de test

C'est le résultat le plus utile de ces trois boucles.

1. **Filtre d'ancienneté supprimé** : aucun test ne tombait. Le cas « paiement
   récent » utilisait une saga `processing`, dont la protection masquait le
   filtre d'âge. Test réécrit pour isoler l'ancienneté **seule**.
2. **Oracle réintroduit** : le test d'isolation acceptait n'importe quelle
   exception. Il compare désormais **statut et code** entre les deux cas.

Un test vert ne prouve rien tant qu'on n'a pas vérifié qu'il sait échouer.

### Chiffres

| Élément | Boucle 7 | Fin boucle 9 |
|---|---|---|
| Tests / assertions | 367 / 1613 | **389 / 1664** |
| CRITICAL ouverts | 2 (non détectés) | **0** |
| HIGH ouverts | 2 (non détectés) | **0** |
| Privilège fondé sur une valeur client | 2 surfaces | **0** |
| États financiers terminaux sans reprise | 1 | **0** |
| Fuites de structure interne | 5 sites | **0** |
