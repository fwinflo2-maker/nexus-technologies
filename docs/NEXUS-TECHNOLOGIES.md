# NEXUS CORP TECHNOLOGIES
> ⚠️ **DOCUMENT SUPERSÉDÉ — « Nexus Pro » est supprimé.**
> La vision actuelle (v6) est définie dans [./NEXUS-VISION.md](./NEXUS-VISION.md) — structure **Personal + Business + Connect** autour du **Nexus Core**. Ce document historique peut encore mentionner « Nexus Pro » ; ne pas réintroduire ce terme.
>


# NEXUS — VISION & SPÉCIFICATION DU PRODUIT

## Document Maître v5.3 — Source de vérité consolidée

**Date :** août 2026
**Statut :** document stratégique, fonctionnel, technique, opérationnel et de gouvernance
**Entreprise :** NEXUS CORP TECHNOLOGIES
**Produit :** NEXUS
**Types de comptes :** Personal / Business
**Surfaces produit :** Personal / Business / Connect
**Couche intelligente :** Nexus Intelligence / Nexus AI
**Infrastructure :** Nexus Core
**API B2B :** Nexus Connect
**KYC/KYB de référence :** Sumsub, sous réserve de validation contractuelle, technique et réglementaire

> Cette version consolide la vision v5.2 et les exigences durcies v5.3. En cas de contradiction, les exigences de conformité, sécurité, protection des fonds, contrôle, traçabilité et mise en production de la présente version prévalent.

---

# 0. Règles normatives

Les termes suivants sont obligatoires :

- **DOIT / MUST** : exigence obligatoire ;
- **NE DOIT PAS / MUST NOT** : interdiction obligatoire ;
- **DEVRAIT / SHOULD** : exigence fortement recommandée ;
- **PEUT / MAY** : option autorisée après validation.

Aucune fonctionnalité ne peut être déclarée terminée sans :

1. une spécification approuvée ;
2. un responsable désigné ;
3. des critères d’acceptation mesurables ;
4. des tests documentés ;
5. une validation sécurité et conformité adaptée ;
6. une traçabilité dans le registre des changements.

Le produit ne peut être lancé publiquement qu’après franchissement des gates réglementaires, juridiques, sécurité, opérations, support et réconciliation.

---

# PARTIE I — VISION STRATÉGIQUE

## 1. Vision de Nexus

Nexus est une plateforme financière intelligente multi-rails permettant à un utilisateur de gérer, déplacer, convertir, recevoir et utiliser différents types de fonds depuis une interface unique.

Nexus connecte, selon les capacités autorisées :

- banques ;
- comptes virtuels ;
- wallets fiat ;
- Mobile Money ;
- services de paiement ;
- infrastructures FX ;
- cryptoactifs ;
- stablecoins ;
- exchanges ;
- réseaux P2P ;
- cartes ;
- fournisseurs de payout ;
- fournisseurs de collecte ;
- infrastructures financières B2B.

Nexus DOIT rester provider-agnostic. Les providers sont des capacités externes ; Nexus demeure la couche d’orchestration, de contrôle, d’expérience et de traçabilité.

L'utilisateur exprime son objectif. Nexus identifie les possibilités réellement admissibles, calcule les routes pertinentes, les explique, laisse l'utilisateur ou l'approbateur choisir lorsque le workflow le permet, puis orchestre l'exécution et la réconciliation.

## 2. Promesse produit

**Nexus transforme une intention financière en transaction admissible, explicable, traçable et exécutable.**

Nexus recherche les possibilités.
Nexus les analyse.
Nexus les explique.
L'utilisateur ou l'approbateur décide.
Nexus exécute via les partenaires autorisés.
Nexus suit, réconcilie et traite les incidents.

Nexus ne promet pas une route optimale dans l'ensemble du marché, un délai absolu, un rendement ou une exécution réussie dans tous les cas.

## 3. Principe fondamental

Le système DOIT pouvoir :

1. comprendre l'intention ;
2. vérifier les capacités disponibles ;
3. filtrer les routes impossibles, non conformes ou risquées ;
4. obtenir et normaliser les quotes ;
5. construire plusieurs routes lorsque plusieurs options admissibles existent ;
6. calculer et classer les routes de manière déterministe ;
7. expliquer les différences ;
8. laisser sélectionner une route lorsque la réglementation et le workflow le permettent ;
9. obtenir l'autorisation et le financement ;
10. exécuter la route sélectionnée ;
11. suivre les statuts ;
12. gérer les erreurs, remboursements et écarts ;
13. effectuer la réconciliation ;
14. inscrire les événements et écritures nécessaires au ledger.

Une route non autorisée DOIT être exclue avant tout calcul économique.

---

# PARTIE II — ARCHITECTURE PRODUIT ET MARQUE

## 4. Une plateforme, deux contextes de compte

Nexus est une plateforme unique avec deux contextes :

- **Nexus Personal** ;
- **Nexus Business**.

Nexus Pro n'est pas un troisième type de compte. C'est une couche de fonctionnalités avancées accessible, selon éligibilité, aux comptes Personal et Business.

```text
NEXUS
  └── CREATE ACCOUNT
       ├── PERSONAL ACCOUNT
       └── BUSINESS ACCOUNT
             └── NEXUS PLATFORM
                  ├── Common Financial Layer
                  ├── Nexus Pro
                  ├── Nexus Intelligence
                  ├── Nexus Core
                  └── Nexus Connect
```

## 5. Nexus Personal

Fonctions possibles, selon pays, statut, provider et réglementation :

- wallet multi-devises ;
- comptes financiers connectés ;
- comptes virtuels ;
- transferts internationaux ;
- réception de fonds ;
- paiements ;
- Mobile Money ;
- transferts bancaires ;
- conversion de devises ;
- crypto et stablecoins ;
- cartes ;
- routing multi-provider ;
- comparaison et explication des routes ;
- historique et suivi ;
- Nexus Pro.

Aucune fonction n'est activée par la seule présence dans cette liste.

## 6. Nexus Business

Nexus Business s'adresse aux entrepreneurs, indépendants, commerçants, PME, startups, entreprises et organisations professionnelles.

Fonctions communes :

- wallets ;
- comptes ;
- transferts ;
- paiements ;
- conversion ;
- suivi ;
- conformité.

Fonctions professionnelles :

- KYB ;
- équipes ;
- rôles et permissions ;
- workflows d'approbation ;
- paiements fournisseurs ;
- paiements en masse ;
- encaissements ;
- trésorerie ;
- cartes d'entreprise ;
- rapprochement ;
- reporting ;
- contrôles internes ;
- Nexus Pro.

## 7. Nexus Pro

Nexus Pro est une couche premium pouvant inclure :

- Global Financial Intelligence ;
- analyse des spreads ;
- analyse FX ;
- analyse crypto et stablecoins ;
- analyse P2P lorsque les données et intégrations sont autorisées ;
- analyse de liquidité ;
- alertes ;
- simulations ;
- scoring avancé ;
- aide à la décision par IA.

Nexus Pro DOIT présenter les opportunités comme des opportunités potentielles. Il ne doit jamais présenter une opération comme un gain garanti.

Crypto, P2P, arbitrage et cross-asset sont désactivés par défaut et sont hors périmètre MVP sauf décision formelle contraire accompagnée des validations requises.

## 8. Nexus Connect

Nexus Connect est la couche API/B2B permettant à une entreprise, fintech ou plateforme d'utiliser les capacités autorisées de Nexus.

Fonctions prévues :

- quotes ;
- routing ;
- transfers ;
- payouts ;
- collections ;
- FX ;
- wallets ;
- comptes ;
- cartes ;
- crypto ;
- reporting ;
- webhooks.

L'architecture interne DOIT anticiper Nexus Connect dès le départ : contrats d'API versionnés, identifiants stables, idempotence, erreurs normalisées, scopes, audit et webhooks signés.

---

# PARTIE III — MODÈLE JURIDIQUE ET RÉGLEMENTAIRE

## 9. Décision préalable obligatoire

Avant tout lancement public, Nexus DOIT documenter :

- le pays de lancement ;
- les pays d'origine et de destination ;
- les activités exercées ;
- le rôle juridique de Nexus ;
- le modèle custodial ou non custodial par fonction ;
- les partenaires réglementés ;
- la responsabilité de chaque partie ;
- la gestion des fonds ;
- les obligations AML/KYC/KYB ;
- les remboursements ;
- les plaintes ;
- les litiges ;
- le support ;
- les limites ;
- les conditions de suspension ou de fermeture.

La formulation « Nexus exécute » doit être utilisée uniquement si elle correspond au rôle juridique réel. Dans les autres cas, les termes « orchestre », « initie », « transmet une instruction » ou « fait exécuter par un partenaire » doivent être utilisés.

## 10. Matrice réglementaire

Une matrice versionnée DOIT couvrir :

- pays d'origine ;
- pays de destination ;
- type de compte ;
- profil utilisateur ;
- actif ;
- devise ;
- type de transaction ;
- destination ;
- provider ;
- licence requise ;
- exemption éventuelle ;
- KYC/KYB ;
- AML/sanctions ;
- limites ;
- restrictions ;
- responsable juridique ;
- date de validité ;
- preuve de validation.

Toute combinaison non explicitement approuvée est bloquée par défaut.

## 11. Modèle custodial/non custodial

Le modèle doit être décidé fonction par fonction :

| Fonction        | Modèle    | Détenteur des fonds              | Responsable | Statut     |
| --------------- | --------- | -------------------------------- | ----------- | ---------- |
| Wallet fiat     | À définir | Nexus ou partenaire              | À définir   | Non validé |
| Payout bancaire | À définir | Provider ou partenaire           | À définir   | Non validé |
| Mobile Money    | À définir | Opérateur/agrégateur             | À définir   | Non validé |
| Wallet crypto   | À définir | Utilisateur, partenaire ou Nexus | À définir   | Désactivé  |
| Carte           | À définir | Émetteur/BIN sponsor             | À définir   | Désactivé  |
| Compte virtuel  | À définir | BaaS/EMI                         | À définir   | Désactivé  |

Nexus ne doit jamais supposer qu'une même entité peut légalement fournir toutes les fonctions dans tous les pays.

---

# PARTIE IV — FONCTIONNALITÉS COMMUNES

## 12. Wallet multi-devises

Le wallet doit distinguer :

- fonds disponibles ;
- fonds réservés ;
- fonds en attente ;
- fonds en transit ;
- fonds en settlement ;
- fonds détenus auprès d'un partenaire ;
- actifs crypto ;
- soldes internes éventuels.

Toute balance doit indiquer sa devise, son statut, son origine et, lorsque nécessaire, le détenteur juridique des fonds.

## 13. Sources de financement

Les sources peuvent inclure :

- wallet fiat ;
- compte bancaire ;
- compte virtuel ;
- carte ;
- Mobile Money ;
- wallet crypto ;
- autre rail supporté.

Chaque source est filtrée selon : pays, compte, devise, statut KYC/KYB, provider, limites, disponibilité, réglementation et risque.

## 14. Destinations

Destinations possibles :

- IBAN ;
- compte bancaire local ;
- compte bancaire international ;
- Mobile Money ;
- wallet crypto ;
- cash pickup ;
- carte ;
- compte virtuel ;
- autre destination supportée.

Les données de destination doivent être validées, protégées, auditées et soumises aux contrôles de changement de bénéficiaire.

## 15. Transparence

Avant toute opération, lorsque techniquement possible, Nexus DOIT afficher :

- montant débité ;
- devise source ;
- montant envoyé ;
- frais Nexus ;
- frais provider ;
- frais réseau ;
- taux appliqué ;
- spread ;
- montant reçu estimé ;
- délai estimé ;
- date d'expiration de la quote ;
- route ;
- providers ;
- conditions ;
- risques pertinents ;
- modalités de remboursement.

Aucun frais connu ne doit être masqué.

---

# PARTIE V — TRANSFERTS INTERNATIONAUX

## 16. Parcours principal

```text
SEND MONEY
  → COUNTRY OF ORIGIN
  → COUNTRY OF DESTINATION
  → AMOUNT
  → SOURCE
  → DESTINATION TYPE
  → NEXUS ROUTING ENGINE
  → USER / APPROVER CONFIRMATION
  → FUNDING
  → EXECUTION
  → TRACKING
  → SETTLEMENT
  → RECONCILIATION
```

Le MVP doit commencer par un corridor précisément défini. « Congo » ne suffit pas comme définition opérationnelle : la République du Congo et la République démocratique du Congo doivent être traitées comme des juridictions distinctes.

## 17. Présentation des alternatives

Lorsqu'au moins deux routes admissibles existent, le système DOIT afficher plusieurs options.

Chaque option doit présenter :

- identifiant de route ;
- montant source ;
- montant destination estimé ;
- frais ;
- taux ;
- spread ;
- délai ;
- fiabilité historique lorsque disponible ;
- provider ;
- liquidité ;
- expiration ;
- conditions ;
- statut de conformité ;
- raison de la recommandation.

Le terme « meilleure route » doit être évité sans précision. Il faut parler de route recommandée selon un objectif : optimisée, moins chère, plus rapide, montant reçu supérieur ou plus fiable.

## 18. Confirmation

L'utilisateur ou l'approbateur doit confirmer explicitement :

- la route ;
- le montant ;
- la source ;
- la destination ;
- les frais ;
- le taux ;
- les conditions ;
- le délai estimé.

Si la quote expire ou si une donnée critique change, une nouvelle confirmation est requise.

---

# PARTIE VI — NEXUS INTELLIGENCE

## 19. Moteurs spécialisés

```text
INTENT ENGINE
  → CAPABILITY ENGINE
  → QUOTE ENGINE
  → ROUTING ENGINE
  → POLICY & RISK ENGINE
  → OPTIMIZATION ENGINE
  → EXECUTION ENGINE
  → RECOVERY ENGINE
  → RECONCILIATION ENGINE
  → NEXUS LEDGER
```

Les moteurs critiques doivent être déterministes, testables, versionnés et indépendants des sorties non contrôlées d'un modèle IA.

## 20. Intent Engine

Le MVP utilise un formulaire guidé déterministe. Le langage naturel assisté par IA peut être ajouté ensuite.

Exemple :

```json
{
  "action": "transfer",
  "source_country": "FR",
  "destination_country": "CG",
  "amount": 500,
  "currency": "EUR",
  "destination_type": "mobile_money",
  "priority": "optimized"
}
```

Toute intention générée par IA doit être confirmée par l'utilisateur avant de devenir une instruction financière.

## 21. Capability Engine

Il vérifie :

- pays ;
- devises ;
- providers ;
- rails ;
- KYC/KYB ;
- limites ;
- statut du compte ;
- réglementation ;
- disponibilité ;
- capacité provider ;
- destination ;
- type d'actif.

Une capacité doit être activée dans un registre versionné et non déduite uniquement du code d'interface.

## 22. Quote Engine

Le Quote Engine normalise :

- montant ;
- devise ;
- taux ;
- frais ;
- spread ;
- destination ;
- délai ;
- expiration ;
- limites ;
- disponibilité ;
- liquidité.

Il doit conserver la source, l'heure, la validité, la version de l'adaptateur et les frais connus. Toute quote expirée doit être clairement signalée.

## 23. Routing Engine

Routes possibles :

- directe ;
- multi-provider ;
- cross-asset, uniquement après activation spécifique.

Le moteur applique obligatoirement :

1. validation des capacités ;
2. conformité ;
3. risque ;
4. limites ;
5. quotes ;
6. construction ;
7. scoring ;
8. classement ;
9. explication ;
10. sélection.

Modes de routing :

- optimisé ;
- moins cher ;
- plus rapide ;
- plus reçu ;
- plus fiable.

## 24. Optimization Engine

Les pondérations sont configurables et versionnées. Le calcul peut prendre en compte :

- montant net reçu ;
- frais ;
- taux ;
- spread ;
- délai ;
- fiabilité ;
- liquidité ;
- taux d'échec ;
- disponibilité ;
- risque ;
- préférence utilisateur.

La conformité ne doit pas être traitée comme une simple pondération : une route non conforme est exclue.

---

# PARTIE VII — POLICY, RISK ET IA

## 25. Policy & Risk Engine

Résultats possibles :

- `APPROVED` ;
- `DECLINED` ;
- `REVIEW_REQUIRED` ;
- `MANUAL_REVIEW`.

Contrôles :

- KYC/KYB ;
- AML ;
- sanctions ;
- PEP lorsque requis ;
- juridictions ;
- restrictions géographiques ;
- limites ;
- source des fonds ;
- règles provider ;
- risque transactionnel ;
- règles crypto ;
- règles internes.

## 26. Nexus AI Layer

L'IA peut assister :

- compréhension de l'intention ;
- explication ;
- détection d'anomalies ;
- analyse de marché ;
- recommandations ;
- prévisions ;
- support ;
- reporting ;
- recherche d'opportunités.

Elle ne doit pas, sans contrôles déterministes et autorisation :

- modifier un montant ;
- modifier une destination ;
- créer un bénéficiaire actif ;
- contourner une limite ;
- désactiver un contrôle ;
- exécuter seule une transaction ;
- inventer un taux, frais, quote ou provider ;
- prendre seule une décision finale de gel ou de déblocage.

Chaque recommandation critique doit conserver le modèle, la version, les données, la sortie, le niveau de confiance, les contrôles et la décision finale.

## 27. Assistants

L'assistant Personal peut expliquer les routes et dépenses. L'assistant Business peut aider à la trésorerie, au rapprochement, aux paiements et aux rapports.

L'IA ne peut jamais contourner les permissions, les validations, les limites ou les workflows d'approbation.

---

# PARTIE VIII — EXÉCUTION, RECOVERY ET LEDGER

## 28. Execution Engine

Il gère :

- autorisation ;
- funding ;
- appel provider ;
- webhooks ;
- statuts ;
- timeouts ;
- settlement ;
- réconciliation ;
- remboursements.

## 29. Machine à états

Les états minimaux sont :

```text
CREATED
QUOTED
AUTHORIZED
FUNDING
PROCESSING
PENDING
COMPLETED
FAILED
CANCELLED
EXPIRED
REVERSED
REFUNDED
RECONCILIATION_REQUIRED
MANUAL_REVIEW
```

Toute transition interdite doit être rejetée et journalisée. Les webhooks en double, tardifs ou contradictoires doivent être dédupliqués et traités selon l'état courant.

## 30. Idempotence et recovery

Toute opération à effet financier doit utiliser :

- clé d'idempotence ;
- fenêtre de validité ;
- réponse stable ;
- détection des payloads contradictoires ;
- journalisation.

En cas d'état inconnu :

1. pas de retry financier aveugle ;
2. statut contrôlé ;
3. interrogation provider ;
4. réconciliation ;
5. décision de reprise ou compensation ;
6. communication utilisateur adaptée.

## 31. Ledger

Le ledger doit suivre :

- transaction\_id ;
- user\_id ;
- account\_id ;
- wallet\_id ;
- source ;
- destination ;
- devise ;
- montant ;
- frais ;
- taux ;
- provider ;
- route ;
- statut ;
- timestamps ;
- état de réconciliation ;
- obligations ;
- settlements ;
- commissions ;
- remboursements ;
- écarts.

Les écritures financières critiques sont immuables. Toute correction est une écriture compensatoire.

Le ledger ne doit pas être présenté comme un compte bancaire interne lorsque Nexus ne détient pas directement les fonds.

## 32. Réconciliation

La réconciliation doit couvrir :

- Nexus / provider ;
- provider / ledger ;
- quote / exécution ;
- fonds attendus / fonds reçus.

Tout écart crée un dossier avec propriétaire, priorité, délai, action et preuve de clôture.

---

# PARTIE IX — KYC, KYB ET CONFORMITÉ

## 33. Sumsub

Sumsub est le provider de référence pour la vérification d'identité et l'onboarding, sous réserve de validation contractuelle, réglementaire et technique.

Nexus Personal peut utiliser :

- KYC ;
- documents ;
- biométrie/liveness lorsque activée ;
- screening ;
- signaux de risque.

Nexus Business peut utiliser :

- KYB ;
- entreprise ;
- dirigeants ;
- bénéficiaires effectifs ;
- documents ;
- screenings.

Sumsub est une brique de vérification. Nexus conserve la responsabilité de ses règles, de son orchestration et de ses décisions relevant de son rôle juridique.

## 34. Contrôles AML et risque

Le système doit prévoir :

- niveaux de vérification ;
- limites par niveau ;
- surveillance transactionnelle ;
- revue manuelle ;
- gel contrôlé ;
- escalade ;
- réexamen périodique ;
- audit des décisions ;
- gestion des faux positifs ;
- source des fonds lorsque nécessaire.

---

# PARTIE X — BUSINESS

## 35. Workspace Business

Le workspace contient :

- wallets ;
- comptes ;
- trésorerie ;
- paiements ;
- encaissements ;
- fournisseurs ;
- bénéficiaires ;
- cartes ;
- utilisateurs ;
- permissions ;
- reporting.

## 36. Rôles et permissions

Rôles initiaux :

- Owner ;
- Administrator ;
- Finance Manager ;
- Accountant ;
- Operator ;
- Viewer.

Les permissions sont configurables selon le principe du moindre privilège. La révocation d'un utilisateur doit être immédiate. Les actions d'administration sont auditées.

## 37. Approval Workflow

Exemple :

```text
Operator
  → Payment Created
  → Finance Manager
  → Approval
  → Policy / Risk
  → Routing
  → Execution
```

Pour les montants élevés, des niveaux multiples peuvent être requis. L'initiateur et l'approbateur doivent pouvoir être séparés.

## 38. Mass Payments et Collections

Ces fonctions sont désactivées par défaut et nécessitent :

- validation réglementaire ;
- validation provider ;
- limites ;
- contrôle de fichiers ;
- détection de doublons ;
- approbation de lot ;
- traitement partiel ;
- réconciliation ligne par ligne ;
- procédure de rejet et remboursement.

## 39. Treasury et cartes

La trésorerie distingue disponibles, réservés, en transit, pending, settlement et exposition par devise.

Les cartes virtuelles, prépayées, physiques, mono-usage ou programmables nécessitent un émetteur et un cadre validés. Les règles peuvent inclure plafond, période, catégorie, marchand et utilisateur.

---

# PARTIE XI — NEXUS PRO, CRYPTO ET MARCHÉS

## 40. GPM

GPM peut analyser spreads, FX, crypto, stablecoins, P2P, liquidité, frais et opportunités inter-marchés lorsque les données et intégrations sont autorisées.

## 41. Arbitrage

Le calcul doit intégrer :

- prix d'achat ;
- frais ;
- spread ;
- coût réseau ;
- retrait ;
- conversion ;
- transfert ;
- slippage ;
- risque ;
- liquidité ;
- coût réel ;
- profit potentiel.

Toute sortie doit porter la mention d'opportunité potentielle et inclure les hypothèses, la date, l'incertitude et les risques.

## 42. P2P et crypto

Nexus ne doit pas dépendre de scraping ou d'automatisation non autorisée. Crypto, P2P et stablecoins sont hors MVP et désactivés par défaut.

Toute activation future doit vérifier :

- juridiction ;
- licence ou partenaire ;
- AML ;
- sanctions ;
- risque de contrepartie ;
- risque de liquidité ;
- réseau ;
- slippage ;
- conservation ;
- fiscalité et reporting applicables.

---

# PARTIE XII — PROVIDER NETWORK

## 43. Provider Registry

Chaque provider doit disposer de :

- Provider ID ;
- capacités ;
- pays ;
- devises ;
- rails ;
- limites ;
- frais ;
- SLA ;
- statut ;
- disponibilité ;
- exigences KYC/KYB ;
- règles de conformité ;
- version API ;
- credentials ;
- webhooks ;
- performance ;
- plan de secours ;
- date de revue.

## 44. Évaluation des providers

Les providers potentiels peuvent inclure des acteurs de banking, paiement, payout, Mobile Money, FX, stablecoins, crypto, cartes, LATAM ou APAC, mais aucun provider cité dans une shortlist n'est considéré comme intégré, contracté, disponible ou juridiquement utilisable sans validation séparée.

Chaque intégration doit passer par une abstraction versionnée et ne doit pas être codée directement dans les parcours métiers centraux.

Nexus doit pouvoir désactiver un provider sans interrompre toute la plateforme lorsque des routes de secours admissibles existent.

---

# PARTIE XIII — SÉCURITÉ, DONNÉES ET IA

## 45. Sécurité

Prévoir et tester :

- MFA ;
- authentification forte ;
- OAuth2/OIDC ;
- RBAC ;
- séparation des tâches ;
- chiffrement ;
- TLS ;
- secrets management ;
- rotation des clés ;
- signatures webhook ;
- idempotence ;
- rate limiting ;
- anti-replay ;
- détection fraude ;
- audit immuable ;
- monitoring ;
- alerting ;
- backup ;
- disaster recovery ;
- RPO/RTO ;
- séparation des environnements ;
- revue des accès ;
- tests d'intrusion ;
- gestion d'incidents.

Les secrets ne doivent jamais être stockés dans le code, les logs, les tickets ou les environnements de test non protégés.

## 46. Data Architecture

Séparer :

- User Data ;
- Compliance Data ;
- Financial Data ;
- Provider Data ;
- Market Data ;
- AI Data.

Pour chaque donnée, documenter finalité, base légale, propriétaire, sensibilité, localisation, conservation, accès, sous-traitants, suppression et utilisation IA.

Les données biométriques, documents d'identité, données financières et données de risque sont hautement sensibles.

## 47. Observabilité

Surveiller :

- API ;
- providers ;
- transactions ;
- latence ;
- échecs ;
- webhooks ;
- quotes ;
- routing ;
- settlement ;
- réconciliation ;
- fraude ;
- file de revue manuelle.

KPIs minimaux :

- Transaction Success Rate ;
- Provider Success Rate ;
- Average Transaction Time ;
- Quote Latency ;
- Route Selection Rate ;
- Provider Failure Rate ;
- Recovery Rate ;
- Reconciliation Rate ;
- Refund Time ;
- Manual Review Aging.

Chaque alerte critique doit avoir un propriétaire, une priorité, un runbook, une escalade et une preuve de résolution.

---

# PARTIE XIV — API ET NEXUS CONNECT

## 48. API

Nexus Connect doit prévoir :

```text
QUOTE
ROUTE
TRANSFER
PAYOUT
COLLECTION
FX
WALLET
CARD
CRYPTO
WEBHOOK
REPORTING
```

Exigences :

- API versionnée ;
- OAuth2 et scopes ;
- sandbox ;
- clés protégées ;
- idempotency keys ;
- erreurs normalisées ;
- pagination ;
- limites de débit ;
- webhooks signés ;
- replay protection ;
- audit ;
- dépréciation ;
- isolation des credentials provider.

Aucune API externe ne doit exposer directement les identifiants, secrets ou détails internes d'un provider.

---

# PARTIE XV — MVP ET ROADMAP

## 49. Objectif du MVP

Le MVP doit démontrer qu'une intention de transfert peut être transformée en transaction admissible, transparente, sélectionnable, exécutable, suivie et réconciliée.

## 50. Périmètre MVP

Le MVP doit être limité à :

- un corridor précisément défini ;
- une devise source et une devise destination ;
- un nombre limité de providers contractés ;
- Personal prioritaire ;
- Business minimal uniquement si nécessaire ;
- KYC/KYB applicable ;
- destination bancaire et/ou Mobile Money validée ;
- Quote Engine ;
- Routing Engine ;
- Policy & Risk ;
- Execution ;
- Recovery ;
- Réconciliation ;
- Ledger ;
- Notifications ;
- Historique ;
- Support.

Crypto, stablecoins, cartes, arbitrage, P2P, mass payments, collections avancées et API publique sont hors MVP par défaut.

## 51. Critères d'acceptation MVP

Le MVP est accepté uniquement si :

1. l'intention est structurée ;
2. les capacités sont vérifiées ;
3. les routes non conformes sont exclues ;
4. plusieurs routes sont présentées lorsqu'elles existent ;
5. chaque route expose frais, taux, montant reçu, délai, provider et expiration ;
6. l'utilisateur sélectionne explicitement une route ;
7. la route exécutée correspond à la confirmation ;
8. l'idempotence empêche les doubles exécutions ;
9. les webhooks sont authentifiés et dédupliqués ;
10. les états inconnus déclenchent une réconciliation ;
11. le ledger peut être rapproché ;
12. chaque opération est auditable ;
13. un traitement existe pour chaque échec critique ;
14. aucun défaut critique n'est ouvert au lancement.

## 52. Roadmap

### Phase 0 — Foundation

- structure juridique ;
- matrice réglementaire ;
- pays et corridor ;
- modèle custody ;
- sécurité ;
- Sumsub ;
- Provider Registry ;
- contrats ;
- ledger ;
- routing ;
- runbooks.

### Phase 1 — Nexus MVP

- Personal ;
- Business minimal ;
- KYC/KYB ;
- quotes ;
- transferts ;
- bank payout ;
- Mobile Money si validé ;
- policy ;
- execution ;
- recovery ;
- reconciliation ;
- notifications.

### Phase 2 — Wallet & Banking

- comptes virtuels ;
- IBAN lorsque disponible ;
- multi-currency ;
- inbound transfers ;
- treasury de base.

### Phase 3 — Nexus Pro

- GPM ;
- spreads ;
- alertes ;
- market intelligence ;
- simulations ;
- fonctions analytiques non transactionnelles.

### Phase 4 — Crypto & Stablecoins

Uniquement après validation juridique, conformité, sécurité, partenaires et custody.

### Phase 5 — Cards

Après validation de l'émetteur, du BIN sponsorship, du KYC et du cadre applicable.

### Phase 6 — Cross-Asset

Fiat, crypto et stablecoins uniquement après maîtrise des phases précédentes.

### Phase 7 — Business Advanced

- mass payments ;
- treasury avancée ;
- approval workflows ;
- reporting ;
- accounting integrations.

### Phase 8 — Nexus Connect

- API publique ;
- routing-as-a-service ;
- payouts ;
- collections ;
- embedded finance ;
- white-label.

---

# PARTIE XVI — GOUVERNANCE, TESTS ET MISE EN PRODUCTION

## 53. Gestion du changement

Tout changement impactant fonds, routing, conformité, données, permissions ou IA doit faire l'objet d'une analyse d'impact.

Le registre doit contenir :

- auteur ;
- date ;
- justification ;
- sections modifiées ;
- risques ;
- décision ;
- validations ;
- version précédente.

Toute nouvelle fonction doit être classée :

- commune ;
- Personal ;
- Business ;
- Nexus Pro ;
- Nexus Intelligence ;
- Nexus Core ;
- Nexus Connect ;
- réglementée ;
- hors périmètre.

## 54. Gates techniques

### Développement vers test

- tests unitaires ;
- revue de code ;
- secrets séparés ;
- données anonymisées ;
- analyse des vulnérabilités.

### Test vers préproduction

- intégrations provider ;
- webhooks ;
- idempotence ;
- réconciliation ;
- permissions ;
- reprise ;
- limites ;
- fraude ;
- remboursements.

### Préproduction vers production

- validation produit ;
- conformité ;
- sécurité ;
- opérations ;
- support ;
- monitoring ;
- rollback ;
- astreinte ;
- absence de défaut critique.

## 55. Registre des risques

Le projet doit maintenir un registre couvrant :

- réglementation ;
- custody ;
- provider ;
- liquidité ;
- fraude ;
- AML ;
- cyber ;
- double paiement ;
- webhook ;
- réconciliation ;
- change ;
- données ;
- IA ;
- dépendance fournisseur ;
- opérations ;
- réputation.

Chaque risque possède probabilité, impact, score, propriétaire, mitigation, indicateur et contingence.

---

# PARTIE XVII — DÉCISIONS BLOQUANTES

Avant validation finale, Nexus doit décider et documenter :

1. pays de lancement ;
2. corridor MVP exact ;
3. rôle juridique de Nexus ;
4. modèle custodial/non custodial par fonction ;
5. providers contractés ;
6. limites de transaction ;
7. politique de frais ;
8. politique de remboursement ;
9. responsabilités AML, support et litiges ;
10. RPO/RTO ;
11. disponibilité cible ;
12. critères d'activation de chaque capacité ;
13. statut de Nexus Pro ;
14. fonctionnalités exclues du MVP ;
15. procédure de sortie d'un provider.

Tant que ces décisions ne sont pas validées, le statut du projet est **Ready for Definition**, et non **Ready for Launch**.

---

# PARTIE XVIII — POSITIONNEMENT FINAL

## 56. Architecture de marque

```text
NEXUS CORP TECHNOLOGIES
  └── NEXUS
       ├── PERSONAL ACCOUNT
       ├── BUSINESS ACCOUNT
       ├── NEXUS PRO
       │    └── Advanced Financial Intelligence
       ├── NEXUS INTELLIGENCE
       │    └── AI + Decision Engines
       ├── NEXUS CORE
       │    └── Execution, Ledger, Settlement, Recovery
       └── NEXUS CONNECT
            └── API / Embedded Finance
```

## 57. Définition finale

**Nexus est une plateforme financière intelligente multi-rails qui permet aux particuliers et aux entreprises de gérer leurs actifs, comptes, paiements et transferts depuis une interface unique, tout en utilisant une couche d'intelligence capable d'identifier, filtrer, comparer, expliquer et orchestrer les routes financières admissibles.**

## 58. Principe architectural final

**Une plateforme. Deux types de comptes. Une intelligence commune. Un réseau multi-provider. Des contrôles déterministes. Une exécution traçable. Une réconciliation obligatoire.**

Personal : particuliers.
Business : professionnels et entreprises.
Pro : intelligence financière avancée.
Intelligence : décision, optimisation et assistance IA.
Core : exécution, ledger, settlement, recovery et réconciliation.
Connect : exposition contrôlée des capacités à d'autres entreprises.

---

# STATUT DU DOCUMENT

**Document :** NEXUS — Vision & Spécification du Produit
**Version :** 5.3
**Statut :** source de vérité consolidée, sous réserve des décisions bloquantes
**Entreprise :** NEXUS CORP TECHNOLOGIES
**Produit :** NEXUS
**Comptes :** Personal / Business
**Premium :** Nexus Pro
**Intelligence :** Nexus Intelligence / Nexus AI
**Infrastructure :** Nexus Core
**API B2B :** Nexus Connect
**KYC/KYB :** Sumsub sous réserve de validation

## Statut recommandé

**APPROUVÉ COMME CADRE STRATÉGIQUE, FONCTIONNEL ET DE CONTRÔLE.**

**NON APPROUVÉ POUR LANCEMENT PUBLIC** tant que les décisions bloquantes, la matrice réglementaire, le modèle de custody, le corridor MVP, les providers contractés, les critères de recette et les validations de sécurité ne sont pas formellement clôturés.

Toute évolution future doit être comparée à ce document maître. Toute nouvelle fonctionnalité doit être classée, évaluée, testée et approuvée avant activation.