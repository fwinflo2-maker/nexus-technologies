# Autorisation d'exécuter en production

Phase : *Production Authorization Policy + câblage `ExecutionContext`*
Base de départ : `3d9c870` — 260 tests / 1223 assertions.
État à la fin de la phase : **276 tests / 1261 assertions**, `sql_contract_audit` PASS, `compare_schemas` PASS.

---

## 1. Le problème traité

La phase précédente savait **décider** d'un environnement. Elle ne savait pas
répondre à la question suivante :

> Ce compte a-t-il le **droit** d'exécuter en production ?

Le réflexe naturel — et l'erreur — consiste à répondre en regardant les
credentials :

```
une clé sk_live_… est configurée  ⇒  exécutons en production
```

Ce raisonnement inverse la logique. La clé est un **moyen technique**, pas une
**permission**. La confondre avec un droit signifie que la personne qui
renseigne une clé dans un formulaire accorde, sans le savoir, un droit de
déplacer de l'argent réel.

D'où la règle structurante de cette phase :

```
production_allowed               ← AUTORISATION  (ProductionAuthorizationPolicy)
production_credential_available  ← CAPACITÉ      (ProviderResolver)
```

Deux conditions **indépendantes**, toutes deux nécessaires, évaluées **dans cet
ordre**. L'autorisation est tranchée *avant* toute lecture de credential : la
présence d'une clé ne peut donc, à aucun instant, influencer la décision.

L'ordre a aussi une conséquence sur ce qui fuit : un compte non autorisé reçoit
`403` sans jamais apprendre si la plateforme détient, ou non, des clés de
production.

---

## 2. Le flux réel

```
HTTP Request
   └─ Authentication            (AuthMiddleware)
       └─ AccountContext        qui agit, pour quel compte
           └─ ExecutionContextResolver   quel environnement    → 400 si invalide
               └─ ProductionAuthorizationPolicy   a-t-il le droit ? → 403 si non
                   └─ ExecutionContext  environnement figé (readonly)
                       └─ ProviderResolver   credential de CET environnement → 409 si absente
                           └─ ProviderAdapter
```

Ce flux est réellement celui du code : `TransferController::execute` et
`PaymentController::{create,execute}` construisent le contexte en tête de
requête et le transportent jusqu'à l'écriture en base.

**Aucun second resolver n'a été créé.** `ProviderResolver::resolve()` prenait
déjà un `ExecutionContext` ; il n'a pas été modifié. `ExecutionContext` a été
étendu, pas dupliqué.

---

## 3. `AccountContext` : une abstraction, pas trois classes

`AccountContext` porte `accountId`, `accountType`, `actorId`, `permissions`.

`actorId` et `accountId` sont **distincts** : un membre d'équipe (acteur) agit
sur l'espace d'une entreprise (compte). Les confondre rendrait impossible
l'audit « qui a fait quoi, sur quel espace », et c'est exactement la distinction
dont Connect aura besoin — un compte opéré pour le compte d'un tiers.

Personal, Business et Connect se distinguent donc par les **valeurs** portées,
pas par trois implémentations concurrentes. La policy lit ces valeurs ; elle
n'interroge pas un type concret.

---

## 4. Ce que la policy autorise aujourd'hui — et ce qu'elle refuse d'inventer

Deny by default. En l'absence d'information : **refus**.

| Situation | Sandbox | Production |
|---|---|---|
| Déploiement standard, aucune configuration | autorisé | **refusé** |
| `NEXUS_PRODUCTION_ALLOWED=true` | autorisé | autorisé |
| `NEXUS_PRODUCTION_ALLOWED_ACCOUNTS=10,42`, compte 42 | autorisé | autorisé |
| idem, compte 43 | autorisé | **refusé** |
| Liste malformée (`*`, `all`, vide) | autorisé | **refusé** |
| `APP_ENV=production` | **refusé** | autorisé |
| Clé `sk_live_…` présente, rien d'autre | autorisé | **refusé** |

Deux choix méritent d'être explicités.

**Le type de compte n'accorde rien.** Faire de `account_type = 'business'` un
droit d'accès à la production aurait été une règle métier *inventée* : rien
dans la spécification ne dit qu'une entreprise est autorisée à déplacer de
l'argent réel du seul fait de son type. La policy expose le point d'extension
et laisse la vraie règle (plan tarifaire, statut KYB validé, colonne dédiée)
être branchée sans réécriture.

**La sandbox est refusée sur un déploiement de production.** Laisser un client
demander « sandbox » sur une plateforme réelle reviendrait à lui laisser
choisir un mode dégradé.

`users.allowed_environment` n'a **pas** été utilisé : l'environnement est une
propriété du contexte d'exécution, l'autorisation une propriété du compte.
Aucun mécanisme concurrent n'a été ajouté — la policy est le point de passage
unique, appelé depuis `ExecutionContext::authorize()`.

---

## 5. Jamais de repli entre environnements

Un refus est **terminal**, dans les deux sens :

- production refusée → **jamais** rétrogradée en sandbox ;
- sandbox refusée → **jamais** promue en production ;
- production autorisée mais credential production absente → `409`, **jamais**
  exécutée avec la clé sandbox.

Un repli silencieux transformerait un « non » en « oui ailleurs » : ce n'est pas
une décision de sécurité, c'en est le contournement.

`ExecutionContext::environment` est `readonly` : l'environnement est **immuable
une fois résolu**. `forOperation(provider, operation)` retourne une nouvelle
instance qui **recopie** l'environnement déjà arbitré — le contexte se précise,
il ne se renégocie jamais. Un test le prouve en modifiant la configuration
*après* résolution : le contexte n'en tient pas compte.

---

## 6. Un défaut corrigé au passage : le canal ignoré

Le test « header / body / query aboutissent à la même décision » a échoué au
premier essai. Le canal **query** n'était pas lu : `?environment=production`
était silencieusement ignoré et la requête s'exécutait en sandbox.

Ce n'était pas exploitable en l'état, mais c'était un piège : l'intention du
client divergeait de l'exécution réelle sans le moindre signal, et le jour où
ce canal aurait été honoré ailleurs, il devenait une porte dérobée. Les trois
canaux sont désormais lus, arbitrés puis soumis à la **même** policy. L'ordre
de priorité départage une requête cohérente ; il n'accorde aucun privilège.

---

## 7. `environment` en base : alimenté par le contexte

`transactions.environment` et `payments.environment` sont désormais écrits
depuis le contexte, et non plus laissés au `DEFAULT 'production'` du schéma —
lequel ne subsiste que pour les lignes **historiques**, antérieures à la
colonne.

Vérification bout-en-bout exécutée : une saga lancée avec un contexte sandbox
écrit `environment = sandbox`, alors que le DEFAULT SQL vaut `production`.

Un paiement porte l'environnement de sa **création**. L'exécuter dans un autre
environnement est refusé (`409 ENVIRONMENT_MISMATCH`) : sans cette règle, une
revue faite en sandbox pourrait valider un mouvement d'argent réel.

Le seeding de démonstration (`AuthController`) n'est pas concerné : il est déjà
verrouillé par `DemoMode::seedingAllowed()`.

---

## 8. Codes d'erreur

| Code | HTTP | Signification |
|---|---|---|
| `ENVIRONMENT_INVALID` | 400 | Valeur inconnue (`prod`, `live`, `staging`…) |
| `ENVIRONMENT_NOT_ALLOWED` | 403 | Environnement refusé **pour ce compte** |
| `ENVIRONMENT_MISMATCH` | 409 | Exécution dans un autre environnement que la création |
| `PROVIDER_UNKNOWN` | 404 | Provider inconnu |
| `PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT` | 409 | Autorisé, mais credential absente |

Le `403 FORBIDDEN_NO_BUSINESS_CONTEXT` **ne régresse pas en 400** : vérifié par
requête HTTP réelle.

---

## 9. Validation par mutation

Un test qui ne sait pas échouer ne prouve rien. Trois mutations ont été
injectées, puis les sources restaurées (`md5sum -c` : OK).

| Mutation | Tests en échec |
|---|---|
| « production autorisée par défaut » (`return true` au lieu du refus) | **7** |
| « credential présente = autorisation » | **2** |
| « production refusée → repli silencieux sandbox » | **2** |

---

## 10. Vérifications exécutées

| Contrôle | Résultat |
|---|---|
| Suite PHPUnit | **276 tests / 1261 assertions**, 0 échec |
| Tests de policy | 9 |
| Tests de sécurité | 7 |
| `sql_contract_audit.php` | PASS |
| `compare_schemas.sh` | SCHEMA EQUIVALENCE PASS |
| `403 FORBIDDEN_NO_BUSINESS_CONTEXT` (HTTP réel) | non régressé |
| header / body / query, production non autorisée (HTTP réel) | 403 identique sur les 3 |
| Control Center `operations_enabled` | **0 / 22 providers — 0/6 opérations** |
| Opérations providers | `ProviderOperationNotImplemented` intact |

---

## 11. Ce qui reste ouvert — état honnête

1. **La vraie règle d'autorisation métier n'existe pas.** L'accès à la
   production se configure aujourd'hui côté serveur (variable d'environnement).
   Aucune règle produit (plan, KYB validé, revue manuelle) n'a été inventée : le
   point d'extension est prêt, la décision appartient au métier.
2. **Pas d'interface d'administration** pour accorder ou révoquer l'accès à la
   production ; cela suppose au préalable un rôle « opérateur Nexus », qui
   n'existe pas encore (défaut n° 3 connu).
3. **L'autorisation n'est pas journalisée dans `audit_logs`.** `toArray()`
   fournit la charge utile complète (compte, provider, opération,
   environnement, source, `request_id`, sans secret) ; son écriture reste à
   brancher.
4. **`quotes`, `wallet_operations` et `ledger_entries` n'ont pas de colonne
   `environment`.** Une quote produite en sandbox n'est donc pas formellement
   liée à son environnement — l'exécution l'est, la cotation ne l'est pas
   encore.
5. **Les opérations réelles des providers restent non implémentées** (0/6),
   conformément au périmètre. Rien dans cette phase ne rapproche d'un faux
   succès.
