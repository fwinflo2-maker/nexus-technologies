# Provider — Sumsub (Compliance / KYC)

Statut d'intégration : **CONFIGURED / CONNECTED** (après test de connexion réussi).
Sumsub reste dans la catégorie **Compliance / KYC** — jamais un provider de paiement.

Source : https://docs.sumsub.com

## Credentials (Credential Manager)

| champ | rôle | exposition |
|---|---|---|
| `app_token` | identifiant d'application (`X-App-Token`) | backend uniquement |
| `secret_key` | clé HMAC-SHA256 (`X-App-Access-Sig`) | backend uniquement |
| `webhook_secret` | digest des webhooks (`X-Payload-Digest`) | backend uniquement |

Affichés **une seule fois** dans le dashboard Sumsub ; propres au mode sandbox
OU production. Chiffrés en base (AES-256-GCM), résolus via
`SumsubAdapter::fromCredentialManager()` — l'environnement ne sert que de
bootstrap.

## Authentification (signature HMAC-SHA256)

Chaque requête porte :
`X-App-Token` + `X-App-Access-Ts` (Unix, secondes) + `X-App-Access-Sig`
(HMAC-SHA256 hex de `ts + METHOD + path(+query) + body`, clé = secret_key).

## testConnection — IMPLEMENTED

`GET /resources/applicants/-;status` signé :
200/4xx-authentifié → CONNECTION_SUCCESS ; 401/403 → INVALID_CREDENTIALS ;
5xx → PROVIDER_UNAVAILABLE ; sans credentials → PROVIDER_NOT_CONFIGURED.

## Flux KYC / KYB

```
Nexus User → KYC requirement → Backend Sumsub (signé)
→ WebSDK access token (court) → Frontend → Sumsub WebSDK
→ Webhook signé (X-Payload-Digest) → Nexus KYC status → PolicyEngine
```

Le frontend ne reçoit que le token court du WebSDK, jamais App Token + Secret
Key. Le statut client (WebSDK) n'est JAMAIS une preuve : seul le webhook vérifié
fait autorité.

## Webhook

Endpoint : `POST /api/kyc/webhook` (public, authentifié par digest).

Séquence : corps BRUT → `X-Payload-Digest-Alg` (défaut HMAC_SHA256_HEX) →
`X-Payload-Digest` (hash_equals, temps constant) → environnement cohérent →
idempotence `kyc_webhook_events` → mise à jour du niveau KYC / statut KYB.

## Mapping des statuts KYC

| Sumsub | Nexus |
|---|---|
| completed + GREEN | verified |
| completed + RED | rejected (final) |
| pending | pending (jamais élevé) |
| retry | resubmission_required |
| inconnu | jamais interprété comme vérifié |

## KYC → Capability (Policy Engine)

Le statut KYC alimente réellement le backend (§13) :

- KYC none/basic : plafond mensuel (200/500 EUR) — au-delà, opération REFUSÉE (403).
- Montant > 1000 EUR : niveau standard ou supérieur requis.
- Compte Business sans `kyb_status = verified` : REFUS total (403).
- Compte PENDING / bloqué : REFUS total.
- KYC standard + politique satisfaite : APPROVED.

Le frontend n'est pas la source de décision : le Policy Engine revalide à
chaque opération (tests dédiés : `KycCapabilityGatingTest`).

## Limites connues

- E2E sandbox complet à démontrer avec des credentials sandbox réelles :
  credentials → test → WebSDK → webhook → élévation du niveau KYC.
