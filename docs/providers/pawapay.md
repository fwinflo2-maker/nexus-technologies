# Provider — pawaPay

Statut d'intégration : **CONFIGURED / CONNECTED** (après test de connexion réussi)
— jamais PRODUCTION READY tant qu'un E2E sandbox complet n'est pas démontré.

Source : https://docs.pawapay.io

## Credentials (Credential Manager)

| champ | rôle |
|---|---|
| `api_token` | Bearer token (`Authorization: Bearer <token>`) — sandbox et production DISTINCTS |
| `api_key_id` | identifiant de clé de signature (keyid) — optionnel |
| `private_key` | clé privée EC de signature des requêtes — optionnel |

## Authentification

- Header `Authorization: Bearer <api_token>`.
- Signatures de requêtes financières : EC (détection de l'algorithme par la clé).
- Les callbacks sont signés en **RFC-9421** (`Signature` / `Signature-Input` /
  `Content-Digest`) et vérifiés contre la **clé publique officielle** du
  provider (récupérée via l'endpoint Public Keys, cache par environnement).

## Environnements

- Sandbox : `https://api.sandbox.pawapay.io` — tokens sandbox uniquement.
- Production : `https://api.pawapay.io` — tokens production uniquement.
- Aucune frontière d'environnement franchie par la résolution des credentials.

## testConnection — IMPLEMENTED

`GET /balances` avec le token. Résultats normalisés :
`CONNECTION_SUCCESS` / `INVALID_CREDENTIALS` / `PROVIDER_UNAVAILABLE` /
`PROVIDER_NOT_CONFIGURED`.

## Capacités — IMPLEMENTED

| capacité | statut |
|---|---|
| balance | `getBalance()` — observation externe → `provider_balances`, jamais un wallet |
| quote | `getQuote()` |
| payout | `createPayment()` — câblé dans ExecutionEngine (opération provider réelle) |
| status | `getPaymentStatus()` — polling + réconciliation |
| webhook | RFC-9421 + clé publique, idempotence par `(payoutId, statut)` |
| reconciliation | `ProviderReconciliationService` (pawapay pollable) — règle les statuts, JAMAIS les montants |
| refund | **NOT_SUPPORTED** — doc : un payout accepté est terminal |

## Webhook

Endpoint : `POST /api/providers/webhook/pawapay` (public, authentifié par signature).

Pipeline : raw body → `Content-Digest` → `Signature` RFC-9421 (clé publique,
fenêtre de tolérance) → `(payoutId, statut)` comme identité d'événement →
idempotence `provider_webhook_events` → règlement de la transaction
(completed / failed) → audit sans secret.

## Mapping des statuts

| pawaPay | Nexus |
|---|---|
| ACCEPTED / COMPLETED | completed |
| FAILED | failed (remboursement du bucket) |
| PAYER_DETAILS_REQUIRED / autres | processing (à réconcilier) |

## Réconciliation

Le polling interroge le provider pour les transactions `processing` immobiles,
compare montant/devise (un écart → `reconciliation_required`, décision humaine),
puis règle les statuts. Aucun montant n'est jamais corrigé automatiquement.

## Limites connues

- Pas d'annulation de payout (terminal).
- E2E sandbox complet à démontrer avec des credentials sandbox réelles :
  credentials → test → quote → payout → webhook → réconciliation.
