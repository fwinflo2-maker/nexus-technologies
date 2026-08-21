# PROVIDER-CAPABILITY-MATRIX — capacités vérifiées (code réel)

**Date :** 2026-08-20  
**Source :** `ProviderCapabilityMatrix` + `ProviderCatalog`  
**Régénérer :** `cd nexus-api; php scripts/matrix_dump.php`

> **Règle d'honnêteté** : `IMPLEMENTED` = code réel derrière un adaptateur.  
> Catalogue ≠ opérationnel. `CONFIGURED` ≠ `CONNECTED` ≠ `SANDBOX_TESTED`.  
> Aucun mock n'est utilisé pour déclarer une capacité.

## Légende

| Symbole | Valeur |
|---|---|
| `IMPLEMENTED` | Code réel câblé |
| `—` | `NOT_IMPLEMENTED` |
| `N/S` | `NOT_SUPPORTED` (non offert par le provider) |
| `CONFIG` | `CONFIG_REQUIRED` (code/pipeline présent, credentials ou config requises) |

## Matrice (vérifiée 2026-08-20)

| Provider         | Catégorie      | TestConn      | Balance | Quote | Payout        | Refund | Webhook | Reconcile     | Intégration       |
|------------------|----------------|---------------|---------|-------|---------------|--------|---------|---------------|-------------------|
| pawapay          | mobile_money   | IMPLEMENTED   | —       | —     | IMPLEMENTED   | N/S    | CONFIG  | IMPLEMENTED   | IMPLEMENTED       |
| stripe           | cards          | IMPLEMENTED   | —       | —     | —             | —      | CONFIG  | —             | IMPLEMENTED       |
| sumsub           | compliance     | CONFIG        | N/S     | N/S   | N/S           | N/S    | IMPLEMENTED | N/S       | IMPLEMENTED       |
| western_union    | payout_network | IMPLEMENTED   | —       | —     | —             | —      | CONFIG  | —             | IMPLEMENTED       |
| moneygram        | payout_network | IMPLEMENTED   | —       | —     | —             | —      | CONFIG  | —             | IMPLEMENTED       |
| thunes           | payout_network | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| onfriq (Onafriq) | payout_network | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| bridge           | crypto         | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| nium             | payout_network | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| currencycloud    | fx             | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| wise             | fx             | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| yellow_card      | mobile_money   | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| bvnk             | banking        | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| dlocal           | payout_network | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| ebanx            | payout_network | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| tazapay          | payout_network | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| 2c2p             | cards          | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| xendit           | payout_network | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| orange_money     | mobile_money   | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| mtn_momo         | mobile_money   | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| safaricom_mpesa  | mobile_money   | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| swan             | banking        | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| modulr           | banking        | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| stripe_issuing   | card_issuing   | IMPLEMENTED   | NOT_SUPPORTED | NOT_SUPPORTED | NOT_SUPPORTED | NOT_SUPPORTED | CONFIG  | NOT_IMPLEMENTED | IMPLEMENTED       |
| marqeta          | card_issuing   | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| noah             | wallet         | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |
| cashramp         | onramp         | —             | —       | —     | —             | —      | CONFIG  | —             | NOT_IMPLEMENTED   |

## Adaptateurs dédiés (code)

| Slug | Adapter | testConnection réel |
|---|---|---|
| `pawapay` | `PawaPayAdapter` | Oui — `GET /v2/public-key/http` |
| `stripe` | `StripeAdapter` | Oui — `GET /v1/balance` |
| `western_union` | `WesternUnionAdapter` | Oui — `GET /Ping` (mTLS) |
| `moneygram` | `MoneyGramAdapter` | Oui — `GET /oauth/accesstoken` (Basic) |
| autres | `ConfigDrivenProviderAdapter` | Via `ProviderAuthProbe` si profil défini ; sinon `CONFIGURATION_ERROR` |

## Statut opérationnel (credentials / connexion)

Voir `docs/NEXUS-PROVIDER-AUDIT-2026-08-20.md` et :

```powershell
cd nexus-api
php scripts/provider_connect_test.php --all
```

Au 2026-08-20 : **0 CONNECTED**, **26 CREDENTIALS_NOT_CONFIGURED** (sandbox).
