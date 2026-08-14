# NEXUS TECHNOLOGIES — VISION GLOBALE (v6)

> **Document canonique de vision produit — source de vérité.**
> **Nexus Pro est définitivement supprimé.** Structure officielle : **Personal + Business + Connect** autour d'un **Nexus Core** unique.
>
> Date d'intégration : août 2026 · Statut : source de vérité

---

## 1. OBJECTIF

Tu travailles sur **Nexus Technologies**, une plateforme d'orchestration financière multi-providers.

La vision fondamentale à respecter est la suivante :

> **Nexus permet à un particulier ou à une entreprise de définir une intention financière, puis détermine et exécute automatiquement la meilleure route disponible pour atteindre cette intention.**

Nexus n'est pas simplement :

* une banque ;
* un wallet ;
* une application de transfert ;
* un exchange ;
* un PSP ;
* une carte bancaire.

Nexus est la **couche d'orchestration située au-dessus de ces infrastructures**.

L'utilisateur indique ce qu'il veut faire.

Nexus détermine comment le faire de manière optimale.

---

## 2. PHILOSOPHIE CENTRALE

L'utilisateur ne devrait pas avoir à connaître les providers disponibles ni leur architecture technique.

Exemple :

> « Je veux envoyer 1 000 EUR au Congo et que le bénéficiaire reçoive le maximum. »

Nexus doit être capable de déterminer :

* les sources de financement disponibles ;
* les providers compatibles ;
* les corridors disponibles ;
* les taux de change ;
* les frais ;
* les limites ;
* le délai estimé ;
* la fiabilité ;
* les contraintes réglementaires ;
* le risque ;
* la meilleure route.

Puis Nexus construit et exécute cette route.

Le principe produit est :

**USER INTENT → NEXUS INTELLIGENCE → OPTIMAL ROUTE → EXECUTION → SETTLEMENT → LEDGER**

---

## 3. NEXUS CORE

Toutes les expériences Personal et Business doivent utiliser le même moteur central.

Architecture conceptuelle :

```text
                    NEXUS
        Financial Orchestration Platform
                         │
                         ▼
                  NEXUS CORE ENGINE
                         │
        ┌────────────────┼────────────────┐
        ▼                ▼                ▼
 Capability           Quote            Routing
 Engine               Engine            Engine
        │                │                │
        └────────────────┼────────────────┘
                         ▼
                    Policy Engine
                         │
                         ▼
                Funding Source Engine
                         │
                         ▼
                   Execution Engine
                         │
                         ▼
                  Self-Healing /
                    Re-Routing
                         │
                         ▼
                     Ledger
                         │
                         ▼
                Provider Network
```

Le Core doit être indépendant de l'interface utilisateur.

---

## 4. CAPABILITY ENGINE

Le Capability Engine détermine ce que Nexus peut réellement faire.

Il doit tenir compte de :

* pays ;
* devise ;
* source de financement ;
* destination ;
* provider ;
* type de compte ;
* KYC/KYB ;
* limites ;
* réglementation ;
* disponibilité du provider ;
* disponibilité du corridor ;
* type d'actif ;
* méthode de paiement.

Nexus ne doit jamais proposer une opération que l'utilisateur ne peut réellement exécuter.

---

## 5. QUOTE ENGINE

Le Quote Engine calcule les différentes possibilités.

Il doit notamment prendre en compte :

* taux de change ;
* frais Nexus ;
* frais provider ;
* spread ;
* frais de réseau ;
* frais de retrait ;
* frais de conversion ;
* montant source ;
* montant destination ;
* montant net reçu ;
* ETA ;
* expiration du quote.

Le résultat doit permettre de comparer les routes.

---

## 6. ROUTING ENGINE

Le Routing Engine est l'un des composants stratégiques de Nexus.

Il détermine la route optimale entre :

**SOURCE → INTERMEDIATE RAILS → DESTINATION**

Il doit pouvoir composer plusieurs étapes lorsque cela est nécessaire.

Exemple conceptuel :

```text
EUR Bank Account
      ↓
Provider A
      ↓
FX Provider
      ↓
Provider B
      ↓
Mobile Money Congo
      ↓
Beneficiary
```

Le Routing Engine doit pouvoir choisir entre plusieurs stratégies :

### Optimized

Compromis optimal entre :

* coût ;
* vitesse ;
* montant reçu ;
* fiabilité.

### Fastest

Priorité au temps d'exécution.

### Cheapest

Priorité au coût total.

### Max Received

Priorité au montant net reçu par le bénéficiaire.

### Most Reliable

Priorité à la probabilité de réussite.

Le mode par défaut doit être **Optimized**.

---

## 7. EXECUTION ENGINE

Une fois la route sélectionnée, Nexus doit pouvoir l'exécuter.

L'exécution doit gérer :

* idempotency ;
* validation ;
* réservation / hold ;
* exécution des étapes ;
* confirmation ;
* settlement ;
* ledger updates ;
* erreurs ;
* retry ;
* rollback lorsque possible ;
* compensation ;
* timeout.

Le système doit suivre une logique de **Saga / multi-step execution**.

---

## 8. SELF-HEALING

Nexus doit pouvoir réagir lorsqu'un provider devient indisponible ou qu'une étape échoue.

Exemple :

```text
Route A
Provider A
    ↓
FAILED

Nexus détecte l'échec
    ↓
Recherche une route alternative
    ↓
Provider B
    ↓
Continuation de l'exécution
```

L'objectif est que l'utilisateur n'ait pas à gérer manuellement les problèmes d'infrastructure.

---

## 9. LEDGER ET COMPTABILITÉ

Le système financier doit être construit autour d'une comptabilité fiable.

Le ledger doit permettre de maintenir les invariants financiers.

Pour les wallets :

```text
available_balance
=
total_balance
-
held_amounts
```

Aucune opération financière ne doit modifier arbitrairement un solde sans journalisation comptable.

Prévoir notamment :

* double-entry ledger ;
* ledger accounts ;
* journal entries ;
* holds ;
* captures ;
* releases ;
* settlements ;
* balance history ;
* reconciliation ;
* provider reconciliation ;
* idempotency ;
* audit trail.

---

## 10. PROVIDER NETWORK

Nexus doit être conçu comme une plateforme multi-providers.

Les providers peuvent appartenir à plusieurs catégories :

* banques ;
* Mobile Money ;
* payment processors ;
* FX providers ;
* crypto providers ;
* card issuers ;
* virtual account providers ;
* international transfer providers.

Exemples de providers potentiels selon les corridors et capacités :

* Stripe ;
* pawaPay ;
* Onafriq ;
* Thunes ;
* Currencycloud ;
* Wise Platform ;
* Nium ;
* Bridge ;
* BVNK ;
* Yellow Card ;
* dLocal ;
* Ebanx ;
* Tazapay ;
* 2C2P ;
* Xendit.

Les intégrations doivent être découplées du Core.

Utiliser une architecture de type :

```text
Provider
   ↓
Adapter
   ↓
Provider Registry
   ↓
Capability Engine
   ↓
Quote Engine
   ↓
Routing Engine
```

Ne jamais coder la logique métier Nexus directement dans un provider spécifique.

---

## 11. NEXUS PERSONAL

Le produit Personal doit donner à l'utilisateur la perception suivante :

> **« Nexus gère intelligemment mon argent. »**

Le dashboard personnel doit être simple, clair et orienté action.

### Dashboard

Afficher notamment :

* Total Balance / Net Worth ;
* Available Balance ;
* Pending ;
* In Transit ;
* Held ;
* Settlement ;
* wallets ;
* transactions récentes ;
* activités importantes ;
* taux de change pertinents ;
* alertes.

### Wallets

Supporter :

* EUR ;
* USD ;
* XAF ;
* autres monnaies fiat ;
* crypto/stablecoins lorsque les rails sont activés.

Chaque wallet doit permettre :

* Send ;
* Receive ;
* Convert ;
* Deposit ;
* Withdraw ;
* History.

### Funding Sources

Permettre de gérer :

* comptes bancaires ;
* cartes ;
* Mobile Money ;
* comptes de paiement ;
* IBAN ;
* autres sources compatibles.

Une source doit avoir un statut de vérification.

---

## 12. RÈGLE IMPORTANTE DU SEND PERSONAL

Le pays d'origine ne doit PAS être un simple champ libre.

Nexus doit déterminer les pays d'origine disponibles à partir des sources de financement réellement vérifiées.

Exemple :

```text
Utilisateur enregistré au Congo

Sources vérifiées :
Congo Bank Account
Congo Mobile Money

Available origins :
Congo
```

Si l'utilisateur possède également une source vérifiée en France :

```text
Available origins :
Congo
France
```

L'utilisateur ne doit pas pouvoir sélectionner arbitrairement :

* Ghana ;
* Kenya ;
* Nigeria ;
* Côte d'Ivoire ;
* etc.

s'il ne dispose pas d'une source de financement vérifiée permettant réellement d'initier l'opération depuis ce pays.

Cette règle doit être appliquée dans :

* UI ;
* API ;
* Capability Engine ;
* Routing Engine ;
* Execution Engine.

La sécurité ne doit jamais dépendre uniquement du frontend.

---

## 13. SEND PERSONAL

Le Send Wizard doit être intégré au Routing Engine.

L'utilisateur indique :

```text
Amount
Source
Destination
Beneficiary
Currency
Purpose
Optimization preference
```

Nexus retourne ensuite les routes disponibles.

Exemple :

```text
OPTIMIZED

Send:
€1,000 EUR

Recipient receives:
655,000 XAF

Fee:
€12

ETA:
2–5 min

Reliability:
98%

Route:
Provider A → FX Provider → Mobile Money
```

L'utilisateur doit pouvoir comparer les alternatives :

* Optimized ;
* Fastest ;
* Cheapest ;
* Max Received ;
* Most Reliable.

---

## 14. RECEIVE PERSONAL

Receive doit permettre :

* bank transfer ;
* IBAN ;
* Mobile Money ;
* crypto wallet ;
* payment link lorsque disponible ;
* QR code ;
* coordonnées adaptées à la devise.

---

## 15. CONVERT PERSONAL

Convert doit utiliser le Quote Engine et le Routing Engine.

Ne pas faire simplement :

```text
EUR → XAF
```

mais :

```text
EUR
 ↓
Available conversion routes
 ↓
Compare rates + fees + spread
 ↓
Best route
 ↓
Execute
 ↓
Update wallets + ledger
```

---

## 16. NEXUS BUSINESS

Le Business Dashboard doit communiquer :

> **« Nexus est l'infrastructure financière opérationnelle de mon entreprise. »**

Le dashboard Business doit être plus puissant que Personal.

### Financial Overview

Afficher :

* Total Assets ;
* Available Balance ;
* Pending ;
* In Transit ;
* Settlement ;
* Receivables ;
* Payables ;
* Transaction Volume ;
* Fees ;
* FX Exposure.

---

## 17. BUSINESS TREASURY

Le Business doit pouvoir gérer :

* plusieurs wallets ;
* plusieurs devises ;
* liquidités ;
* transferts internes ;
* conversions ;
* settlement ;
* positions FX ;
* flux entrants ;
* flux sortants.

---

## 18. BUSINESS PAYMENTS

Fonctionnalités :

* supplier payments ;
* employee payments ;
* payroll ;
* bulk payments ;
* customer refunds ;
* recurring payments ;
* international transfers.

Avec workflow :

```text
Create Payment
      ↓
Approval
      ↓
Nexus Routing
      ↓
Execution
      ↓
Settlement
      ↓
Ledger
```

---

## 19. BUSINESS ROLES & PERMISSIONS

Prévoir :

* Owner ;
* Admin ;
* Finance Manager ;
* Accountant ;
* Operator ;
* Viewer.

Les permissions doivent contrôler :

* consultation ;
* création ;
* approbation ;
* exécution ;
* gestion des bénéficiaires ;
* gestion des sources ;
* gestion des wallets ;
* exports ;
* API ;
* paramètres.

---

## 20. BUSINESS ROUTING INTELLIGENCE

Le Business Dashboard doit exposer la puissance d'orchestration de Nexus.

Afficher :

* volume traité ;
* provider utilisé ;
* taux de réussite ;
* latence ;
* frais ;
* économies réalisées ;
* routes utilisées ;
* rerouting ;
* provider performance.

Exemple :

```text
Processed:
€2.4M

Successful:
99.2%

Routing Savings:
€38,420

Average Execution Time:
2.4 sec
```

---

## 21. BUSINESS RECONCILIATION

Prévoir :

* ledger ;
* settlements ;
* provider reconciliation ;
* unmatched transactions ;
* accounting entries ;
* balance history ;
* exports ;
* reports.

Le Business doit pouvoir rapprocher les mouvements Nexus avec les mouvements des providers.

---

## 22. BUSINESS COMPLIANCE

Prévoir :

* KYB ;
* KYC des utilisateurs concernés ;
* AML ;
* transaction monitoring ;
* sanctions screening ;
* risk scoring ;
* limits ;
* verification status ;
* audit logs.

---

## 23. BUSINESS ANALYTICS

Les dashboards doivent permettre d'analyser :

### Volume

Combien l'entreprise traite ?

### Cost

Combien coûtent les opérations ?

### Revenue

Combien Nexus génère ?

### Margin

Quelle est la marge ?

### Savings

Combien Nexus économise grâce au routing ?

### Reliability

Quel est le taux de réussite ?

### FX

Quelle est l'exposition aux devises ?

---

## 24. NEXUS CONNECT

Nexus Connect remplace toute ancienne notion de « Nexus Pro ».

Il ne s'agit PAS d'un produit d'arbitrage.

Nexus Connect est l'offre B2B/API.

Elle permet aux entreprises et plateformes externes d'utiliser l'infrastructure Nexus.

Prévoir :

* REST API ;
* API authentication ;
* API keys ;
* OAuth lorsque nécessaire ;
* webhooks ;
* transaction APIs ;
* quote APIs ;
* routing APIs ;
* wallet APIs ;
* beneficiary APIs ;
* reconciliation APIs ;
* developer portal ;
* API documentation ;
* sandbox.

Exemple :

```text
External Business
       ↓
Nexus API
       ↓
Nexus Core
       ↓
Capability
       ↓
Quote
       ↓
Routing
       ↓
Execution
       ↓
Provider Network
```

---

## 25. CROSS-ASSET

À terme, Nexus doit pouvoir orchestrer différents types d'actifs.

Notamment :

* fiat ;
* crypto ;
* stablecoins ;
* fiat → crypto ;
* crypto → fiat ;
* crypto → crypto ;
* paiements utilisant des rails crypto lorsque légalement et techniquement approprié.

La crypto est une capacité de Nexus.

Elle ne doit pas transformer Nexus en simple exchange crypto.

---

## 26. SMART TREASURY

Phase avancée :

* liquidity forecasting ;
* automated liquidity management ;
* FX optimization ;
* intelligent provider allocation ;
* treasury automation ;
* cash concentration ;
* settlement optimization.

Ces fonctions restent intégrées à Nexus Business.

Elles ne constituent PAS un produit séparé nommé Nexus Pro.

---

## 27. STRUCTURE PRODUIT FINALE

La structure officielle de Nexus doit être :

```text
                         NEXUS
                          │
             Financial Orchestration
                          │
          ┌───────────────┼───────────────┐
          │               │               │
       PERSONAL        BUSINESS        CONNECT
          │               │               │
       Consumer       Companies          API
          │               │               │
          └───────────────┼───────────────┘
                          │
                     NEXUS CORE
```

Il n'existe plus :

```text
Nexus Pro
```

Ne pas créer de navigation, route, dashboard, abonnement, rôle ou fonctionnalité appelée **Nexus Pro**.

---

## 28. ROADMAP OFFICIELLE

La roadmap doit être considérée comme :

### Phase 1 — Foundation

* architecture ;
* sécurité ;
* users ;
* entities ;
* KYC/KYB ;
* ledger ;
* database ;
* audit ;
* licensing/compliance foundation.

### Phase 2 — MVP Financial Orchestration

* wallets ;
* funding sources ;
* Capability Engine ;
* Quote Engine ;
* Routing Engine ;
* Send ;
* Receive ;
* Convert ;
* Execution Engine ;
* Self-Healing ;
* EUR → XAF initial corridor.

### Phase 3 — Personal

* multi-currency ;
* virtual accounts ;
* advanced transfers ;
* cards ;
* personal analytics.

### Phase 4 — Business

* treasury ;
* payments ;
* collections ;
* bulk payments ;
* approvals ;
* teams ;
* accounting ;
* reconciliation ;
* analytics ;
* compliance.

### Phase 5 — Cross-Asset

* crypto ;
* stablecoins ;
* crypto/fiat rails ;
* additional corridors.

### Phase 6 — Smart Treasury

* liquidity optimization ;
* FX optimization ;
* automated treasury ;
* intelligent allocation.

### Phase 7 — Nexus Connect

* API ;
* webhooks ;
* sandbox ;
* developer portal ;
* embedded financial orchestration.

---

## 29. PRINCIPLE UX MAJEUR

L'interface ne doit jamais donner l'impression que l'utilisateur doit comprendre toute l'infrastructure Nexus.

Elle doit masquer la complexité tout en exposant suffisamment d'informations pour inspirer confiance.

Le principe UX est :

```text
USER
"What do I want to achieve?"
        ↓
NEXUS
"What is the best available way?"
        ↓
ROUTING ENGINE
"Which route should be used?"
        ↓
EXECUTION ENGINE
"Execute it reliably."
        ↓
LEDGER
"Record it correctly."
```

---

## 30. CE QUE LES DASHBOARDS DOIVENT COMMUNIQUER

### Personal

Message principal :

> **YOUR MONEY, INTELLIGENTLY ORCHESTRATED.**

Le dashboard doit privilégier :

* simplicité ;
* visibilité ;
* actions ;
* wallets ;
* transfers ;
* conversions ;
* sécurité ;
* transparence des frais ;
* montant réellement reçu.

### Business

Message principal :

> **YOUR FINANCIAL OPERATIONS, ORCHESTRATED BY NEXUS.**

Le dashboard doit privilégier :

* treasury ;
* cash flow ;
* payments ;
* collections ;
* routing ;
* providers ;
* reconciliation ;
* compliance ;
* analytics ;
* team management ;
* API.

---

## 31. RÈGLE ARCHITECTURALE FINALE

Ne pas construire des fonctionnalités isolées uniquement pour remplir les dashboards.

Chaque fonctionnalité visible doit être reliée au Core Nexus.

Architecture cible :

```text
                    FRONTEND
                       │
        ┌──────────────┴──────────────┐
        │                             │
    PERSONAL                       BUSINESS
        │                             │
        └──────────────┬──────────────┘
                       │
                    REST API
                       │
                 NEXUS BACKEND
                       │
       ┌───────────────┼───────────────┐
       │               │               │
  Capability        Quote           Routing
     Engine         Engine           Engine
       │               │               │
       └───────────────┼───────────────┘
                       │
                 Policy Engine
                       │
               Funding Engine
                       │
               Execution Engine
                       │
                Self-Healing
                       │
                    Ledger
                       │
              Provider Adapters
                       │
       ┌───────────────┼───────────────┐
       ▼               ▼               ▼
     Banks          Mobile Money      Crypto
       │               │               │
       └───────────────┼───────────────┘
                       │
                   Settlement
```

---

## INSTRUCTION FINALE À RESPECTER

À partir de maintenant, toute modification du code, du backend, du frontend, des dashboards, des routes, des menus, des permissions, de la base de données ou de la roadmap doit respecter cette vision.

**Nexus Pro est définitivement supprimé.**

Ne pas réintroduire « Nexus Pro » sous un autre nom ou sous forme d'un produit parallèle.

Les fonctionnalités avancées d'optimisation, de routing, de treasury et d'intelligence financière doivent rester des **capacités du Nexus Core ou de Nexus Business**, tandis que **Nexus Connect** constitue l'offre API/B2B.

L'objectif final est de construire **une seule plateforme Nexus cohérente**, avec un Core financier commun et trois surfaces produit :

**Personal + Business + Connect.**
