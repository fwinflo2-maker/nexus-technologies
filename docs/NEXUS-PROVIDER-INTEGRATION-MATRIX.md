# NEXUS — Matrice d'intégration providers

> **SUPERSEDED (2026-08-20)** — ce document contenait des faux positifs
> historiques (« pawaPay PRODUCTION READY », balances IMPLEMENTED, etc.).
> Source de vérité actuelle :
>
> - [`docs/PROVIDER-CAPABILITY-MATRIX.md`](PROVIDER-CAPABILITY-MATRIX.md) — capacités code
> - [`docs/NEXUS-PROVIDER-AUDIT-2026-08-20.md`](NEXUS-PROVIDER-AUDIT-2026-08-20.md) — opérationnel
> - [`docs/PROVIDER-CREDENTIALS.md`](PROVIDER-CREDENTIALS.md) — credentials
>
> Régénérer la matrice : `cd nexus-api; php scripts/matrix_dump.php`  
> Audit connexion : `php scripts/provider_connect_test.php --all`

État généré depuis le code réel (`ProviderCapabilityMatrix`, `ProviderHealthService`,
`WebhookRegistry`) — mise à jour : 20 août 2026.

> **Règle d'honnêteté** : un provider n'est jamais `IMPLEMENTED` tant qu'un adapter
> vide n'a pas de vraie implémentation. `CONFIGURED` ≠ `CONNECTED` ≠ `PRODUCTION READY`.

## 1. Matrice des capacités

Légende : `IMPLEMENTED` = code réel + testé · `N/S` = NOT_SUPPORTED (non offert par le
provider) · `CONFIG` = CONFIG_REQUIRED (déclaré, non opérationnel) · `—` = NOT_IMPLEMENTED.

Voir le tableau à jour dans [`PROVIDER-CAPABILITY-MATRIX.md`](PROVIDER-CAPABILITY-MATRIX.md).

## 2. États opérationnels (§21)

| Provider | Credentials | Connecté | Compte | Balance | Quote | Exécution | Webhook | Réconciliation | E2E sandbox | Statut |
| -------- | ----------- | -------- | ------ | ------- | ----- | --------- | ------- | -------------- | ----------- | ------ |
| pawaPay  | ❌          | ❌ BLOCKED | —     | —       | —     | code prêt  | code prêt | code prêt     | ❌          | **CREDENTIALS_NOT_CONFIGURED** |
| Stripe   | ❌          | ❌ BLOCKED | —     | —       | —     | —          | code prêt | —              | ❌          | **CREDENTIALS_NOT_CONFIGURED** |
| Sumsub   | ❌          | ❌ BLOCKED | n/a   | n/a     | n/a   | n/a (KYC)  | code prêt | n/a            | ❌          | **CREDENTIALS_NOT_CONFIGURED** |
| Autres   | ❌          | BLOCKED    | —     | —       | —     | —          | déclaré  | —              | —           | NOT_IMPLEMENTED + NOT_CONFIGURED |

**Règles :**
- `NOT_CONFIGURED` / `CREDENTIALS_NOT_CONFIGURED` — aucun credential, aucun appel.
- `CONFIGURED` — credentials présents, pas de testConnection réussi (ou non tenté).
- `CONNECTED` — testConnection réel réussi contre le sandbox provider.
- `SANDBOX_TESTED` — parcours sandbox bout-en-bout démontré.
- `PRODUCTION READY` — E2E sandbox + production testée. **Aucun provider** à cette date.

## 3. Health (GET /api/admin/providers/health)

Chaque provider expose (sans aucun secret) :

```text
configured | connected | degraded | unavailable | disabled
Configured · Authenticated · Last successful test · Last failed test
Last error code · Last checked
```

- `connected` exige un `testConnection` réellement réussi (jamais déduit de la
  présence de credentials).
- La balance provider alimente `provider_balances` (observation externe), jamais
  `wallet.balance` directement.

## 4. Webhook Registry

| Provider | Path                 | Signature                  | Replay | Event ID          |
| -------- | -------------------- | -------------------------- | ------ | ----------------- |
| pawaPay  | /api/providers/webhook/pawapay | RFC 9421 (Content-Digest)  | oui    | payoutId:status |
| stripe   | /api/providers/webhook/stripe | Stripe-Signature (HMAC-SHA256, t/v1) | oui | id (evt_…)   |
| sumsub   | /api/kyc/webhook     | X-Payload-Digest (HMAC-SHA256) | oui  | applicantId + type |
| autres   | /api/providers/webhook/<slug> | hmac_nexus (CONFIG_REQUIRED) | — | — |

Un webhook falsifié est toujours rejeté (401) ; chaque endpoint vérifie
signature → timestamp/replay → idempotence → mise à jour transaction →
déclenchement réconciliation quand applicable.

## 5. Docs par provider

- [docs/providers/pawapay.md](providers/pawapay.md)
- [docs/PROVIDER-CREDENTIALS.md](PROVIDER-CREDENTIALS.md)
- [docs/SUMSUB-INTEGRATION.md](SUMSUB-INTEGRATION.md)
- [docs/NEXUS-PROVIDER-AUDIT-2026-08-20.md](NEXUS-PROVIDER-AUDIT-2026-08-20.md)

## 6. KYC → CapabilityEngine

Les statuts Sumsub (`pending`, `approved`, `rejected`, `expired`, `manual_review`)
sont mappés vers le modèle Nexus et consommés par le **PolicyEngine** côté backend :
- KYC non vérifié → payout bloqué (plafonds mensuels réduits : none=200 €, basic=500 €).
- KYC vérifié → plafonds élevés (1 000 € / 10 000 € selon profil).
- La décision est toujours revalidée backend — le frontend n'est jamais la source.
- Sumsub n'est **pas** un provider de payout / routing monétaire.

Tests : `tests/KycCapabilityGatingTest.php` (refus et passage couverts).
