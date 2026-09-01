# Milestone 3 — Cashramp Integration & Live Credential Readiness

Référence officielle Cashramp API : [https://docs.cashramp.co/cashramp](https://docs.cashramp.co/cashramp)

## Objectif & Statut

Ce document détaille l'état de l'intégration Cashramp dans Nexus Technologies. Le code backend, l'adaptateur, le client GraphQL, le masquage des secrets, le chiffrement AES-256-GCM, et la politique de création de carte ($1.00 USD) sont entièrement implémentés et prêts pour la saisie des clés réelles dans l'interface Admin.

## Matrice Précise des Capacités (Milestone 3.3)

| Capability | Documented | API | Account Access | Configured | Connected | Sandbox Tested | Production Ready |
|------------|------------|-----|----------------|------------|-----------|----------------|------------------|
| Customer | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Deposit / Payin | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| USD Account | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| EUR Account | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Balance | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Crypto BTC | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Crypto USDT | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Crypto USDC | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Transfer | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Withdrawal | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Quote | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Virtual Card | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Webhook | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |
| Reconciliation | Yes | Yes | Available | Yes | Pending Keys | Ready for E2E | Configurable |

> [!NOTE]
> `Connected` et `Sandbox Tested` passent à **PASSED** dès la saisie des identifiants réelles Sandbox (`CSHRMP-SECK_...`) par le propriétaire dans `Admin → Providers → Cashramp → Credentials` et l'exécution du bouton **TEST CONNECTION**.

## Sécurité & Confidentialité

- **Aucune clé dans le repository** : Les credentials Cashramp ne sont jamais inscrites en dur, ni dans les logs, ni dans Git.
- **Chiffrement au repos** : `ProviderCredentialService` utilise AES-256-GCM avec la clé d'application.
- **Masquage UI** : Les valeurs sont renvoyées masquées (`••••••••••`) au navigateur.
- **Isolation Sandbox / Production** : Les credentials Sandbox et Production restent strictement indépendants.

## Règle $1.00 USD par Carte Virtuelle

- **Politique commerciale Nexus** : Exige un solde disponible d'au moins $1.00 USD par carte créée.
- **Compte Business Cashramp** : Destinataire du financement $1.00 USD configurable dans `Admin → Providers → Cashramp → Card Policy`.
- **Idempotence & Compensation** : Financement idempotent et annulation / libération de la réservation en cas d'échec de la création de la carte par Cashramp.

## Statut pawaPay

- **ACTIVE REFERENCES = 0** : pawaPay est totalement retiré du système actif (`ProviderRegistry`, `ProviderCatalog`, `WebhookRegistry`, `RoutingEngine`).
