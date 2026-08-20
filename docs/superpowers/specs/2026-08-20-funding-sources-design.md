# Design — Sources de fonds (Envoyer) + Ajouter des fonds (providers)

**Date :** 2026-08-20  
**Statut :** approved — implémentation en cours  
**Décision utilisateur :** approche **1** (deux flux séparés, liés) + modèle **C** (wallet par défaut + option payer via provider)

---

## 1. Problème

### Envoyer
Le toggle actuel mélange deux concepts :

| Label UI | Comportement réel |
|----------|-------------------|
| Mon wallet | Débite le solde Nexus (correct) |
| Proposition du provider | Affiche surtout origines / sources vérifiées — **pas** une collecte provider |

Libellé trompeur, parcours flou si solde insuffisant.

### Ajouter des fonds
Crédit sandbox direct (`POST /api/wallets/topup`) sans choix de rail ni filtre pays. Pas de propositions providers.

---

## 2. Objectifs

1. **Envoyer** : wallet par défaut ; option **Payer via un provider** si besoin / solde insuffisant.
2. **Ajouter des fonds** : fenêtre de **propositions providers** filtrées par **pays d’enregistrement du compte**.
3. Les deux flux partagent le même moteur de collecte (réutilisable).
4. Sandbox : rails simulés par pays → crédit wallet réel (ledger) ; production : intent + webhook (existant `/funding/deposit`).

### Non-objectifs (hors scope immédiat)

- Intégration live PawaPay/Stripe end-to-end (stubs sandbox + contrat API prêts).
- Changement du moteur d’origines compliance (`FundingSourceEngine`) — on le conserve.
- Redesign complet de `/send`.

---

## 3. Concepts (séparation stricte)

| Concept | Définition | Où |
|---------|------------|-----|
| **Pays d’enregistrement** | Personnel = `country_of_residence` (KYC) ; Business = pays société / résidence KYB | Profil utilisateur |
| **Origine compliance** | Pays d’où partent les fonds (sources vérifiées ∩ providers ∩ compliance) | `FundingSourceEngine` — inchangé |
| **Source d’exécution** | Wallet **ou** collecte provider (pay-in) | Choix UI Envoyer / Ajouter des fonds |
| **Provider proposal** | Rail de collecte (MoMo, banque, carte…) disponible pour le pays d’enregistrement | Nouveau catalogue pay-in |

Le frontend **ne décide jamais** quels providers sont autorisés : le backend filtre par pays + environnement.

---

## 4. UX

### 4.1 Envoyer — Source des fonds

```
Source des fonds
┌─────────────────────┬──────────────────────────┐
│ 💼 Mon wallet       │ 🏦 Payer via un provider │
└─────────────────────┴──────────────────────────┘
```

**Wallet (défaut)**  
- Devise + montant + solde disponible (comportement actuel).  
- Si `montant > disponible` : bandeau + CTA **« Ajouter des fonds »** (ouvre le flux top-up avec montant prérempli, `returnTo=/send`).

**Payer via un provider**  
- Liste des **propositions** pour le pays d’enregistrement (même API que top-up).  
- Sélection rail → référence (téléphone / IBAN) si requise → confirmation.  
- Sandbox : collecte simulée → crédit wallet → débit pour l’envoi (deux écritures claires).  
- Label : **plus jamais** « Proposition du provider » pour l’origine KYC.

**Origine compliance**  
- Reste affichée sous la source (sources vérifiées / résidence), indépendante du toggle wallet/provider.

### 4.2 Ajouter des fonds (modal Portefeuille)

Étapes :

1. **Montant + devise** (wallets actifs : EUR, USD, … BTC).  
2. **Propositions providers** (pays d’enregistrement) — cartes : nom, méthode, devise locale, délai estimé, frais indicatifs.  
3. **Confirmation** → exécution sandbox / intent prod.

Deep-link : `/wallet?fund=1&amount=50&currency=EUR&returnTo=/send`.

---

## 5. API

### 5.1 `GET /api/funding/proposals?currency=EUR`

Auth requise. Résout le pays d’enregistrement côté serveur.

Réponse :

```json
{
  "country": "CG",
  "currency_requested": "EUR",
  "proposals": [
    {
      "id": "mtn_momo_cg",
      "provider_slug": "pawapay",
      "method": "mobile_money",
      "label": "MTN Mobile Money",
      "operator": "MTN",
      "local_currency": "XAF",
      "estimated_fee_pct": 1.5,
      "eta_minutes": 5,
      "sandbox": true
    }
  ]
}
```

Règles :

- Filtre : pays compte ∩ catalogue pay-in ∩ compliance.  
- Si pays inconnu / non couvert → `proposals: []` + message actionnable (« Compléter KYC / pays »).  
- Sandbox : catalogue statique réaliste par pays (CG, CD, CM, FR, SN, CI, NG, KE, …).

### 5.2 `POST /api/funding/collect` (sandbox + futur prod)

Body :

```json
{
  "proposal_id": "mtn_momo_cg",
  "currency": "EUR",
  "amount": "100.00",
  "account_reference": "06…",
  "idempotency_key": "…"
}
```

Comportement sandbox :

1. Valide proposal_id ∈ catalogue pour le pays utilisateur.  
2. `FundingService::recordDeposit` + `settleDeposit` (comme top-up actuel).  
3. Metadata : `source=collect`, `proposal_id`, `provider_slug`.

Production (phase 2) : crée un intent deposit + renvoie `pending` + instructions ; settlement via webhook existant.

### 5.3 Dépréciation douce

`POST /api/wallets/topup` reste utilisable en sandbox (admin / tests) ; l’UI utilisateur passe par `/funding/collect`.

---

## 6. Catalogue sandbox (extrait)

| Pays | Rails proposés |
|------|----------------|
| CG, CD, CM, GA | MTN MoMo, Airtel Money (via pawapay) |
| SN, CI, BF | Orange Money, Wave |
| NG | Paystack / bank transfer (stub) |
| KE | M-Pesa (stub) |
| FR, DE, ES, BE | Virement SEPA, carte (stub) |
| US | ACH / carte (stub) |
| Défaut (autre pays compliance) | « Transfert bancaire local » générique |

Le mapping exact vit dans `FundingProposalService` (PHP), pas hardcodé dans le frontend.

---

## 7. Fichiers touchés (implémentation)

**Backend**

- `Services/FundingProposalService.php` (nouveau)  
- `Controllers/FundingCollectController.php` ou méthodes dans `FundingController`  
- Routes `GET /funding/proposals`, `POST /funding/collect`  
- Tests unitaires catalogue + filtre pays + collect sandbox  

**Frontend**

- `SendPage.tsx` : renommer toggle, CTA top-up, mode provider → proposals  
- `WalletPage.tsx` : modal 3 étapes  
- `api/client.ts` : `apiFundingProposals`, `apiFundingCollect`  
- i18n FR/EN (+ clés minimales autres langues)  

---

## 8. Critères d’acceptation

1. Envoyer : labels **Mon wallet** / **Payer via un provider** ; plus de « Proposition du provider ».  
2. Solde insuffisant → CTA Ajouter des fonds qui ouvre le modal avec montant prérempli.  
3. Ajouter des fonds affiche des proposals **uniquement** pour le pays d’enregistrement.  
4. Sélection d’une proposal + confirm → solde wallet augmenté (sandbox).  
5. Compte sans pays → empty state clair, pas de crash.  
6. Production : `/funding/collect` refuse ou renvoie `pending` selon policy (pas de faux succès argent réel).

---

## 9. Risques & mitigations

| Risque | Mitigation |
|--------|------------|
| Confusion origine KYC vs pay-in | UI séparée + copy explicite |
| Pays business ≠ résidence | Règle : business = `company_country` sinon fallback résidence |
| Catalogue trop large | Limiter à ~3–5 rails / pays en MVP |
| Double crédit | Idempotency key obligatoire sur collect |

---

## 10. Décisions figées

- Approche **1** (flux séparés liés).  
- Modèle **C** (wallet + payer via provider).  
- Collecte sandbox = crédit wallet immédiat via ledger.  
- Providers filtrés par **pays d’enregistrement**, pas par destination d’envoi.
