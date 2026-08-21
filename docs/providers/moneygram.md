# Provider — MoneyGram

Statut d'intégration : **CONFIGURED / CONNECTED** (après `testConnection()` OAuth
réussi) — jamais PRODUCTION READY tant qu'un E2E sandbox disbursement/transfer
n'est pas démontré avec credentials partenaire.

Source : https://developer.moneygram.com/  
OAuth : https://developer.moneygram.com/moneygram-developer/docs/o-auth-api  
Disbursement : https://developer.moneygram.com/moneygram-developer/docs/disbursement

## Credentials (Credential Manager)

| champ | rôle |
|---|---|
| `client_id` | OAuth 2.0 client ID — Basic Auth (sensible, backend-only) |
| `client_secret` | OAuth 2.0 client secret — Basic Auth |
| `agent_partner_id` | `agentPartnerId` partenaire — requis sur appels métier, optionnel pour OAuth |

## Authentification

1. `GET {host}/oauth/accesstoken?grant_type=client_credentials`
2. Header `Authorization: Basic base64(client_id:client_secret)`
3. Header `Content-Type: application/json` / `Accept: application/json`
4. Réponse : `access_token`, `expires_in` (~3599 s / 1 h), `token_type` BearerToken
5. Appels suivants : `Authorization: Bearer <access_token>` + souvent
   `X-MG-ClientRequestId` (UUID) et query `agentPartnerId`

## Environnements

- Sandbox : `https://sandboxapi.moneygram.com`
- Production : `https://api.moneygram.com`
- Credentials sandbox / production distinctes (délivrées après partenariat).

## testConnection — IMPLEMENTED

Sonde OAuth réelle via `MoneyGramAdapter::testConnection()`. Résultats
normalisés : `CONNECTION_SUCCESS` / `INVALID_CREDENTIALS` / `UNAUTHORIZED` /
`PROVIDER_UNAVAILABLE` / `TIMEOUT` / `PROVIDER_NOT_CONFIGURED` /
`CONFIGURATION_ERROR`.

Succès = HTTP 200 **et** présence d'un `access_token` non vide dans le JSON.

## Capacités

| capacité | statut |
|---|---|
| test_connection | **IMPLEMENTED** (OAuth) |
| quote | **NOT_IMPLEMENTED** (Disbursement/Transfer quote documentés, non câblés) |
| payout | **NOT_IMPLEMENTED** (commit / auto-commit nécessitent partenariat E2E) |
| balance | **NOT_IMPLEMENTED** |
| refund | **NOT_IMPLEMENTED** |
| webhook | **CONFIG_REQUIRED** (pipeline générique ; callbacks MG non câblés) |
| reconciliation | **NOT_IMPLEMENTED** |

`declaredMethods()` = `['cash_pickup']` — Disbursement = cash pickup B2C.
Transfer API documente aussi bank/wallet : non déclarés tant que non câblés.

## Modules documentés (hors scope actuel)

- **Disbursement** : quote → update → commit (ou batch auto-commit) → référence
  cash pickup.
- **Transfer** : P2P cash / bank / wallet / crypto ramp.
- **Reference data** : pays, devises, etc.

## Limites connues

- Accès self-service impossible : credentials après onboarding MoneyGram.
- Payout / quote / reconciliation absents jusqu'à E2E sandbox avec
  `agentPartnerId` réel.
- Token OAuth runtime (~1 h) — jamais stocké comme credential permanente.
