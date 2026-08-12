# NEXUS CORP TECHNOLOGIES

# NEXUS — ARCHITECTURE TECHNIQUE DÉTAILLÉE

## Document Technique 01 — Version 1.0

**Date :** août 2026  
**Statut :** spécification technique de référence  
**Entreprise :** NEXUS CORP TECHNOLOGIES  
**Produit :** NEXUS  
**Document parent :** NEXUS — Vision & Spécification du Produit — Document Maître v5.3

> Ce document définit l'architecture technique cible de Nexus. Il traduit la vision du Document Maître en composants logiciels, services, flux de données, mécanismes d'orchestration, intégrations providers, sécurité, conformité, exécution et réconciliation.

---

# 0. STATUT ET RÈGLES NORMATIVES

Les termes suivants sont normatifs :

- **MUST / DOIT** : obligatoire ;
- **MUST NOT / NE DOIT PAS** : interdit ;
- **SHOULD / DEVRAIT** : fortement recommandé ;
- **MAY / PEUT** : optionnel.

Toute implémentation financière critique doit être :

- déterministe ;
- idempotente ;
- observable ;
- auditée ;
- versionnée ;
- testable ;
- réconciliable ;
- réversible lorsque le rail le permet.

Une sortie d'IA ne constitue jamais, à elle seule, une autorisation financière.

---

# PARTIE I — OBJECTIF ARCHITECTURAL

## 1. Mission de l'architecture

L'architecture Nexus doit permettre à une plateforme unique de connecter plusieurs infrastructures financières et de transformer une intention utilisateur en opération financière exécutable.

L'architecture doit notamment permettre :

- de gérer des comptes Personal ;
- de gérer des comptes Business ;
- de gérer plusieurs devises ;
- de connecter des comptes et wallets ;
- d'envoyer de l'argent internationalement ;
- de recevoir de l'argent ;
- de financer une transaction depuis plusieurs types de sources ;
- de sélectionner une destination bancaire ou wallet ;
- de comparer plusieurs routes ;
- de permettre à l'utilisateur de choisir une route ;
- d'exécuter cette route ;
- de suivre son état ;
- de gérer les erreurs ;
- de réconcilier les résultats ;
- de gérer les obligations financières dans le ledger ;
- d'intégrer des providers interchangeables ;
- d'utiliser l'IA comme couche d'intelligence et d'assistance.

---

# 2. Principe architectural fondamental

Nexus n'est pas construit autour d'un provider.

Il est construit autour de **capacités financières abstraites**.

```text
                         NEXUS
                           │
                    NEXUS CORE
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
     PERSONAL           BUSINESS           CONNECT
        │                  │                  │
        └──────────────────┼──────────────────┘
                           │
                    NEXUS INTELLIGENCE
                           │
             ┌─────────────┼─────────────┐
             │             │             │
          ROUTING         RISK           AI
             │             │             │
             └─────────────┼─────────────┘
                           │
                    PROVIDER NETWORK
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
       BANK             PAYMENT            CRYPTO
        │                  │                  │
      BaaS/EMI          PSP/MoMo          Ramp/CEX
```

Le cœur de Nexus ne doit donc jamais dépendre directement de l'API d'un provider particulier.

---

# PARTIE II — ARCHITECTURE GLOBALE

# 3. Vue logique

L'architecture cible est organisée en couches.

```text
┌─────────────────────────────────────────────────────────────┐
│                        EXPERIENCE                            │
│                                                             │
│ Web App │ Mobile App │ Business Workspace │ Admin Console  │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                         NEXUS API                            │
│                                                             │
│ Authentication │ Authorization │ Rate Limit │ API Gateway   │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                      NEXUS CORE                              │
│                                                             │
│ Accounts │ Wallets │ Transfers │ Payments │ Beneficiaries  │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                  NEXUS ORCHESTRATION                         │
│                                                             │
│ Intent │ Capability │ Quote │ Routing │ Optimization        │
│ Policy │ Risk │ Execution │ Recovery │ Reconciliation      │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                   NEXUS INTELLIGENCE                         │
│                                                             │
│ AI │ Analytics │ Predictions │ Recommendations │ Pro       │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                     PROVIDER LAYER                           │
│                                                             │
│ Banking │ PSP │ FX │ Mobile Money │ Crypto │ Cards         │
└─────────────────────────────────────────────────────────────┘
```

---

# 4. Principes d'architecture

L'architecture DOIT respecter :

### 4.1 Provider abstraction

Aucun provider ne doit être directement exposé aux couches métier.

### 4.2 Separation of concerns

Les responsabilités suivantes doivent être séparées :

- identité ;
- conformité ;
- routing ;
- risque ;
- exécution ;
- ledger ;
- settlement ;
- reporting ;
- IA.

### 4.3 Event-driven architecture

Les événements financiers importants doivent être propagés par un système d'événements.

### 4.4 API-first

Toutes les capacités centrales doivent être accessibles via des interfaces internes versionnées.

### 4.5 Security-by-design

La sécurité doit être intégrée à chaque couche.

### 4.6 Auditability

Chaque décision importante doit pouvoir être reconstruite a posteriori.

---

# PARTIE III — NEXUS CORE

# 5. Nexus Core

Nexus Core constitue le cœur transactionnel de la plateforme.

Il regroupe notamment :

```text
NEXUS CORE
│
├── Identity
├── Accounts
├── Wallets
├── Beneficiaries
├── Transfers
├── Payments
├── Funding
├── Transactions
├── Ledger
├── Settlement
├── Reconciliation
├── Notifications
└── Audit
```

Nexus Core ne décide pas seul quelle route financière utiliser.

Il fournit l'état et les primitives nécessaires à l'orchestration.

---

# 6. Account Service

Le Account Service gère :

- Personal Account ;
- Business Account ;
- utilisateurs ;
- organisations ;
- profils ;
- statuts ;
- permissions ;
- relations utilisateur/organisation.

## 6.1 Personal

```text
PERSONAL ACCOUNT
│
├── User
├── KYC
├── Wallets
├── Accounts
├── Beneficiaries
├── Transfers
├── Cards
└── Nexus Pro
```

## 6.2 Business

```text
BUSINESS ACCOUNT
│
├── Organization
├── KYB
├── Members
├── Roles
├── Wallets
├── Accounts
├── Beneficiaries
├── Approval Workflows
├── Treasury
├── Payments
├── Cards
└── Nexus Pro
```

---

# 7. Identity Service

Le Identity Service gère :

- authentification ;
- sessions ;
- MFA ;
- appareils ;
- récupération de compte ;
- OAuth/OIDC ;
- tokens ;
- permissions.

Il doit être séparé du système KYC.

---

# PARTIE IV — SUMSUB ET COMPLIANCE

# 8. Sumsub Integration Layer

Sumsub constitue le provider de référence pour les processus KYC/KYB de Nexus, sous réserve de validation contractuelle, réglementaire et technique.

Architecture :

```text
NEXUS
  │
  ▼
COMPLIANCE SERVICE
  │
  ▼
SUMSUB ADAPTER
  │
  ▼
SUMSUB
```

Nexus ne doit pas appeler directement Sumsub depuis les interfaces utilisateur.

Toutes les interactions doivent passer par un service d'intégration contrôlé.

---

# 9. KYC Flow

```text
ACCOUNT CREATED
       ↓
KYC REQUIRED
       ↓
SUMSUB SESSION
       ↓
DOCUMENT VERIFICATION
       ↓
LIVENESS / BIOMETRIC CHECK
       ↓
SCREENING
       ↓
SUMSUB RESULT
       ↓
NEXUS COMPLIANCE DECISION
       ↓
ACTIVE / REVIEW / REJECTED
```

Le résultat de Sumsub ne doit pas être considéré comme l'unique décision réglementaire de Nexus.

---

# 10. KYB Flow

Pour Business :

```text
BUSINESS CREATED
       ↓
KYB
       ↓
COMPANY VERIFICATION
       ↓
UBO IDENTIFICATION
       ↓
DIRECTOR / REPRESENTATIVE
       ↓
SCREENING
       ↓
COMPLIANCE REVIEW
       ↓
BUSINESS ACTIVATED
```

---

# 11. Compliance State Machine

```text
NOT_STARTED
     ↓
PENDING
     ↓
VERIFICATION
     ↓
APPROVED
     │
     ├── REVIEW_REQUIRED
     │
     └── REJECTED
```

Un compte non conforme ne doit pas pouvoir accéder à une fonctionnalité réglementée.

---

# PARTIE V — WALLET ARCHITECTURE

# 12. Wallet Service

Le Wallet Service doit permettre une représentation unifiée des actifs et comptes accessibles à l'utilisateur.

Exemple :

```text
NEXUS WALLET

EUR
€2,500

USD
$1,200

XAF
1,500,000 XAF

USDT
1,000

USDC
500
```

Mais le système doit distinguer :

```text
DISPLAY BALANCE
AVAILABLE BALANCE
PENDING BALANCE
RESERVED BALANCE
IN_TRANSIT
SETTLEMENT_PENDING
```

---

# 13. Wallet ne signifie pas nécessairement custody

Nexus doit distinguer :

```text
NEXUS VIEW
      │
      ├── External Bank Account
      ├── External Wallet
      ├── Provider Account
      └── Nexus-controlled balance
```

Une interface wallet peut donc représenter des fonds détenus chez différents partenaires sans que Nexus soit juridiquement le dépositaire de ces fonds.

---

# 14. Wallet multi-currency

Chaque wallet doit posséder :

- wallet_id ;
- currency ;
- asset_type ;
- provider ;
- provider_account_id ;
- custody_model ;
- balance ;
- available_balance ;
- pending_balance ;
- status.

Exemple :

```json
{
  "wallet_id": "wal_123",
  "currency": "EUR",
  "asset_type": "FIAT",
  "provider": "provider_x",
  "custody_model": "PARTNER_CUSTODY"
}
```

---

# 14 bis. Hold Lifecycle & Expiration automatique

## 14 bis.1 Définition

Un **hold** (réservation de fonds) est une opération `wallet_operations` de
type `hold`, statut `pending`, qui :

- diminue `available_balance` ;
- augmente `hold_balance` ;
- **n'écrit aucune entrée au ledger** (aucun effet comptable net à la création) ;

Il est ensuite soit :

- **capturé** (`completed`) → débit permanent au ledger, `hold_balance` et
  `balance` diminués, `available_balance` inchangé ;
- **libéré** (`cancelled`) → `available_balance` restauré, `hold_balance`
  diminué, aucune écriture au ledger ;
- **expiré** (automatique) → équivalent comptable d'une libération.

## 14 bis.2 Invariants

```text
balance = available_balance + hold_balance   (projection 2 dp)

wallet_operations.status ∈ {pending, completed, cancelled}
  - une capture ne s'applique que si status = pending ;
  - une libération ne s'applique que si status = pending ;
  - une expiration ne s'applique que si status = pending ET expires_at <= NOW().

Exactement UN effet comptable par hold :
  - idempotency_key déterministe (`expire-hold-{operation_id}` pour le worker) ;
  - SELECT ... FOR UPDATE sur l'opération ET le wallet dans une transaction ;
  - garde-fou de tolérance HOLD_PROJECTION_TOLERANCE (0.01) : la projection
    2 dp peut être arrondie vers le bas par rapport au montant exact 8 dp.
```

## 14 bis.3 Précision 8 dp

Les montants sont stockés en `DECIMAL(20,8)` et manipulés en strings décimales
(`bcadd`/`bcsub`) — jamais de flottants. La projection wallet (`balance`,
`available_balance`, `hold_balance`) reste en `DECIMAL(20,2)` pour l'affichage ;
les écritures ledger et `wallet_operations.source_amount` conservent la
précision 8 dp exacte.

## 14 bis.4 Expiration (worker)

- `expires_at` : `created_at + HOLD_TTL_SECONDS` (constant config, défaut
  1800 s = 30 min).
- Worker `scripts/expire_holds.php` : sélectionne les holds
  `type='hold' AND status='pending' AND expires_at <= NOW()`, puis appelle
  `WalletService::releaseHold()` avec la clé `expire-hold-{operation_id}`.
- La boucle `scripts/expire_holds_loop.php` exécute le worker toutes les
  `EXPIRE_HOLD_INTERVAL_SECONDS` secondes (défaut 60 s).
- Deux workers concurrents sur le même hold → **un seul** effet comptable
  (idempotence + statut vérifié sous `FOR UPDATE`).

## 14 bis.5 API

- `POST /api/wallets/hold` — créer un hold.
- `POST /api/wallets/hold/capture` — capturer.
- `POST /api/wallets/hold/release` — libérer.
- `GET /api/wallets/holds?status=pending` — lister les holds de l'utilisateur
  (isolation stricte par `user_id` ; `remaining_seconds` calculé en UTC).

Détail complet : [`docs/api/hold.md`](api/hold.md).

---

# PARTIE VI — FUNDING ENGINE

# 15. Funding Engine

Le Funding Engine constitue un élément essentiel de la vision Nexus.

Il répond à :

> **Depuis quelle source la transaction sera-t-elle financée ?**

Sources possibles :

```text
BANK ACCOUNT
CARD
MOBILE MONEY
FIAT WALLET
CRYPTO WALLET
VIRTUAL ACCOUNT
OTHER SUPPORTED RAIL
```

---

# 16. Funding Flow

Exemple :

```text
USER
 │
 │ wants to send 500 EUR
 ▼
NEXUS
 │
 ▼
SELECTED ROUTE
 │
 ▼
FUNDING ENGINE
 │
 ├── EUR WALLET
 ├── BANK ACCOUNT
 ├── CARD
 └── OTHER RAIL
 │
 ▼
PROVIDER
 │
 ▼
EXECUTION
```

Le Funding Engine doit vérifier :

- solde ;
- devise ;
- disponibilité ;
- limites ;
- KYC/KYB ;
- risque ;
- provider ;
- frais ;
- autorisation.

---

# 17. Funding Authorization

Avant tout débit :

```text
QUOTE
 ↓
USER CONFIRMATION
 ↓
FUNDING AUTHORIZATION
 ↓
SOURCE VALIDATION
 ↓
DEBIT / RESERVATION
 ↓
EXECUTION
```

Le système doit empêcher qu'une transaction soit exécutée sans financement autorisé.

---

# PARTIE VII — DESTINATION ENGINE

# 18. Destination Service

Le Destination Service gère les différentes destinations financières.

```text
DESTINATION
│
├── BANK ACCOUNT
├── IBAN
├── MOBILE MONEY
├── CRYPTO WALLET
├── CARD
├── VIRTUAL ACCOUNT
└── OTHER SUPPORTED RAIL
```

---

# 19. Exemple

Utilisateur :

> Envoyer 500 EUR vers le Congo.

Destination :

```text
COUNTRY
CG

DESTINATION TYPE
MOBILE MONEY

NETWORK
SUPPORTED NETWORK

RECIPIENT
+242XXXXXXXX
```

Le Routing Engine recherche ensuite les providers capables de réaliser cette opération.

---

# PARTIE VIII — PROVIDER ABSTRACTION

# 20. Provider Adapter Architecture

Chaque provider doit être encapsulé derrière un adapter.

```text
NEXUS CORE
     │
     ▼
PROVIDER INTERFACE
     │
 ┌───┼────┬──────┐
 ▼   ▼    ▼      ▼
A1   A2   A3     A4
 │   │    │      │
P1   P2   P3     P4
```

Exemple :

```text
ProviderInterface

createQuote()
getCapabilities()
createPayment()
getPaymentStatus()
cancelPayment()
refundPayment()
getBalance()
getAccount()
```

Le métier Nexus ne doit pas dépendre des méthodes spécifiques d'un provider.

---

# 21. Provider Registry

Le registry contient :

```text
Provider
├── Identity
├── Capabilities
├── Countries
├── Currencies
├── Payment Rails
├── Payout Rails
├── Limits
├── Fees
├── SLA
├── Compliance Requirements
├── API Version
├── Health
├── Reliability Score
└── Status
```

---

# 22. Provider Health

Chaque provider doit avoir un état :

```text
ACTIVE
DEGRADED
PAUSED
MAINTENANCE
DISABLED
```

Un provider `DEGRADED` peut être exclu des routes prioritaires.

Un provider `PAUSED` ne doit pas recevoir de nouvelles transactions.

---

# PARTIE IX — INTELLIGENT ROUTING ENGINE

# 23. Routing Engine

Le Routing Engine constitue l'un des composants les plus importants de Nexus.

Il ne répond pas uniquement à :

> « Quel provider est disponible ? »

Il répond à :

> **« Quelles routes admissibles permettent de réaliser cette intention, et laquelle répond le mieux aux préférences de l'utilisateur ? »**

---

# 24. Routing Pipeline

```text
USER INTENT
    ↓
CAPABILITY CHECK
    ↓
COMPLIANCE FILTER
    ↓
RISK FILTER
    ↓
PROVIDER DISCOVERY
    ↓
QUOTE COLLECTION
    ↓
ROUTE CONSTRUCTION
    ↓
ROUTE SCORING
    ↓
ROUTE EXPLANATION
    ↓
USER SELECTION
    ↓
EXECUTION
```

---

# 25. Exemple de routes

Utilisateur :

```text
FROM: EUR
TO: XAF
AMOUNT: 500
DESTINATION: Mobile Money
```

Nexus peut produire :

```text
ROUTE A
EUR
 ↓
Provider A
 ↓
Mobile Money
```

```text
ROUTE B
EUR
 ↓
Provider B
 ↓
Provider C
 ↓
Mobile Money
```

```text
ROUTE C
EUR
 ↓
Bank Rail
 ↓
Mobile Money Aggregator
```

Chaque route possède son propre coût, délai, fiabilité et état de conformité.

---

# 26. Route Object

```json
{
  "route_id": "route_123",
  "source": {
    "currency": "EUR",
    "country": "FR"
  },
  "destination": {
    "currency": "XAF",
    "country": "CG",
    "type": "MOBILE_MONEY"
  },
  "providers": [
    "provider_a",
    "provider_b"
  ],
  "fees": 4.50,
  "estimated_delivery": "10m",
  "reliability_score": 0.97,
  "status": "ELIGIBLE"
}
```

---

# 27. Route Selection

Nexus doit présenter :

```text
RECOMMENDED

Receive:
XXX XAF

Fees:
X EUR

Estimated delivery:
10 minutes

Reliability:
97%

Route:
Provider A → Provider B

[SELECT]
```

Puis :

```text
ALTERNATIVES

CHEAPER
FASTER
MORE RECEIVED
MORE RELIABLE
```

L'utilisateur conserve la capacité de choisir parmi les routes admissibles lorsque le modèle réglementaire le permet.

---

# PARTIE X — QUOTE ENGINE

# 28. Quote Aggregation

Le Quote Engine interroge plusieurs providers en parallèle lorsque possible.

```text
                QUOTE REQUEST
                     │
          ┌──────────┼──────────┐
          ▼          ▼          ▼
      Provider A Provider B Provider C
          │          │          │
          └──────────┼──────────┘
                     ▼
              NORMALIZATION
                     ↓
                 COMPARISON
```

---

# 29. Quote Object

Une quote doit inclure :

```text
quote_id
provider_id
route_id
source_amount
source_currency
destination_amount
destination_currency
fees
spread
exchange_rate
estimated_delivery
expires_at
created_at
conditions
```

Une quote expirée ne peut pas être utilisée pour exécuter une transaction sans nouvelle validation.

---

# PARTIE XI — NEXUS AI

# 30. AI Architecture

Nexus AI est une couche d'intelligence placée au-dessus des moteurs opérationnels.

```text
                   NEXUS AI
                      │
      ┌───────────────┼────────────────┐
      │               │                │
  Understanding   Analytics       Recommendations
      │               │                │
      └───────────────┼────────────────┘
                      │
              Deterministic Engines
                      │
              Execution / Risk
```

L'IA ne remplace pas les contrôles déterministes.

---

# 31. AI Use Cases

Nexus AI peut assister :

- compréhension de l'intention ;
- explication des routes ;
- analyse des coûts ;
- analyse des spreads ;
- détection d'anomalies ;
- prédiction de délais ;
- analyse de performance provider ;
- recherche d'opportunités ;
- assistance Business ;
- reporting ;
- support.

---

# 32. AI Guardrails

L'IA ne peut pas seule :

- débiter un compte ;
- changer un bénéficiaire ;
- modifier un montant ;
- modifier une devise ;
- désactiver un contrôle ;
- contourner KYC ;
- contourner AML ;
- sélectionner automatiquement une route interdite ;
- autoriser une transaction bloquée.

Toute action financière doit passer par les services déterministes correspondants.

---

# PARTIE XII — POLICY & RISK ENGINE

# 33. Policy Engine

Le Policy Engine vérifie les règles avant l'exécution.

```text
Transaction
    ↓
Policy Engine
    ├── Jurisdiction
    ├── KYC/KYB
    ├── AML
    ├── Limits
    ├── Provider Rules
    ├── Asset Rules
    └── Internal Policies
```

Résultat :

```text
APPROVED
DECLINED
REVIEW_REQUIRED
```

---

# 34. Risk Engine

Le Risk Engine calcule le risque transactionnel.

Variables :

- profil utilisateur ;
- montant ;
- fréquence ;
- destination ;
- source ;
- provider ;
- comportement ;
- historique ;
- signaux fraude ;
- risque de corridor ;
- risque de contrepartie.

Le Risk Engine ne doit pas être confondu avec le moteur AML.

---

# PARTIE XIII — EXECUTION ENGINE

# 35. Execution Architecture

Après sélection :

```text
USER SELECTS ROUTE
        ↓
CONFIRMATION
        ↓
FUNDING AUTHORIZATION
        ↓
EXECUTION ENGINE
        ↓
PROVIDER ADAPTER
        ↓
PROVIDER
        ↓
WEBHOOK / POLLING
        ↓
TRANSACTION STATE
```

---

# 36. Transaction State Machine

```text
CREATED
   ↓
QUOTED
   ↓
AUTHORIZED
   ↓
FUNDING
   ↓
PROCESSING
   ↓
PENDING
   ↓
COMPLETED
```

Branches :

```text
FAILED
CANCELLED
EXPIRED
REVERSED
REFUNDED
MANUAL_REVIEW
RECONCILIATION_REQUIRED
```

---

# 37. Idempotency

Toute opération financière doit disposer d'une clé d'idempotence.

```text
idempotency_key
transaction_id
provider_reference
```

Un même ordre ne doit jamais entraîner deux débits simplement parce qu'une requête a été répétée.

---

# PARTIE XIV — RECOVERY ENGINE

# 38. Intelligent Recovery

Le Recovery Engine traite :

- timeout ;
- provider unavailable ;
- webhook missing ;
- duplicate webhook ;
- failed transaction ;
- unknown state ;
- partial completion ;
- refund ;
- route failure.

Il ne doit jamais faire de retry financier aveugle.

---

# 39. Exemple

```text
EXECUTION
   ↓
TIMEOUT
   ↓
UNKNOWN
   ↓
CHECK PROVIDER
   ↓
 ┌───────────────┐
 │               │
SUCCESS         FAILED
 │               │
DONE             ↓
             NEW ROUTE
                ↓
             USER / POLICY
                ↓
             EXECUTION
```

Toute compensation doit être compatible avec le rail concerné et avec le modèle juridique.

---

# PARTIE XV — RECONCILIATION

# 40. Reconciliation Engine

Le moteur compare :

```text
NEXUS
   ↕
PROVIDER
   ↕
BANK / PAYMENT NETWORK
```

Il vérifie :

- montant ;
- statut ;
- frais ;
- référence ;
- settlement ;
- timestamp ;
- devise.

---

# 41. Reconciliation Case

Un écart crée :

```text
CASE_ID
TRANSACTION_ID
PROVIDER
EXPECTED
ACTUAL
DIFFERENCE
STATUS
OWNER
PRIORITY
CREATED_AT
RESOLUTION
```

Les cas critiques doivent être escaladés automatiquement.

---

# PARTIE XVI — LEDGER

# 42. Ledger Architecture

Le ledger est séparé des balances affichées dans l'interface.

```text
TRANSACTION
      ↓
LEDGER EVENTS
      ↓
ACCOUNTING ENTRIES
      ↓
SETTLEMENT
```

Lorsque pertinent, les écritures doivent suivre un modèle double-entry.

---

# 43. Ledger Principles

Le ledger doit être :

- immuable ;
- append-only ;
- auditable ;
- réconciliable ;
- versionné.

Une correction doit créer une nouvelle écriture et non modifier silencieusement l'historique.

---

# PARTIE XVII — SETTLEMENT

# 44. Settlement Engine

Le Settlement Engine gère les obligations entre Nexus et les providers.

Exemple :

```text
USER
 ↓
NEXUS TRANSACTION
 ↓
PROVIDER
 ↓
DESTINATION
 ↓
SETTLEMENT
 ↓
PROVIDER RECONCILIATION
 ↓
NEXUS LEDGER
```

Le settlement doit être distinct de la transaction utilisateur.

Une transaction peut être `COMPLETED` pour l'utilisateur tout en restant en settlement inter-provider.

---

# PARTIE XVIII — NEXUS BUSINESS

# 45. Business Architecture

```text
BUSINESS
 │
 ├── Organization
 ├── Members
 ├── Roles
 ├── Wallets
 ├── Accounts
 ├── Beneficiaries
 ├── Payments
 ├── Transfers
 ├── Treasury
 ├── Cards
 ├── Reports
 └── Nexus Pro
```

---

# 46. Approval Engine

```text
EMPLOYEE
   ↓
CREATE PAYMENT
   ↓
APPROVAL REQUIRED
   ↓
MANAGER
   ↓
RISK / POLICY
   ↓
ROUTING
   ↓
EXECUTION
```

L'initiateur ne doit pas pouvoir approuver seul une opération lorsque la politique impose une séparation des tâches.

---

# PARTIE XIX — NEXUS PRO

# 47. Pro Architecture

Nexus Pro utilise :

- Market Data ;
- Provider Data ;
- Historical Performance ;
- Route Performance ;
- FX Data ;
- Crypto Data ;
- P2P Data lorsque disponible ;
- Analytics ;
- AI.

```text
DATA
 ↓
NORMALIZATION
 ↓
ANALYTICS
 ↓
AI / MODELS
 ↓
OPPORTUNITY ENGINE
 ↓
NEXUS PRO
```

---

# 48. GPM

GPM peut analyser :

```text
BUY PRICE
+ FEES
+ SPREAD
+ GAS
+ WITHDRAWAL
+ FX
+ TRANSFER
+ SLIPPAGE
+ RISK
----------------
REAL COST
```

Puis :

```text
EXIT PRICE
-
REAL COST
=
POTENTIAL OPPORTUNITY
```

Aucune opportunité ne doit être présentée comme un rendement garanti.

---

# PARTIE XX — CARDS

# 49. Card Architecture

Les cartes sont une capacité provider.

```text
NEXUS
 ↓
CARD SERVICE
 ↓
ISSUER / PROCESSOR
 ↓
CARD NETWORK
 ↓
MERCHANT
```

Nexus peut fournir :

- cartes virtuelles ;
- cartes prépayées lorsque juridiquement et techniquement disponibles ;
- cartes mono-usage ;
- cartes programmables ;
- cartes Business.

---

# 50. Card Controls

Contrôles possibles :

- montant ;
- fréquence ;
- marchand ;
- catégorie ;
- pays ;
- utilisateur ;
- période ;
- budget.

Les cartes ne doivent pas être activées dans une juridiction sans validation du modèle d'émission.

---

# PARTIE XXI — API ET NEXUS CONNECT

# 51. API Gateway

Architecture :

```text
CLIENT
 ↓
API GATEWAY
 ↓
AUTH
 ↓
RATE LIMIT
 ↓
TENANT ISOLATION
 ↓
NEXUS SERVICES
```

---

# 52. API Resources

Ressources initiales :

```text
/users
/accounts
/businesses
/wallets
/beneficiaries
/quotes
/routes
/transfers
/payments
/payouts
/collections
/cards
/providers
/webhooks
/reports
```

Les endpoints définitifs seront spécifiés dans le document API dédié.

---

# PARTIE XXII — EVENT BUS

# 53. Event-Driven Architecture

Les événements principaux peuvent inclure :

```text
USER_CREATED
KYC_COMPLETED
KYB_COMPLETED
WALLET_CREATED
QUOTE_CREATED
QUOTE_EXPIRED
ROUTE_SELECTED
TRANSACTION_CREATED
FUNDING_AUTHORIZED
TRANSACTION_PROCESSING
TRANSACTION_COMPLETED
TRANSACTION_FAILED
REFUND_CREATED
SETTLEMENT_COMPLETED
RECONCILIATION_FAILED
PROVIDER_DEGRADED
```

Les événements financiers doivent être immuables et traçables.

---

# PARTIE XXIII — DATABASE ARCHITECTURE

# 54. Domain Separation

Les données doivent être séparées logiquement par domaine :

```text
IDENTITY
COMPLIANCE
CUSTOMER
TRANSACTION
LEDGER
PROVIDER
MARKET
AI
AUDIT
```

Les données critiques ne doivent pas être accessibles directement par n'importe quel service.

---

# 55. Source of Truth

Chaque domaine doit avoir une source de vérité clairement définie.

Exemple :

| Domaine | Source de vérité |
|---|---|
| Identity | Identity Service |
| KYC/KYB | Compliance Service + provider evidence |
| Transaction | Transaction Service |
| Route | Routing Service |
| Quote | Quote Service |
| Ledger | Ledger Service |
| Provider status | Provider Registry |
| Settlement | Settlement Service |
| Audit | Audit Service |

---

# PARTIE XXIV — SÉCURITÉ

# 56. Security Architecture

```text
IDENTITY
   ↓
MFA
   ↓
AUTHORIZATION
   ↓
RBAC / ABAC
   ↓
POLICY
   ↓
TRANSACTION
```

Contrôles :

- MFA ;
- OAuth2/OIDC ;
- chiffrement ;
- secrets management ;
- RBAC ;
- séparation des tâches ;
- rate limiting ;
- anti-replay ;
- signature webhook ;
- audit ;
- monitoring ;
- alerting ;
- backups ;
- disaster recovery.

---

# 57. Secrets

Les credentials providers doivent être stockés dans un système de secrets dédié.

Ils ne doivent jamais apparaître :

- dans le code ;
- dans Git ;
- dans les logs ;
- dans les tickets ;
- dans les réponses API ;
- dans les messages d'erreur.

---

# PARTIE XXV — OBSERVABILITÉ

# 58. Monitoring

Nexus doit surveiller :

```text
APPLICATION
API
DATABASE
QUEUE
PROVIDERS
TRANSACTIONS
ROUTES
WEBHOOKS
LEDGER
SETTLEMENT
RECONCILIATION
AI
```

---

# 59. Core Metrics

KPIs techniques :

- API latency ;
- provider latency ;
- quote latency ;
- transaction success rate ;
- provider success rate ;
- routing success rate ;
- recovery rate ;
- reconciliation success rate ;
- refund time ;
- webhook delay ;
- queue depth ;
- error rate.

---

# PARTIE XXVI — FAILURE DOMAIN

# 60. Provider Failure

Nexus doit isoler les défaillances provider.

```text
PROVIDER A DOWN
      ↓
HEALTH CHECK
      ↓
ROUTE A DISABLED
      ↓
ROUTE B / C
      ↓
USER SELECTION
```

Nexus ne doit pas transférer automatiquement vers une route financière différente sans respecter les règles de confirmation applicables.

---

# 61. Database Failure

Le système doit prévoir :

- backups ;
- réplication ;
- recovery ;
- point-in-time recovery ;
- tests de restauration.

---

# 62. Event Failure

Les événements doivent supporter :

- retries techniques ;
- dead-letter queue ;
- replay contrôlé ;
- déduplication.

Les événements financiers ne doivent jamais être rejoués aveuglément.

---

# PARTIE XXVII — ENVIRONNEMENTS

# 63. Environnements

Minimum :

```text
LOCAL
DEVELOPMENT
STAGING
SANDBOX
PRODUCTION
```

Les credentials et bases de données doivent être séparés.

Les données production ne doivent pas être copiées directement vers des environnements inférieurs.

---

# PARTIE XXVIII — TESTING

# 64. Testing Strategy

Tests :

```text
UNIT
INTEGRATION
CONTRACT
END-TO-END
SECURITY
LOAD
FAILURE
RECONCILIATION
PROVIDER
AI
```

---

# 65. Provider Contract Testing

Chaque adapter doit être soumis à des tests contractuels.

Exemple :

```text
createQuote()
createPayment()
getStatus()
refund()
webhook()
```

Un changement d'API provider ne doit pas casser silencieusement Nexus.

---

# PARTIE XXIX — DEPLOYMENT

# 66. CI/CD

Pipeline :

```text
COMMIT
 ↓
LINT
 ↓
UNIT TEST
 ↓
SECURITY SCAN
 ↓
BUILD
 ↓
INTEGRATION TEST
 ↓
STAGING
 ↓
E2E
 ↓
APPROVAL
 ↓
PRODUCTION
```

Les changements affectant les flux financiers doivent nécessiter une validation renforcée.

---

# 67. Feature Flags

Les fonctionnalités réglementées ou risquées doivent être activables via feature flags.

Exemple :

```text
CRYPTO_ENABLED
CARDS_ENABLED
P2P_ENABLED
CROSS_ASSET_ENABLED
BUSINESS_MASS_PAYMENTS
```

Une feature flag ne doit jamais servir à contourner une obligation réglementaire.

---

# PARTIE XXX — ARCHITECTURE CIBLE DU TRANSFERT

# 68. Exemple complet

Utilisateur Personal :

> « Envoyer 500 EUR vers un Mobile Money au Congo. »

### Étape 1

```text
USER
 ↓
TRANSFER FORM
```

### Étape 2

```text
SOURCE
EUR WALLET
```

### Étape 3

```text
DESTINATION
CG
MOBILE MONEY
```

### Étape 4

```text
INTENT ENGINE
```

### Étape 5

```text
CAPABILITY ENGINE
```

### Étape 6

```text
POLICY / RISK
```

### Étape 7

```text
QUOTE ENGINE
```

### Étape 8

```text
ROUTING ENGINE
```

### Étape 9

```text
ROUTES

A — moins chère
B — plus rapide
C — optimisée
```

### Étape 10

```text
USER SELECTS B
```

### Étape 11

```text
FUNDING ENGINE
```

### Étape 12

```text
EUR WALLET
 ↓
RESERVATION / DEBIT
```

### Étape 13

```text
EXECUTION ENGINE
 ↓
PROVIDER ADAPTER
 ↓
PROVIDER
```

### Étape 14

```text
WEBHOOK
 ↓
TRANSACTION STATE
```

### Étape 15

```text
SETTLEMENT
 ↓
RECONCILIATION
 ↓
LEDGER
```

### Étape 16

```text
USER
 ↓
TRANSFER COMPLETED
```

---

# PARTIE XXXI — ARCHITECTURE BUSINESS DU MÊME FLUX

Une entreprise peut effectuer le même transfert :

```text
BUSINESS WALLET
      ↓
EMPLOYEE
      ↓
PAYMENT REQUEST
      ↓
APPROVAL
      ↓
POLICY
      ↓
ROUTING
      ↓
FUNDING
      ↓
EXECUTION
      ↓
SETTLEMENT
      ↓
RECONCILIATION
      ↓
ACCOUNTING
```

La différence principale entre Personal et Business n'est donc pas le moteur de routing.

Le moteur financier est commun.

La différence porte notamment sur :

- identité ;
- ownership ;
- permissions ;
- approbations ;
- limites ;
- reporting ;
- conformité ;
- trésorerie ;
- workflows.

---

# PARTIE XXXII — ARCHITECTURE FINALE

# 69. Vue consolidée

```text
                         NEXUS PLATFORM
                               │
            ┌──────────────────┴──────────────────┐
            │                                     │
        PERSONAL                               BUSINESS
            │                                     │
            └──────────────────┬──────────────────┘
                               │
                         NEXUS CORE
                               │
       ┌───────────────┬───────┼────────┬──────────────┐
       │               │       │        │              │
    Accounts        Wallets  Funding  Payments      Transfers
       │               │       │        │              │
       └───────────────┴───────┼────────┴──────────────┘
                               │
                     NEXUS ORCHESTRATION
                               │
      ┌────────┬────────┬──────┼──────┬────────┬─────────┐
      │        │        │      │      │        │         │
    Intent Capability Quote Routing Policy Optimization Execution
                                      │
                                   Risk
                                      │
                              Recovery / Reconciliation
                                      │
                                   Ledger
                                      │
                                  Settlement
                                      │
                           PROVIDER NETWORK
                                      │
          ┌───────────────┬───────────┼───────────────┐
          │               │           │               │
       Banking          Payments     FX            Crypto
          │               │           │               │
       BaaS/EMI       PSP/MoMo     FX rails      Ramp/CEX
                                      │
                                Cards / Issuing
                                      │
                              NEXUS INTELLIGENCE
                                      │
                              AI / Analytics / Pro
                                      │
                              NEXUS CONNECT API
```

---

# 70. Principe technique absolu

> **Nexus ne doit jamais être construit comme une collection d'intégrations providers.**

Il doit être construit comme :

```text
FINANCIAL CAPABILITIES
        ↓
NEXUS ABSTRACTION
        ↓
INTELLIGENT ROUTING
        ↓
USER / BUSINESS DECISION
        ↓
FUNDING
        ↓
EXECUTION
        ↓
SETTLEMENT
        ↓
RECONCILIATION
        ↓
LEARNING DATA
        ↓
NEXUS INTELLIGENCE
```

Les providers alimentent le réseau.

Les données alimentent l'intelligence.

Les moteurs déterministes contrôlent les opérations.

L'IA améliore la compréhension, l'analyse et la recommandation.

Le ledger garantit la traçabilité.

La réconciliation garantit la cohérence.

---

# 71. Définition technique finale

**Nexus est une plateforme d'orchestration financière multi-provider dont l'architecture sépare strictement l'expérience utilisateur, les capacités financières, le routing, le risque, l'exécution, le funding, le settlement et le ledger.**

Un utilisateur Personal ou Business peut sélectionner une source financière et une destination.

Nexus identifie les rails admissibles, interroge les providers disponibles, construit les routes, les compare, les explique et les soumet à la sélection de l'utilisateur ou au workflow d'approbation Business.

Après validation, Nexus utilise le Funding Engine pour obtenir ou réserver les fonds depuis le rail sélectionné, puis l'Execution Engine orchestre la transaction via l'adapter provider approprié.

La transaction est ensuite suivie, réconciliée et inscrite dans les systèmes financiers internes.

**Nexus AI et Nexus Pro apportent l'intelligence nécessaire à l'analyse, à la recommandation, à la détection d'anomalies et à l'optimisation, sans remplacer les contrôles déterministes et réglementaires.**

---

# STATUT DU DOCUMENT

**Document :** NEXUS — Architecture Technique Détaillée  
**Numéro :** Document Technique 01  
**Version :** 1.0  
**Parent :** Document Maître v5.3  
**Statut :** Architecture technique cible  
**Classification :** Référence technique interne  
**KYC/KYB :** Sumsub — sous réserve de validation  
**Comptes :** Personal / Business  
**Intelligence :** Nexus AI / Nexus Pro  
**Core :** Nexus Core  
**API :** Nexus Connect

## Statut recommandé

**APPROUVÉ COMME ARCHITECTURE CIBLE.**

La mise en œuvre doit être précédée par les documents techniques spécialisés portant notamment sur :

1. Architecture des microservices ;
2. Modèle de données ;
3. API Specification ;
4. Provider Adapter Specification ;
5. Routing Engine Specification ;
6. Ledger & Accounting Specification ;
7. KYC/KYB & Compliance Specification ;
8. Security Architecture ;
9. Transaction State Machine ;
10. Reconciliation Specification ;
11. Nexus AI Architecture ;
12. Infrastructure & DevOps ;
13. Observability ;
14. Disaster Recovery ;
15. Business Architecture ;
16. Card Architecture ;
17. Crypto & Stablecoin Architecture ;
18. Nexus Connect API.

**Fin du Document Technique 01.**