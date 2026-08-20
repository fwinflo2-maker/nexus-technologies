# PROVIDER-CREDENTIALS — Gestionnaire unifié des credentials providers

Source de vérité pour les intégrations provider de Nexus : ce que Nexus attend
de chaque plateforme, comment les credentials sont stockées, testées, rotées et
révoquées. Les schémas de credentials sont **provider-driven** : chaque provider
déclare ses propres champs et son propre mécanisme d'authentification — Nexus ne
suppose jamais qu'un provider n'a que `api_key` / `api_secret` / `public_key`.

## 1. Architecture

```
                NEXUS CONTROL CENTER
                         |
                  Credential Manager
                         |
             Credential Schema Registry
                         |
        ┌────────────────┼─────────────────┐
        |                |                 |
     pawaPay          Stripe            Sumsub
        |                |                 |
   Bearer token    Secret/Public       App Token
   Public key      Webhook secret      Secret Key
                                     Webhook secret
        |                |                 |
        └────────────────┼─────────────────┘
                         |
                  Credential Resolver
                         |
                  Provider Adapters
                         |
                 External APIs
```

- **Credential Manager** — `ProviderCredentialService` : chiffrement AES-256-GCM
  (`Crypto`), isolation d'environnement, stockage plateforme (`user_id IS NULL`),
  rotation.
- **Schema Registry** — `ProviderCredentialSchema` : schémas **vérifiés sur la
  documentation officielle** de chaque provider, avec justification par champ.
  Un schéma non vérifié expose `UNKNOWN` et n'expose rien au frontend.
- **Credential Resolver** — `ProviderCredentialService::resolvePlatform()` /
  `resolve()` : unique point de déchiffrement ; les adaptateurs ne lisent jamais
  la base directement.
- **Adapters** — `PawaPayAdapter`, `StripeAdapter`, `SumsubAdapter`, … : chaque
  adaptateur consomme ses credentials via le resolver et implémente
  `testConnection()`.

## 2. Règles non négociables

1. **Jamais de secret en clair** : ni en base (AES-256-GCM), ni dans Git, ni
   dans les logs, ni dans les réponses API, ni dans les exceptions, ni dans le
   bundle frontend (`SecretRedactor` + tests de fuite).
2. **Environnement étanche** : `sandbox ≠ production`. Une credential sandbox
   n'est JAMAIS résolue en production (et inversement). L'unicité SQL porte sur
   `(owner_scope, provider_slug, environment)`.
3. **Le succès n'est jamais déclaré sans test réel** : `credentials_present ≠
   provider_connected`. `testConnection()` effectue un appel authentifié réel.
4. **Pas de credentials codées en dur** et pas de `getenv()` comme solution
   permanente : les variables d'environnement sont réservées au **bootstrap
   d'infrastructure**. Les credentials administrables vivent dans
   `provider_credentials` (chiffrées).
5. **Rotation sans perte** : l'ancienne credential n'est jamais supprimée avant
   validation de la nouvelle (table `credential_rotations`).
6. **RBAC** : l'inventaire des credentials exige `credential_inventory` ;
   écrire/tester/activer/révoquer exige `credentials`. Un utilisateur normal ne
   reçoit jamais une valeur brute.

## 3. Modèle de données

`provider_credentials` — une ligne par `(owner_scope, provider_slug, environment)` :

| colonne | rôle |
|---|---|
| `credentials_enc` | JSON chiffré AES-256-GCM (`{credentials, updated_by, updated_at}`) |
| `status` | `not_configured` / `sandbox_only` / `active` / `error` |
| `last_tested_at` / `last_error` | résultat du dernier test de connexion |
| `configured_by` | opérateur ayant saisi la credential (traçabilité) |

`credential_rotations` — rotation (§29) :

| statut | signification |
|---|---|
| `staged` | nouvelles credentials, testables, PAS encore actives |
| `active` | credentials promues (devenues actives) |
| `revoked` | credentials remplacées ou révoquées — archivées, jamais perdues |

## 4. Cycle de vie (API)

| Endpoint | Action |
|---|---|
| `GET /api/providers` | catalogue (slugs + métadonnées, sans secret) |
| `GET /api/providers/credentials` | inventaire (statuts, sans secret) — RBAC `credential_inventory` |
| `PUT /api/providers/{slug}/credentials` | enregistrer (upsert chiffré) — RBAC `credentials` |
| `POST /api/providers/{slug}/test` | test de connexion réel — RBAC `credentials` |
| `POST /api/providers/{slug}/credentials/rotate` | staged : nouvelles credentials, sans toucher l'active |
| `POST /api/providers/{slug}/credentials/activate` | promotion : ancienne archivée (`revoked`), nouvelle active |
| `POST /api/providers/{slug}/credentials/revoke` | révocation : archivée puis retirée |
| `DELETE /api/providers/{slug}/credentials` | suppression (environnement explicite requis) |
| `GET /api/providers/credentials/rotations` | historique des rotations (sans secret) |

L'activation exige un `rotation_id` : le serveur refuse d'activer une rotation
inconnue, d'un autre environnement, ou déjà consommée.

## 5. Test de connexion

`testConnection(environment, credentials)` retourne :

| statut | signification |
|---|---|
| `CONNECTION_SUCCESS` | appel réel authentifié accepté (200, ou 4xx applicatif prouvant l'auth) |
| `INVALID_CREDENTIALS` | 401/403 — secret ou token invalide |
| `PROVIDER_UNAVAILABLE` | 5xx ou erreur réseau |
| `PROVIDER_NOT_CONFIGURED` | aucune credential — aucun appel envoyé |
| `CONFIGURATION_ERROR` | échec inattendu du test |

## 6. Webhooks

Chaque provider dispose d'un endpoint dédié, authentifié par **signature**, avec
idempotence par `event_id` :

- `POST /api/providers/webhook/{slug}` — webhooks de paiement (pawaPay,
  Stripe, Bridge, Xendit, …) : vérification de signature propre au provider.
- `POST /api/kyc/webhook` — webhooks Sumsub (`X-Payload-Digest`,
  `X-Payload-Digest-Alg`, HMAC-SHA256 minimum).

Jamais de webhook générique acceptant n'importe quelle signature.

## 7. Fiches providers (schémas vérifiés)

### pawaPay (mobile_money)

| champ | type | usage |
|---|---|---|
| `api_token` | secret, requis | Bearer token (`Authorization: Bearer <token>`) |
| `api_key_id` | identifiant, optionnel | identifiant de clé de signature (keyid) |
| `private_key` | secret, optionnel | clé privée EC de signature des requêtes |

- Environnement : tokens sandbox et production **distincts**.
- Webhook : callbacks signés RFC-9421, vérifiés contre la **clé publique
  officielle** du provider (endpoint Public Keys, cache par environnement).
- Rotation : pawaPay limite à deux tokens actifs simultanés — respecter cette
  contrainte (révoquer l'ancien après activation).
- Docs : https://docs.pawapay.io/using_the_api

### Thunes (payout_network)

| champ | type | usage |
|---|---|---|
| `api_key` | secret, requis | identifiant Basic Auth / HMAC |
| `api_secret` | secret, requis | secret de signature |

- Authentification : Basic Auth ou HMAC selon l'API utilisée — pas de bearer
  token supposé.
- Environnements : `https://api.thunes.com` (prod) / `https://sandbox.thunes.com`.
- Docs : https://docs.thunes.com/money-transfer/v1

### Currencycloud (fx)

| champ | type | usage |
|---|---|---|
| `login_id` | identifiant, requis | login du partenaire |
| `api_key` | secret, requis | clé API |

- Le `auth_token` est une donnée **runtime** obtenue par le backend (login_id +
  api_key) — jamais saisie comme credential permanente.

### Wise Platform (fx)

| champ | type | usage |
|---|---|---|
| `client_id` | identifiant, requis | Client ID OAuth |
| `client_secret` | secret, requis | Client Secret OAuth |
| `mTLS certificate` / `private key` | secrets hautement sensibles | selon le modèle partenaire |

- Credentials sandbox et production **distinctes** ; token OAuth temporaire
  (runtime), jamais stocké.
- Docs : https://docs.wise.com/guides/developer/auth-and-security

### dLocal (cards)

| champ | type | usage |
|---|---|---|
| `x-login` | identifiant, requis | header `X-Login` |
| `x-trans-key` | secret, requis | header `X-Trans-Key` |
| `secret_key` | secret, requis | signature des requêtes |
| `smartfields_key` | secret, optionnel | Smart Fields |

- Ne pas confondre `x-trans-key` et `secret_key` : fonctions différentes.

### Stripe (cards)

| champ | type | usage |
|---|---|---|
| `secret_key` | secret, requis | backend uniquement — jamais au frontend |
| `publishable_key` | public, requis | frontend (documenté « safe to expose ») |
| `webhook_signing_secret` | secret, requis | vérification des webhooks |

- Test : `GET /v1/balance` (200 → CONNECTION_SUCCESS, 401/403 → échec).
- Docs : https://docs.stripe.com/keys

### Stripe Issuing (card_issuing — cartes virtuelles)

| champ | type | usage |
|---|---|---|
| `secret_key` | secret, requis | même clé `sk_test_` / `sk_live_` avec permissions Issuing |
| `webhook_secret` | secret, optionnel | événements `issuing_*` |

- Adapter : `StripeIssuingAdapter` (slug `stripe_issuing`).
- Test : `GET /v1/issuing/cardholders?limit=1`.
- Émission : `POST /v1/issuing/cardholders` puis `POST /v1/issuing/cards` (`type=virtual`).
- Repli credentials : si `stripe_issuing` n’a pas de clé, le runtime peut utiliser la `secret_key` du slug `stripe` (même compte).
- Devises : EUR, USD, GBP (pas de XAF).
- Nexus stocke `last4` / `brand` / `issuer_ref` uniquement — jamais PAN/CVV.
- Docs : https://docs.stripe.com/issuing/cards/virtual/issue-cards

### Bridge (banking)

| champ | type | usage |
|---|---|---|
| `api_key` | secret, requis | appels API |
| `webhook_public_key` | secret, requis | vérification `X-Webhook-Signature` (timestamp + signature, anti-replay) |

- Docs : https://docs.bridge.xyz

### BVNK (crypto)

| champ | type | usage |
|---|---|---|
| `hawk_auth_id` | identifiant, requis | Hawk Auth ID |
| `hawk_secret_key` | secret, requis | Hawk Secret Key |
| `webhook_secret` | secret, requis | HMAC-SHA256 + idempotence par event id |

### Tazapay (payout_network)

| champ | type | usage |
|---|---|---|
| `api_key` | secret, requis | clé API |
| `api_secret` | secret, requis | secret |

- Credentials sandbox et production **distinctes** — jamais mélangées.
- Docs : https://developers.tazapay.com/

### 2C2P (cards)

| champ | type | usage |
|---|---|---|
| `merchant_id` | identifiant, requis | identifiant marchand |
| `secret_key` | secret, requis | clé secrète |

- Le backend génère les **JWT signés** (donnée runtime dérivée des
  credentials) — jamais un JWT pré-généré saisi par l'administrateur.
- Docs : https://www.2c2p.com/docs/

### Xendit (payout_network)

| champ | type | usage |
|---|---|---|
| `secret_key` | secret, requis | API backend uniquement |
| `public_key` | secret, requis | clé publique |
| `webhook_token` | secret, requis | vérification des webhooks (idempotence par event id) |

### Sumsub (compliance / KYC)

Voir `docs/SUMSUB-INTEGRATION.md` — App Token, Secret Key, Webhook Secret,
chiffrés en base par environnement, jamais exposés au frontend.

## 8. Rotation (recommandations opérationnelles)

1. `POST .../credentials/rotate` avec les nouvelles credentials → `staged`.
2. `POST .../test` avec `rotation_id` → la NOUVELLE credential est testée, la
   ligne active n'est pas touchée.
3. `POST .../credentials/activate` → l'ancienne est archivée (`revoked`), la
   nouvelle devient active.
4. Révoquer l'ancien côté provider si la plateforme limite les tokens actifs
   (pawaPay : deux tokens maximum).

## 9. Audit

Chaque opération est journalisée (`audit_logs`, colonne `environment`) :
`credential_created`, `credential_replaced`, `credential_tested`,
`credential_enabled`, `credential_disabled`, `credential_rotated`,
`credential_revoked`, `credential_deleted`. Jamais la valeur d'un secret dans
les métadonnées (redaction systématique).

## 10. Convention d'environnement

```text
PROVIDER_{SLUG}_ENABLED             = true|false
PROVIDER_{SLUG}_ENV                 = sandbox|production
PROVIDER_{SLUG}_SANDBOX_{FIELD}     = valeur sandbox
PROVIDER_{SLUG}_PRODUCTION_{FIELD}  = valeur production
```

La forme générique `PROVIDER_{SLUG}_{FIELD}` (sans environnement) **n'est plus
lue** : elle est signalée `INVALID_CONFIGURATION` pour éviter les fuites
sandbox ↔ production.

Champs = clés du `ProviderCatalog` (ex. `api_token` → `API_TOKEN`).

## 11. Runner générique

```powershell
cd nexus-api
php scripts/provider_connect_test.php --provider=pawapay
php scripts/provider_connect_test.php --all
php scripts/provider_connect_test.php --all --no-connect
```

Sortie (jamais de secret) :

```text
credentials=CONFIGURED|CREDENTIALS_NOT_CONFIGURED
connection=CONNECTED|BLOCKED|CONNECTION_FAILED|NOT_TESTED
```

Enregistrement depuis l'environnement (jamais via argument CLI) :

```powershell
$env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN = '<token>'
php scripts/provider_register_from_env.php --provider=pawapay
Remove-Item Env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN -ErrorAction SilentlyContinue
```

## 12. PowerShell — configuration P1 (placeholders uniquement)

### pawaPay

```powershell
cd nexus-api
$env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN = '<token-sandbox>'
# optionnel :
# $env:PROVIDER_PAWAPAY_SANDBOX_API_KEY_ID = '<keyid>'
# $env:PROVIDER_PAWAPAY_SANDBOX_PRIVATE_KEY = '<pem>'
php scripts/provider_register_from_env.php --provider=pawapay
Remove-Item Env:PROVIDER_PAWAPAY_SANDBOX_API_TOKEN -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=pawapay
```

### Stripe

```powershell
cd nexus-api
$env:PROVIDER_STRIPE_SANDBOX_SECRET_KEY = '<sk_test_...>'
# optionnel : publishable_key, webhook_secret
php scripts/provider_register_from_env.php --provider=stripe
Remove-Item Env:PROVIDER_STRIPE_SANDBOX_SECRET_KEY -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=stripe
```

### Onafriq (slug catalogue `onfriq`)

```powershell
cd nexus-api
$env:PROVIDER_ONFRIQ_SANDBOX_API_KEY = '<key>'
$env:PROVIDER_ONFRIQ_SANDBOX_API_SECRET = '<secret>'
php scripts/provider_register_from_env.php --provider=onfriq
Remove-Item Env:PROVIDER_ONFRIQ_SANDBOX_API_KEY, Env:PROVIDER_ONFRIQ_SANDBOX_API_SECRET -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=onfriq
# Note : adapter dédié ABSENT → connection restera BLOCKED / CONFIGURATION_ERROR
# même avec credentials présentes (NOT_IMPLEMENTED).
```

### Bridge

```powershell
cd nexus-api
$env:PROVIDER_BRIDGE_SANDBOX_API_KEY = '<key>'
$env:PROVIDER_BRIDGE_SANDBOX_API_SECRET = '<secret>'
php scripts/provider_register_from_env.php --provider=bridge
Remove-Item Env:PROVIDER_BRIDGE_SANDBOX_API_KEY, Env:PROVIDER_BRIDGE_SANDBOX_API_SECRET -ErrorAction SilentlyContinue
php scripts/provider_connect_test.php --provider=bridge
# Note : adapter dédié ABSENT → NOT_IMPLEMENTED.
```

Un provider `BLOCKED` n'empêche pas d'en configurer / tester un autre.

## 13. Schémas vérifiés vs catalogue

| Provider | Schema `ProviderCredentialSchema` | Notes |
|---|---|---|
| stripe, stripe_issuing, pawapay, wise, nium, western_union, sumsub | **vérifié** (doc officielle citée) | |
| thunes, bridge, bvnk, dlocal, ebanx, xendit, tazapay, 2c2p, onfriq, … | **UNKNOWN** | Champs catalogue présents pour saisie ; ne pas traiter comme confirmés champ-par-champ |

Voir aussi : `docs/PROVIDER-CAPABILITY-MATRIX.md`, `docs/NEXUS-PROVIDER-AUDIT-2026-08-20.md`.
