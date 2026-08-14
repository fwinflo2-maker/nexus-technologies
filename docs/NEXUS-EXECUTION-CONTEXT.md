# NEXUS — Contexte d'exécution et résolution de l'environnement

**Commit** : `bdf683e` · **Base** : `23d7c28` · **Périmètre** : backend uniquement

---

## Baseline

```text
commit      : 23d7c28
git status  : propre (hors screenshots-control/, artefacts locaux)
tests avant : OK (232 tests, 1153 assertions)
```

---

## Règle fondamentale posée

> **L'environnement n'est jamais déduit d'une credential disponible.**

```text
contexte → environnement → credential      ← sens imposé
credential disponible → environnement      ← INTERDIT
```

La phase précédente avait sécurisé `credential(provider, environment, field)`.
Mais `environment` restait décidé par une variable globale. Sans inversion
explicite, la seule présence d'une clé `sk_live_…` aurait suffi à faire
basculer une opération de test en production.

Désormais : **la politique décide, puis la credential de l'environnement
décidé est exigée.** Absente ⇒ échec explicite, jamais de repli.

---

## Chaîne de résolution

```text
Request
  ↓
Utilisateur authentifié      (AuthMiddleware)
  ↓
Contexte de compte           (acteur / sujet, account_type)
  ↓
Environnement d'exécution    (ExecutionContext — politique serveur)
  ↓
Provider                     (ProviderResolver)
  ↓
Credential                   (scopée, exigée pour CET environnement)
  ↓
Provider Adapter
```

### Politique d'arbitrage (ordre strict)

| # | Condition | Résultat | `environment_source` |
|---|---|---|---|
| 1 | `APP_ENV=production` | **production imposée** ; demande divergente ⇒ `403 ENVIRONMENT_NOT_ALLOWED` | `server_forced_production` |
| 2 | Demande client (`X-Nexus-Environment` ou `environment`) | validée serveur ; valeur inconnue ⇒ `400 ENVIRONMENT_INVALID` | `client_request` |
| 3 | Aucune demande | défaut `PROVIDERS_ENV` (sandbox sauf config) | `server_default` |

**Le client demande, le serveur décide.** Une valeur invalide n'est jamais
remplacée en silence par un défaut : le client croirait sa demande honorée.

---

## Composants ajoutés

| Fichier | Rôle |
|---|---|
| `src/Execution/ExecutionEnvironment.php` | Enum `sandbox\|production`. Refuse `staging`, `test`, `prod`, `live`, `dev`. `isRealMoney()`. |
| `src/Execution/ExecutionContext.php` | Résolution + arbitrage + traçabilité (`toArray()` sans secret). |
| `src/Execution/ProviderResolver.php` | Exige la credential de l'environnement retenu ; `usableSlugs()` pour le routing. |

`ProviderResolver` consulte les **deux** sources (variables scopées, base
chiffrée) et **ne déchiffre jamais** pour un simple test de présence.

---

## SQL — traçabilité des mouvements d'argent

`transactions` et `payments` ne portaient **aucune** colonne `environment`,
alors que `kyc_verifications`, `kyc_webhook_events` et `provider_credentials`
en ont une. Une transaction sandbox était donc **indistinguable en base** d'un
mouvement réel, et tout total agrégé mélangeait argent fictif et argent réel.

Migration `2026_08_14_execution_environment.sql` :

```sql
environment ENUM('sandbox','production') NOT NULL DEFAULT 'production'
+ INDEX (environment, created_at)
```

**Défaut `production`, et non `sandbox`** : les lignes existantes proviennent
d'un système sans notion d'environnement. Les marquer `sandbox` reviendrait à
déclarer rétroactivement « ceci n'était pas de l'argent réel » — affirmation
invérifiable. `production` est l'hypothèse prudente : elle ne minimise jamais
un mouvement réel.

Discipline respectée : migration idempotente (vérifiée 3×) → manifeste →
`full_schema.sql` régénéré → `compare_schemas` PASS → `sql_contract_audit` PASS.

---

## RBAC — correction 400 → 403

`BusinessService::resolveBusinessUserId()` :

| | Avant | Après |
|---|---|---|
| Acteur sans espace Business | `400 BUSINESS_ID_REQUIRED` | `403 FORBIDDEN_NO_BUSINESS_CONTEXT` |

Un acteur sans espace Business n'a pas commis d'erreur de saisie : il n'a pas
l'autorisation. Un 400 laissait croire qu'ajouter un paramètre suffirait.

### Précision issue de la vérification

Je soupçonnais une fuite inter-tenant (`business_id` renvoyé tel quel). **Test
sur 7 surfaces** avec un compte personnel ciblant l'espace d'un tiers :

```text
beneficiaries · business/overview · business/treasury · business/analytics
payments · team · reconciliation      → tous 403 FORBIDDEN_ROLE
```

`requireRole()` bloquait déjà. **Il n'y a jamais eu de fuite de données** —
uniquement un code de statut trompeur dans le cas « aucun espace ciblé ».

---

## Tests

**28 nouveaux** — `ExecutionContextTest` (21), `BusinessAccessControlTest` (7)

Couverture : défaut serveur · demande via en-tête et via corps · refus des
alias · production forcée · refus de sandbox en production · **non-inversion
credential → environnement dans les deux sens** · filtrage du routing par
environnement · auditabilité sans secret · 403 pour acteur sans espace ·
cross-tenant · rôles d'équipe actifs/désactivés.

### Validation par mutation

| Mutation injectée | Détection |
|---|---|
| Repli inter-environnement dans le resolver | **3 tests échouent** |
| Garde « production » neutralisée | **1 test échoue** |
| Valeur invalide retombant sur le défaut | **1 test échoue** |

Fichiers restaurés, suite re-vérifiée verte après chaque mutation.

### Résultats

```text
tests              : 260
assertions         : 1223
régressions        : 0   (232 baseline + 28)
sql_contract_audit : [PASS]
compare_schemas    : SCHEMA EQUIVALENCE PASS
migration          : idempotente (3 passages)
frontend           : non modifié
```

---

## Sécurité

- `ExecutionContext::toArray()` ne contient que des identifiants et la
  décision prise ; vérifié sans secret par test dédié.
- Le message `PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT` nomme le provider et
  l'environnement, jamais une valeur de credential.
- `hasCredentialFor()` utilise `findRow()` — **aucun déchiffrement** pour un
  test de présence.
- Bases `nexus` et `nexus_test` vérifiées propres après recette.

---

## Ce qui reste ouvert (état honnête)

- **`ExecutionContext` n'est pas encore câblé dans les contrôleurs.** Les
  briques sont posées et testées, mais `ExecutionEngine` ne le reçoit pas
  encore : la colonne `environment` des transactions est donc alimentée par
  son défaut. Ce câblage appartient à la phase Execute.
- Aucune autorisation par compte : tout compte authentifié peut demander
  `production` sur un déploiement non-production. Le verrou par compte
  (colonne `users.allowed_environment` ou politique dédiée) reste à décider.
- Opérations métier des adapters toujours non implémentées (`0/6`).
- Health check toujours une sonde TCP non authentifiée.
