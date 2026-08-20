# NEXUS — Matrice d'intégration providers

État généré depuis le code réel (`ProviderCapabilityMatrix`, `ProviderHealthService`,
`WebhookRegistry`) — mise à jour : 18 août 2026.

> **Règle d'honnêteté** : un provider n'est jamais `IMPLEMENTED` tant qu'un adapter
> vide n'a pas de vraie implémentation. `CONFIGURED` ≠ `CONNECTED` ≠ `PRODUCTION READY`.

## 1. Matrice des capacités

Légende : `IMPLEMENTED` = code réel + testé · `N/S` = NOT_SUPPORTED (non offert par le
provider) · `CONFIG` = CONFIG_REQUIRED (déclaré, non opérationnel) · `—` = NOT_IMPLEMENTED.

| Provider         | Catégorie     | TestConn      | Balance    | Quote   | Payout | Refund  | Webhook | Reconcile     | Intégration  |
|------------------|----------------|---------------|------------|---------|--------|---------|---------|---------------|--------------|
| pawapay          | mobile_money   | IMPLEMENTED   | IMPLEMENTED | IMPLEMENTED | IMPLEMENTED | N/S     | IMPLEMENTED | IMPLEMENTED   | IMPLEMENTED   |
| stripe           | cards          | IMPLEMENTED   | IMPLEMENTED | —        | —       | —        | IMPLEMENTED | —             | IMPLEMENTED   |
| sumsub           | compliance     | IMPLEMENTED   | N/S        | N/S     | N/S    | N/S     | IMPLEMENTED | N/S           | IMPLEMENTED   |
| thunes           | payout_network | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| orange_money     | mobile_money   | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| mtn_momo         | mobile_money   | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| safaricom_mpesa  | mobile_money   | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| swan             | banking        | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| modulr           | banking        | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| bvnk             | banking        | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| currencycloud    | fx             | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| wise             | fx             | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| western_union    | fx             | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| stripe_issuing   | card_issuing   | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| marqeta          | card_issuing   | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| bridge           | crypto         | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| yellow_card      | mobile_money   | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| onfriq           | payout_network | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| dlocal           | payout_network | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| ebanx            | payout_network | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| xendit           | payout_network | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| nium             | payout_network | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| noah             | wallet         | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| cashramp         | onramp         | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| tazapay          | payout_network | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |
| 2c2p             | cards          | —             | —          | —       | —       | —        | CONFIG      | —             | NOT_IMPLEMENTED |

Régénérer : `cd nexus-api && DB_NAME=nexus_test php scripts/matrix_dump.php`.

## 2. États opérationnels (§21)

| Provider | Credentials | Connecté | Compte | Balance | Quote | Exécution | Webhook | Réconciliation | E2E sandbox | Statut |
| -------- | ----------- | -------- | ------ | ------- | ----- | --------- | ------- | -------------- | ----------- | ------ |
| pawaPay  | ✅          | ✅ testé  | ✅      | ✅      | ✅    | ✅         | ✅       | ✅              | ✅          | **PRODUCTION READY (sandbox validé)** |
| Stripe   | ✅          | ✅ testé  | partiel | ✅      | —     | —          | ✅       | —              | ⚠️ partiel  | CONFIGURED → CONNECTED |
| Sumsub   | ✅          | ✅ testé  | n/a     | n/a     | n/a   | n/a (KYC)  | ✅       | n/a            | ✅          | CONNECTED (KYC) |
| Autres   | selon saisie | selon test | —     | —       | —     | —          | déclaré  | —              | —           | CONFIGURED si credentials, sinon NOT_CONFIGURED |

**Règles :**
- `NOT_CONFIGURED` — aucun credential en base, aucun test tenté.
- `CONFIGURED` — credentials présents, pas de testConnection réussi (ou non tenté).
- `CONNECTED` — testConnection réel réussi contre le sandbox provider.
- `PRODUCTION READY` — E2E sandbox complet démontré + réconciliation + webhook vérifié.
  Aujourd'hui : **pawaPay uniquement**. Stripe et Sumsub restent CONNECTED tant que
  l'E2E de bout en bout (exécution + réconciliation) n'est pas démontré.

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
| pawaPay  | /webhooks/pawapay    | RFC 9421 (Content-Digest)  | oui    | webhookId         |
| stripe   | /webhooks/stripe     | Stripe-Signature (HMAC-SHA256, t/v1) | oui | id (evt_…)   |
| sumsub   | /webhooks/sumsub     | X-Payload-Digest (HMAC-SHA256) | oui  | applicantId + type |
| autres   | /webhooks/<slug>     | déclaré, non vérifié       | —      | —                 |

Un webhook falsifié est toujours rejeté (401) ; chaque endpoint vérifie
signature → timestamp/replay → idempotence → mise à jour transaction →
déclenchement réconciliation quand applicable.

## 5. Docs par provider

- [docs/providers/pawapay.md](providers/pawapay.md) — intégré, E2E sandbox démontré
- [docs/providers/stripe.md](providers/stripe.md) — testConnection + balance + webhook réels
- [docs/providers/sumsub.md](providers/sumsub.md) — KYC complet (WebSDK, webhook, mapping statuts)
- [docs/PROVIDER-CREDENTIALS.md](../PROVIDER-CREDENTIALS.md) — credentials par provider
- [docs/SUMSUB-INTEGRATION.md](../SUMSUB-INTEGRATION.md) — intégration Sumsub détaillée

## 6. KYC → CapabilityEngine

Les statuts Sumsub (`pending`, `approved`, `rejected`, `expired`, `manual_review`)
sont mappés vers le modèle Nexus et consommés par le **PolicyEngine** côté backend :
- KYC non vérifié → payout bloqué (plafonds mensuels réduits : none=200 €, basic=500 €).
- KYC vérifié → plafonds élevés (1 000 € / 10 000 € selon profil).
- La décision est toujours revalidée backend — le frontend n'est jamais la source.

Tests : `tests/KycCapabilityGatingTest.php` (refus et passage couverts).
