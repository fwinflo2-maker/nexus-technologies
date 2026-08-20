# SUMSUB-INTEGRATION — KYC / KYB dans Nexus

Sumsub est le provider **Compliance / KYC** de Nexus : il apparaît dans le
Control Center sous `Providers → Compliance / KYC`, jamais comme provider de
paiement. Ses credentials sont gérées par le **Credential Manager** (chiffrées
en base, par environnement), conformément à `docs/PROVIDER-CREDENTIALS.md`.

## 1. Credentials (par environnement)

Sumsub distingue explicitement sandbox et production : l'App Token et la Secret
Key sont **affichés une seule fois** dans le dashboard Sumsub et sont **propres
au mode sandbox ou production**. Nexus ne partage jamais un secret entre les
deux environnements.

| champ | rôle | exposition |
|---|---|---|
| `app_token` | identifiant d'application (`X-App-Token`) | backend uniquement |
| `secret_key` | clé de signature HMAC-SHA256 (`X-App-Access-Sig`) | backend uniquement |
| `webhook_secret` | vérification des webhooks (`X-Payload-Digest`) | backend uniquement |

Stockage : `provider_credentials` (AES-256-GCM) via
`ProviderCredentialService::upsertPlatform('sumsub', …)`. Résolution :
`SumsubAdapter::fromCredentialManager()`. Les variables d'environnement
(`SUMSUB_APP_TOKEN`, `SUMSUB_SECRET_KEY`, `SUMSUB_WEBHOOK_SECRET`) ne servent
que de **bootstrap d'infrastructure** — repli si aucune credential n'est en base.

## 2. Authentification API (signature HMAC-SHA256)

Chaque requête porte trois en-têtes (docs.sumsub.com/reference/authentication) :

```
X-App-Token:      <app_token>
X-App-Access-Ts:  <timestamp Unix, secondes UTC>
X-App-Access-Sig: HMAC-SHA256(ts + METHOD + path(+query) + body, secret_key)
```

- La signature est en hexadécimal minuscule.
- Le `path` doit inclure la query string exactement telle qu'envoyée.
- Le timestamp doit être proche de l'heure serveur.
- Implémentation : `SumsubAdapter::signRequest()` ; le frontend ne connaît
  jamais ni l'App Token ni la Secret Key.

### Test de connexion

`SumsubAdapter::testConnection(environment, credentials)` effectue un
`GET /resources/applicants/-;status` signé :

| réponse HTTP | résultat |
|---|---|
| 200/201 | `CONNECTION_SUCCESS` |
| 400/404/422 (authentifié) | `CONNECTION_SUCCESS` (l'erreur applicative prouve l'auth) |
| 401/403 | `INVALID_CREDENTIALS` |
| 5xx / réseau | `PROVIDER_UNAVAILABLE` |
| credentials absentes | `PROVIDER_NOT_CONFIGURED` (aucun appel) |

## 3. WebSDK (access token temporaire)

Flux documenté (docs.sumsub.com/reference/generate-access-token) :

```
Frontend React
       ↓
Nexus Backend (POST /api/kyc/session — AuthMiddleware)
       ↓
Sumsub API → Access Token temporaire (courte durée)
       ↓
Frontend → Sumsub WebSDK
```

- Le backend crée l'applicant (`createApplicant`) puis la session
  (`createVerificationSession`) et ne renvoie au client que le **token court**
  + `expires_in`. Jamais le couple App Token + Secret Key.
- Niveau de vérification : `levelName()` — KYC (individuel) et KYB (entreprise)
  sont des niveaux distincts.

## 4. Webhooks

Endpoint public : `POST /api/kyc/webhook` (aucun cookie/session : l'authentification
est la signature).

Séquence imposée :

1. **Corps BRUT** conservé — le HMAC porte sur les octets exacts reçus.
2. **Vérification de signature avant toute interprétation** :
   - `X-Payload-Digest-Alg` (défaut `HMAC_SHA256_HEX`) ;
   - `X-Payload-Digest` = HMAC du corps brut avec `webhook_secret`
     (`hash_equals`, temps constant) ;
   - algorithme inconnu → rejeté ; secret absent → aucun webhook accepté.
3. **Environnement cohérent** : l'environnement de l'événement doit correspondre
   à celui du provider configuré.
4. **Idempotence** : événement déjà traité (`kyc_webhook_events`,
   `event_id` + environnement) → acquitté 200 sans double application.
5. **Application** : mise à jour du niveau KYC / statut KYB via `KycService`.

Rejets : signature invalide → 401 (aucun détail sur la cause, pour ne pas aider
un attaquant) ; payload illisible → 400 ; environnement incohérent → 409.

Un webhook dupliqué répond 200 : le provider ne doit pas réessayer indéfiniment.

## 5. Mapping des statuts KYC

| reviewStatus Sumsub | reviewResult | statut Nexus |
|---|---|---|
| `completed` | `reviewAnswer = GREEN` | `verified` |
| `completed` | `reviewAnswer = RED` | `rejected` (final) |
| `pending` | — | `pending` (jamais élevé) |
| `retry` | — | `resubmission_required` |
| inconnu | — | jamais interprété comme vérifié |

Le statut client (WebSDK) n'est **jamais** une preuve : seule la source serveur
(webhook vérifié / lecture API) fait foi. Le niveau KYC est persisté sur le
profil, le statut KYB distinct pour les comptes business.

## 6. Sandbox / Production

- Credentials distinctes par environnement (jamais copiées de sandbox vers
  production — §4 du Credential Manager).
- URL de base identique (`https://api.sumsub.com`) ; la séparation se fait par
  les tokens.
- Le webhook rejette un événement dont l'environnement ne correspond pas.

## 7. Sécurité

- Aucun secret en clair : chiffrement AES-256-GCM en base, redaction dans les
  logs/audit, jamais dans les réponses API.
- Le couple App Token + Secret Key ne quitte jamais le backend.
- Les événements webhook sont journalisés sans secret (`audit_logs`,
  colonne `environment`).
