# NEXUS — Isolation stricte sandbox / production des credentials providers

**Commit** : `6fd6282` · **Base** : `a8f35fa` · **Périmètre** : backend uniquement

---

## Baseline (avant modification)

```text
commit      : a8f35fa
git status  : propre (hors screenshots-control/, artefacts locaux non versionnés)
tests       : OK (214 tests, 1100 assertions)
sql_contract_audit : [PASS]
compare_schemas    : SCHEMA EQUIVALENCE PASS
```

---

## Audit

### Problème exact

`ProviderConfig::credential()` appliquait la priorité suivante :

```text
1. PROVIDER_{SLUG}_{ENV}_{FIELD}     (scopé — correct)
2. PROVIDER_{SLUG}_{FIELD}           (générique — AUCUN environnement)
```

Le second terme n'appartient à aucun environnement. Une valeur renseignée
sous cette forme était donc retournée **indifféremment pour sandbox et pour
production**.

### Cause

Le repli générique avait été conçu comme une commodité de configuration. Il
introduisait une frontière poreuse : la fonction ne pouvait pas distinguer
« credential absente dans cet environnement » de « credential définie
ailleurs ».

Aggravant : `.env.example` **promouvait cette forme** pour les 9 providers
documentés (`PROVIDER_STRIPE_SECRET_KEY=`, `PROVIDER_PAWAPAY_API_TOKEN=`…).
Le chemin dangereux était donc le chemin *recommandé*, pas un cas limite.

Le docblock de la classe affirmait pourtant :
> « La séparation sandbox/production est STRICTE ».

Le code contredisait sa propre documentation.

### Flux concerné

```text
AbstractProviderAdapter::verifyWebhook()  → credential(WEBHOOK_SECRET, env)
ProviderConfig::validate()                → credential(field, env)
ProviderConfig::baseUrl()                 → credential(BASE_URL, env)
ProviderConfig::strictMode()              → credential(field, env)
```

Tous héritaient de la fuite.

### Reproduction avant correction

```text
PROVIDER_STRIPE_SECRET_KEY=sk_live_CLE_DE_PRODUCTION
  credential('stripe','SECRET_KEY','sandbox')    → 'sk_live_CLE_DE_PRODUCTION'   ← FUITE
  credential('stripe','SECRET_KEY','production') → 'sk_live_CLE_DE_PRODUCTION'

PROVIDER_PAWAPAY_API_TOKEN=token_SANDBOX_de_test
  credential('pawapay','API_TOKEN','production') → 'token_SANDBOX_de_test'       ← FUITE
```

**Impact réel** : un test « sandbox » exécuté avec une clé de production
déclenche des mouvements d'argent réels.

### Points vérifiés et jugés SAINS (aucune modification)

| Élément | Constat |
|---|---|
| `ProviderCredentialService` | Requêtes filtrées sur `user_id + provider_slug + environment`, plus un garde-fou de cohérence ligne 98. Déjà isolé. |
| Schéma SQL | `ENUM('sandbox','production')` + `UNIQUE uq_provider_creds_env(user_id, provider_slug, environment)`. Déjà contraint. **Aucune migration créée** — elle aurait été redondante. |
| `ProviderRegistry::$cache` | Indexé par slug, mais l'environnement est résolu à chaque appel par l'adapter. Pas de cache inter-environnement. |
| `ProviderCredentialController` | Valide l'environnement et refuse toute valeur hors liste. |
| Déchiffrement inutile | Corrigé au commit précédent (`publicKeyRegistry`). |

---

## Correction

**Fichiers modifiés** : `src/Providers/ProviderConfig.php`, `.env.example`
**Fichier ajouté** : `tests/ProviderEnvironmentIsolationTest.php`

| | Avant | Après |
|---|---|---|
| Résolution | scopée, **puis repli générique** | scopée **uniquement** |
| Credential absente | valeur d'un autre environnement | `null` |
| Environnement invalide | traité comme préfixe, résolution vide | `InvalidArgumentException` |
| Variable générique présente | utilisée silencieusement | `INVALID_CONFIGURATION`, variable nommée |
| `.env.example` | 30 variables génériques | paires `SANDBOX_` / `PRODUCTION_` |

---

## Isolation garantie

```text
sandbox credential     → sandbox uniquement
production credential  → production uniquement
```

Le triplet `provider + environment + credential_name` identifie une
credential et une seule. Aucune résolution implicite ne franchit la
frontière, dans un sens comme dans l'autre.

---

## Tests

`ProviderEnvironmentIsolationTest` — **18 tests, 53 assertions**

| Cas | Couverture |
|---|---|
| A | production seule → jamais servie à sandbox |
| B | sandbox seule → jamais servie à production |
| C | coexistence, ordre d'appel indifférent |
| D | absence → aucun repli (ni autre champ, ni autre environnement) |
| E | `publishable_key` / `secret_key` / `webhook_secret` isolés séparément |
| F | pas de cache partagé ; une valeur retirée disparaît réellement |
| + | frontière entre providers · alias d'environnement refusés · isolation en **base chiffrée** · chiffrement au repos |

### Validation par mutation

Un test qui ne sait pas échouer ne prouve rien. Vérifié :

```text
réintroduction du fallback générique   → 1 test échoue
injection d'un repli sandbox↔production → 4 tests échouent
fichier restauré                        → 18/18 OK
```

### Résultats

```text
tests              : 232
assertions         : 1153
régressions        : 0   (214 baseline + 18 nouveaux)
sql_contract_audit : [PASS]
compare_schemas    : SCHEMA EQUIVALENCE PASS
tsc (frontend)     : PASS
```

---

## Sécurité

| Vecteur | Vérification |
|---|---|
| Réponses API | 8 endpoints testés avec des sentinelles enregistrées : aucune occurrence |
| Exceptions | Message = nom d'environnement fourni ; aucune valeur de secret |
| Traces | Aucune valeur de secret |
| Logs | Aucun secret dans les journaux serveur |
| `validate()` | Nomme `PROVIDER_STRIPE_SECRET_KEY`, jamais sa valeur |
| Base de données | Secrets illisibles en clair (AES-256-GCM), déchiffrables par le seul service légitime |
| DOM | Inchangé (frontend non modifié) |

**Test de sécurité réel (§10)** — via l'API HTTP sur la base `nexus` :

```text
stripe / sandbox    / secret_key = SANDBOX_SECRET_TEST
stripe / production / secret_key = PRODUCTION_SECRET_TEST

resolve(sandbox)    → SANDBOX_SECRET_TEST      (jamais PRODUCTION_SECRET_TEST)
resolve(production) → PRODUCTION_SECRET_TEST   (jamais SANDBOX_SECRET_TEST)
```

Nettoyage vérifié : `nexus` et `nexus_test` → 0 utilisateur de test,
0 `provider_credentials`, 0 secret résiduel.

---

## Contrat Control Center préservé

`configured`, `reachable`, `authenticated`, `unknown` restent distincts.
Une credential absente n'est pas devenue « configurée » ; une credential
sandbox n'est pas devenue « production » ; `authenticated` reste `null`
faute d'appel authentifié implémenté. Les compteurs (22 providers,
4 schémas vérifiés, 0/6 opérations) sont inchangés.

---

## Points non résolus (état honnête)

- `PROVIDER_{SLUG}_ENV` reste global au provider : basculer sandbox↔production
  se fait par variable d'environnement, pas encore par requête.
- Les opérations métier des adapters restent non implémentées (`0/6`).
- Le health check demeure une sonde TCP non authentifiée.
- `BusinessService::resolveBusinessUserId` renvoie toujours 400 au lieu de 403
  pour un acteur non-business (hors périmètre de cette phase).
