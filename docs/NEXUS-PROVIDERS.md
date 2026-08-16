# NEXUS — ARCHITECTURE PROVIDER CREDENTIALS & CONFIGURATION

> **Date :** 2026-08-14 · Préparation sécurisée des intégrations providers.
> **Aucune vraie clé API dans ce dépôt.** Aucune transaction réelle chez les
> providers n'est encore implémentée. Ce document décrit les emplacements et
> mécanismes permettant d'introduire les credentials ultérieurement.

---

## 1. Principe

```text
Nexus Core
    ↓
Provider Registry
    ↓
Provider Adapter
    ↓
Provider Configuration
    ↓
Provider Credentials (environnement / secret manager)
    ↓
External Provider API
```

Le **Nexus Core** (Capability / Quote / Routing / Execution) ne contient et ne
connaît **aucune clé** : Stripe, pawaPay, Onafriq, Thunes, Wise, Nium… sont
isolés derrière leur adaptateur. Le Core est provider-agnostic.

## 2. Composants (namespace `Nexus\Providers`)

| Fichier | Rôle |
|---|---|
| `ProviderAdapter.php` | Interface commune : `getCapabilities`, `validateConfiguration`, `healthCheck`, `getQuote`, `createPayment`, `getPaymentStatus`, `cancelPayment`, `verifyWebhook`, `getBalance` |
| `AbstractProviderAdapter.php` | Implémentation commune (validation, health check, sonde TCP, webhook) ; opérations non câblées → `ProviderOperationNotImplemented` |
| `StripeAdapter.php` / `PawaPayAdapter.php` | Exemples concrets (aucune clé en dur) |
| `ConfigDrivenProviderAdapter.php` | Adaptateur générique piloté par le catalogue |
| `ProviderRegistry.php` | Point d'entrée unique : résout l'adaptateur, le statut, la disponibilité pour le routing |
| `ProviderConfig.php` | Résolution des variables d'environnement par (slug, environnement) |
| `ProviderStatus.php` | Énumération des statuts |
| `SecretRedactor.php` | Redaction des secrets (logs/audits) |
| `WebhookVerifier.php` | Vérification HMAC des signatures de webhooks |

## 3. Types de credentials gérés

API key, public key, secret key, client ID, client secret, access token,
refresh token, webhook secret, signing secret, account ID, merchant ID,
wallet ID, environment — le schéma est **défini par provider** dans le
`ProviderCatalog` (extensible sans toucher au Core).

## 4. Environnements (séparation stricte)

- `PROVIDER_{SLUG}_ENV = sandbox | production`
- Credentials scopés : `PROVIDER_{SLUG}_{SANDBOX}_{CHAMP}` et
  `PROVIDER_{SLUG}_{PRODUCTION}_{CHAMP}` sont **totalement séparés**.
  Une clé sandbox ne peut jamais être lue comme clé production (et inversement).

## 5. Statuts

| Statut | Signification |
|---|---|
| `configured` | credentials requis présents, format valide (**≠ sain**) |
| `missing_credentials` | champs requis absents |
| `invalid_configuration` | slug inconnu / URL invalide |
| `disabled` | `*_ENABLED` absent ou `false` |
| `healthy` | configuré + connectivité vérifiée (sonde TCP) |
| `degraded` | réservé (latence/erreurs partielles) |
| `unavailable` | configuré mais injoignable |

## 6. Mode démo vs mode strict (routing)

- **Mode démo** (aucun provider configuré) : tous les providers du catalogue
  sont éligibles — comportement historique, réservé au développement.
- **Mode strict** (≥ 1 provider configuré, ou `PROVIDERS_STRICT_MODE=true`) :
  le `CapabilityEngine` **exclut** les providers `disabled`, `missing_credentials`,
  `invalid_configuration` ou `unavailable`. Un provider mal configuré ne casse
  jamais le Core.

## 7. Sécurité

- `.env` **jamais** commité (`.gitignore`).
- Secrets **jamais** en clair dans MySQL : la table `provider_credentials`
  existante stocke en AES-256-GCM (`APP_KEY`), et l'approche recommandée pour
  la v1 est l'**environnement / secret manager**.
- Secrets **jamais** renvoyés par l'API, jamais affichés, jamais loggés
  (`SecretRedactor`).
- Public/private distingués : par défaut **backend-only** ; une public key
  n'est exposée au frontend que si le provider le permet explicitement.
- Rotation = changement de variable d'environnement + redémarrage (aucun code).

## 8. Webhooks (préparé, non exposé)

Un webhook entrant devra être : authentifié, signé, vérifié (HMAC temps
constant), idempotent, journalisé, associé au provider ET à l'environnement.
`WebhookVerifier` fournit la vérification cryptographique ; aucun endpoint HTTP
n'est activé tant qu'un provider n'est pas intégré.

## 8bis. Rotation des secrets (§3)

**Rotation d'un credential provider (approche env / secret manager) :**

1. Ajouter la **nouvelle** valeur en parallèle de l'ancienne (par ex. une
   variable temporaire) ;
2. Basculer la variable canonique sur la nouvelle valeur ;
3. Redémarrer l'API (les adaptateurs relisent l'environnement — aucun
   changement de code) ;
4. Vérifier `GET /api/providers/status` (statut `configured`, pas d'erreur) ;
5. Supprimer l'ancienne valeur.

**Rotation de la clé de chiffrement `APP_KEY` (données chiffrées en DB) :**

`Crypto` chiffre les données sensibles (IBAN, références bénéficiaires,
credentials DB) en AES-256-GCM avec une clé dérivée de `APP_KEY`. Changer
`APP_KEY` rend les anciens ciphertexts indéchiffrables. Procédure :

1. Démarrer avec l'**ancienne** `APP_KEY` ;
2. Déchiffrer toutes les valeurs sensibles (bénéficiaires, payment_accounts,
   provider_credentials) ;
3. Basculer sur la **nouvelle** `APP_KEY` ;
4. Ré-chiffrer l'ensemble des valeurs ;
5. Supprimer l'ancienne clé.

> ⚠️ Cette procédure est **documentée mais pas encore scriptée** : elle sera
> automatisée (script de ré-encryption) lorsque des credentials réels seront
> stockés en DB. Pour la v1, privilégier l'environnement / secret manager, où
> la rotation = changement de variable + redémarrage.

## 9. Intégration au pipeline

`CapabilityEngine::findEligible()` applique désormais un **Filtre 3** :
`ProviderRegistry::isAvailableForRouting($slug)`. C'est le seul point de
branchement — le Quote Engine et le Routing Engine héritent automatiquement du
filtre, sans connaissance des providers.

## 10. Vérifications effectuées

- 19 tests PHPUnit dédiés (`ProviderRegistryTest`) : configuré, non configuré,
  credentials manquants/invalides, sandbox, production, disabled, dégradé/santé,
  unavailable, health check, mode strict, fuite de secrets, webhooks.
- Suite complète : 151 tests, ~857 assertions, OK.
- Mode strict vérifié en API : avec pawaPay seul configuré, une quote
  EUR→XAF ne retourne plus que la route pawaPay.
- `GET /api/providers/status` renvoie uniquement des statuts (aucun secret).
