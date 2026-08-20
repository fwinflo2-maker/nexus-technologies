# Provider — Stripe

Statut d'intégration : **CONFIGURED / CONNECTED** (après test de connexion réussi).
La secret key n'est JAMAIS exposée au frontend.

Source : https://docs.stripe.com

## Credentials (Credential Manager)

| champ | rôle | exposition |
|---|---|---|
| `secret_key` | clé API secrète | backend uniquement |
| `publishable_key` | clé publique | frontend (documenté « safe to expose ») |
| `webhook_signing_secret` | secret de vérification des webhooks | backend uniquement |

## Authentification

- `Authorization: Bearer <secret_key>`.
- Schéma vérifié : docs.stripe.com/keys (« Only publishable keys are safe to
  expose outside your application's backend »).

## Environnements

- Sandbox : clés `sk_test_*` ; production : clés `sk_live_*`.
- Credentials séparées par environnement (jamais partagées).

## testConnection — IMPLEMENTED

`GET /v1/balance` avec la clé secrète :
200 → CONNECTION_SUCCESS ; 401 → INVALID_CREDENTIALS ; 403 → UNAUTHORIZED
(clé restreinte) ; 429 → CONFIGURATION_ERROR ; réseau → PROVIDER_UNAVAILABLE.

## Capacités

| capacité | statut |
|---|---|
| balance | IMPLEMENTED — `getBalance()` (observation → `provider_balances`) |
| webhook | IMPLEMENTED — `Stripe-Signature` HMAC-SHA256 + tolérance anti-replay |
| test_connection | IMPLEMENTED |
| quote / payout / refund / reconciliation | NOT_IMPLEMENTED (Stripe Payouts non câblé) |

## Webhook

Endpoint : `POST /api/providers/webhook/stripe` (public, authentifié).

En-tête `Stripe-Signature: t=<timestamp>,v1=<hmac>` :
- HMAC-SHA256 de `"<t>.<corps brut>"` avec le `webhook_signing_secret` ;
- comparaison en temps constant (`hash_equals`) ;
- tolérance anti-replay : 300 s (rejeu ancien rejeté) ;
- idempotence par `event_id` (`provider_webhook_events`).

Un webhook falsifié, un payload modifié, un timestamp ancien ou un mauvais
secret sont TOUJOURS rejetés (tests dédiés).

## Limites connues

- Stripe Payouts (création de paiement/remboursement) non câblé : la matrice
  le déclare NOT_IMPLEMENTED, jamais IMPLEMENTED.
- Le pipeline webhook vérifie et persiste ; le règlement métier Stripe
  (mapping des événements → transactions Nexus) reste à implémenter.
