# NEXUS — Fiches de configuration des providers

Date : 2026-08-14

**Aucune valeur secrète ne figure dans ce document, ni dans Git, ni en SQL.**
Les credentials vivent exclusivement dans l'environnement d'exécution.

## Convention de nommage

```
PROVIDER_{SLUG}_ENABLED                  = true|false
PROVIDER_{SLUG}_ENV                      = sandbox|production
PROVIDER_{SLUG}_SANDBOX_{FIELD}          = valeur scope sandbox
PROVIDER_{SLUG}_PRODUCTION_{FIELD}       = valeur scope production
```

La résolution est **strictement scopée** : `PROVIDER_X_SANDBOX_*` n'est jamais
lue en production, et inversement.

## Règle de classification (§6)

Une credential est **backend-only par défaut**. Elle n'est exposable au
frontend que si la documentation officielle du provider l'affirme
explicitement. Ne jamais déduire qu'une clé est publique parce que son nom
contient « public ».

| Sensibilité | Signification | Exposable au client |
|---|---|---|
| `secret` | Authentification, signature, webhook | **Non** |
| `identifier` | Identifiant de compte non secret | **Non** (précaution) |
| `public` | Documenté comme destiné au client | **Oui** |

---

## Stripe

- **Slug** : `stripe` — **Catégorie** : cards
- **Documentation** : https://docs.stripe.com/keys
- **Base URL** : `https://api.stripe.com/v1` (sandbox = mêmes URLs, distinction par le préfixe de clé)
- **Schéma vérifié** : oui

| Credential | Requis | Sensibilité | Frontend | Usage |
|---|---|---|---|---|
| `secret_key` | oui | secret | non | Authentification API |
| `publishable_key` | non | **public** | **oui** | Initialisation Stripe.js / Elements |
| `webhook_secret` | non | secret | non | Vérification des webhooks |
| `account_id` | non | identifier | non | Compte Connect |

**Justification de la clé publique** : la documentation Stripe est explicite —
« Only publishable keys are safe to expose outside your application's backend. »
La `publishable_key` est la **seule** credential Stripe exposable au client.
Les clés restreintes (`rk_`), malgré leurs permissions réduites, restent
backend-only selon Stripe.

**Environnements** : distingués par le préfixe (`sk_test_`/`pk_test_` vs
`sk_live_`/`pk_live_`), pas par l'URL.

---

## pawaPay

- **Slug** : `pawapay` — **Catégorie** : mobile_money
- **Documentation** : https://docs.pawapay.io/using_the_api
- **Base URL** : production `https://api.pawapay.io` — sandbox `https://api.sandbox.pawapay.io`
- **Schéma vérifié** : oui

| Credential | Requis | Sensibilité | Frontend | Usage |
|---|---|---|---|---|
| `api_token` | oui | secret | non | Bearer token |
| `private_key` | non | secret | non | Signature des requêtes financières |
| `api_key_id` | non | identifier | non | Identifiant de la clé de signature |

**Point d'attention (§6)** : pawaPay utilise une clé publique déposée *dans son
dashboard* pour valider les signatures. Ce n'est **pas** une clé destinée au
navigateur. **Aucune credential pawaPay n'est exposable au frontend.**

**Environnements** : strictement isolés — la documentation précise qu'un token
sandbox ne fonctionne **que** en sandbox, et qu'un token distinct doit être
généré depuis le compte live pour la production.

---

## Wise Platform

- **Slug** : `wise` — **Catégorie** : fx
- **Documentation** : https://docs.wise.com/guides/developer/auth-and-security
- **Base URL** : production `https://api.wise.com` — sandbox `https://api.wise-sandbox.com`
- **Schéma vérifié** : oui

| Credential | Requis | Sensibilité | Frontend | Usage |
|---|---|---|---|---|
| `client_id` | oui | secret | non | OAuth 2.0 |
| `client_secret` | oui | secret | non | OAuth 2.0 |
| `profile_id` | non | identifier | non | Profil Wise |

**Justification** : la documentation Wise impose « Never expose client
credentials or tokens in client-side code, logs, or URLs » et « Use separate
credentials for sandbox and production ». Le `client_id` est donc traité comme
secret, contrairement à l'usage OAuth courant.

---

## Nium

- **Slug** : `nium` — **Catégorie** : payout_network
- **Documentation** : https://docs.nium.com/apis/reference/nium-environments
- **Base URL** : production `https://api.spend.nium.com/api` — sandbox `https://gateway.nium.com/api`
- **Schéma vérifié** : oui

| Credential | Requis | Sensibilité | Frontend | Usage |
|---|---|---|---|---|
| `client_id` | oui | secret | non | Authentification API |
| `client_secret` | oui | secret | non | Authentification API |

**Correction appliquée** : le catalogue pointait par erreur vers les URLs
d'**Airwallex** (`www.airwallex.com/api/v1`). Corrigé d'après la documentation
officielle Nium.

---

## Onafriq / Thunes — schéma NON VÉRIFIÉ

- **Slugs** : `onfriq`, `thunes`
- **Statut** : `UNKNOWN`

Leur documentation d'authentification n'a **pas** pu être confirmée depuis une
source officielle pendant cette phase. Conformément au §7 (« si une information
n'est pas confirmée : UNKNOWN, et non une supposition »), leur schéma de
credentials n'est **pas** déclaré comme vérifié.

Conséquence appliquée par le code : `ProviderCredentialSchema::isVerified()`
renvoie `false`, et **aucun** de leurs champs n'est exposable au frontend
(principe de précaution). Les entrées du catalogue restent utilisables pour la
configuration, mais leur classification devra être confirmée avant toute mise
en production.

**Même traitement** pour tous les autres providers du catalogue
(dLocal, BVNK, Currencycloud, Marqeta, Bridge, Yellow Card, EBANX, Xendit,
Noah, Cashramp, Swan, Modulr, Orange Money, MTN MoMo, M-Pesa, Stripe Issuing).

---

## Sumsub (KYC/KYB)

- **Documentation** : https://docs.sumsub.com/reference/authentication
- **Base URL** : `https://api.sumsub.com`

| Variable | Sensibilité | Rôle |
|---|---|---|
| `SUMSUB_APP_TOKEN` | secret | En-tête `X-App-Token` |
| `SUMSUB_SECRET_KEY` | secret | Clé HMAC de signature des requêtes |
| `SUMSUB_WEBHOOK_SECRET` | secret | Vérification `x-payload-digest` |
| `SUMSUB_BASE_URL` | config | URL de l'API |
| `SUMSUB_ENVIRONMENT` | config | `sandbox` \| `production` |
| `SUMSUB_LEVEL_NAME` | config | Niveau de vérification KYC (personne) |
| `SUMSUB_LEVEL_NAME_KYB` | config | Niveau de vérification KYB (entreprise) |
| `SUMSUB_TOKEN_TTL` | config | Durée de vie des access tokens SDK |

**Authentification** (documentée) : chaque requête porte `X-App-Token`,
`X-App-Access-Ts` (Unix, secondes) et `X-App-Access-Sig` = HMAC-SHA256 hex
minuscule sur `ts + METHOD + path + body`.

**Ce qui va au frontend** : uniquement un *access token* à durée de vie courte,
lié à un seul applicant. La clé secrète ne quitte **jamais** le backend.

**Sandbox / production** : la documentation Sumsub impose des paires
App token + Secret key **distinctes** par environnement.
