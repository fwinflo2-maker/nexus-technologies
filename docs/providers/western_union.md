# Provider — Western Union (Mass Payments)

Statut d'intégration : **CONFIGURED / CONNECTED** (après `testConnection()` Ping
mTLS réussi) — jamais PRODUCTION READY tant qu'un E2E sandbox batches/payments
n'est pas démontré avec certificat partenaire.

Source : https://developer.westernunion.com/getting-started.html  
OpenAPI Mass Payments (Partnership Program).

## Credentials (Credential Manager)

| champ | rôle |
|---|---|
| `client_id` | WU `clientId` path param (`/customers/{clientId}/…`) — backend-only |
| `client_cert_path` | Chemin serveur du certificat client mTLS (`.crt` / `.pem`) |
| `client_key_path` | Chemin serveur de la clé privée mTLS |
| `partner_id` | Partner ID optionnel |

### Décision path vs PEM

Le schéma vérifié et l'adaptateur utilisent **des chemins fichier**
(`client_cert_path` / `client_key_path`), pas des PEM inline
(`certificate_pem` / `private_key_pem`). Raison : `curl` mTLS via
`CURLOPT_SSLCERT` / `CURLOPT_SSLKEY` attend des chemins ; les certificats WU
sont délivrés à l'adhésion Partnership et déployés sur le serveur d'application.
Ne pas inventer de champs PEM tant qu'une stratégie de stockage chiffré PEM
n'est pas définie.

## Authentification

- **Mutual TLS (mTLS)** — certificat client fourni par Western Union à
  l'enrollment Partnership Program.
- Aucun Bearer token self-service.
- Chaque requête HTTPS inclut le certificat client.

## Environnements

- Sandbox : `https://api-sandbox.westernunion.com`
- Production : `https://api.westernunion.com`

## Endpoints documentés

| Méthode | Path | Rôle |
|---|---|---|
| GET | `/Ping` | Health / sonde auth |
| GET | `/customers/{clientId}` | Customer |
| POST | `/customers/{clientId}/quotes` | Quote FX |
| PUT | `/customers/{clientId}/batches/{batchId}` | Batch |
| POST | `/customers/{clientId}/batches/{batchId}/payments` | Payment |
| GET | `/customers/{clientId}/batches/{batchId}/payments/{paymentId}` | Status |

## testConnection — IMPLEMENTED

`WesternUnionAdapter::testConnection()` → `GET /Ping` avec mTLS.
Statuts : `CONNECTION_SUCCESS` / `INVALID_CREDENTIALS` / `UNAUTHORIZED` /
`PROVIDER_UNAVAILABLE` / `TIMEOUT` / `PROVIDER_NOT_CONFIGURED` /
`CONFIGURATION_ERROR`.

`healthCheck()` délègue à la même sonde (latence + statut `active`/`error`).

## Capacités

| capacité | statut |
|---|---|
| test_connection | **IMPLEMENTED** (`GET /Ping`) |
| quote | **NOT_IMPLEMENTED** dans la matrice (`getQuote()` existe côté adaptateur mais hors CapabilityEngine / E2E) |
| payout | **NOT_IMPLEMENTED** (batches/payments non câblés ExecutionEngine) |
| balance | **NOT_IMPLEMENTED** |
| refund | **NOT_IMPLEMENTED** |
| webhook | **CONFIG_REQUIRED** |
| reconciliation | **NOT_IMPLEMENTED** |

`declaredMethods()` = `['cash_pickup', 'bank']` — Mass Payments couvre cash et
bank selon le produit partenaire.

## Limites connues

- Onboarding partenaire obligatoire (pas de sandbox self-service).
- Quote HTTP implémentée dans l'adaptateur mais **non** déclarée IMPLEMENTED
  dans la matrice tant qu'un E2E sandbox n'est pas démontré.
- Payout / réconciliation absents.
