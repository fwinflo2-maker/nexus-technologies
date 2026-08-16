# NEXUS — Rapport de gel SQL (Étape 2)

**Date :** 14 août 2026
**Périmètre :** gel et vérification de l'état SQL. **Aucune évolution backend**,
conformément à l'ordre imposé (§12).
**Environnement :** MariaDB 11.8.6, PHP 8.4.24.

> Application de la règle §14 : chaque PASS ci-dessous correspond à une commande
> réellement exécutée. Les problèmes trouvés sont décrits, pas contournés.

---

## 1. SQL — état figé

| Élément | Valeur |
|---|---|
| Tables | **19** |
| Colonnes | **234** |
| Migrations | **12** (+ `schema.sql`) |
| Clés étrangères | **20** |
| Index | **58** |
| Contraintes uniques | **29** |
| Colonnes ENUM | **25** |

### Tables (19)

`audit_logs`, `beneficiaries`, `fx_rates_cache`, `idempotency_keys`,
`ledger_entries`, `login_attempts`, `notifications`, `oauth_identities`,
`payment_accounts`, `payments`, `provider_credentials`, `quotes`,
`reconciliation_items`, `revoked_tokens`, `team_members`, `transactions`,
`users`, `wallet_operations`, `wallets`.

Aucune table n'a été inventée (§2). Le périmètre est strictement celui
réellement utilisé par le backend.

---

## 2. Tests

| Test | Résultat |
|---|---|
| Fresh install (`DROP` + `CREATE` + `migrate.sh`) | **PASS** |
| Migration runner | **PASS** |
| Second migration (idempotence, §7) | **PASS** — 0 erreur |
| Full schema (`mysql nexus < full_schema.sql`) | **PASS** |
| Schema equivalence (§6) | **PASS** |
| SQL ↔ PHP contract (§4) | **PASS** — 171 colonnes vérifiées |

### Détail de l'équivalence

```
MIGRATION INSTALLATION           PASS
FULL SCHEMA INSTALLATION         PASS
TABLE COUNT                      MATCH (19)
COLUMN STRUCTURE                 MATCH (234 colonnes)
INDEXES                          MATCH (58)
FOREIGN KEYS                     MATCH (20)
ENUMS                            MATCH (25 colonnes ENUM)
SCHEMA EQUIVALENCE               PASS
```

### Les outils ont été validés par test négatif

Un outil qui affiche PASS sans savoir échouer ne prouve rien. Les deux
vérificateurs ont donc été testés contre une panne provoquée :

- **Comparateur** — ajout d'une colonne `colonne_intruse` dans une seule des
  deux bases → `DIFF détectée`, puis retour à `MATCH` après nettoyage.
- **Auditeur de contrat** — renommage de `revoked_tokens.expires_at` →
  `[FAIL] COLONNE_MANQUANTE : revoked_tokens.expires_at`, puis retour à `PASS`
  après restauration.

> Note de transparence : la première version de l'auditeur annonçait
> « PASS, 0 colonne qualifiée ». Le PASS était vide de sens — trois défauts
> successifs (délimiteur de regex, `token_get_all` joint par espaces, puis
> analyse limitée aux colonnes qualifiées alors que le SQL de Nexus n'en
> utilise presque pas) neutralisaient le contrôle. Corrigé : l'outil analyse
> désormais les colonnes des `INSERT INTO`, forme réellement employée, et
> vérifie 171 colonnes.

---

## 3. Sécurité

| Contrôle | Résultat |
|---|---|
| Secrets hardcodés dans `database/` | **PASS** — 0 |
| `.env` ignoré par Git | **PASS** — seul `.env.example` est versionné |
| Secrets providers dans le frontend | **PASS** — aucun |
| Séparation sandbox/production | **PASS** — `PROVIDER_{SLUG}_{SANDBOX\|PRODUCTION}_{CHAMP}` |
| Cross-tenant | **PASS** — `FORBIDDEN_CROSS_BUSINESS` en 403 |

L'architecture Provider Credentials (§9) est inchangée : emplacements prévus
pour `API_KEY`, `SECRET_KEY`, `CLIENT_ID`, `WEBHOOK_SECRET`, etc., **aucune
valeur réelle**, credentials lus depuis l'environnement ou chiffrés en base.

---

## 4. Problèmes trouvés et corrigés

### 4.1 Un schéma orphelin divergent (§14)

`database/migrations/schema.sql` — 237 lignes, **jamais exécuté** par
`migrate.sh`, **jamais référencé** nulle part.

Il définissait **7 tables fantômes** absentes de la base et inutilisées par le
code : `alerts`, `approval_requests`, `kyc_applications`, `providers`,
`sessions`, `teams`, `transaction_events`. Il contenait en plus un `INSERT` de
providers actifs (`Swan`, …).

C'était exactement la source de divergence que le §5 cherche à éliminer.
**Supprimé.**

### 4.2 Données de démonstration dans les migrations (§8)

Deux migrations de structure inséraient des données métier :

**`2026_08_10_kyc_origins.sql`** — créait une source de financement
« Mobile Money Ghana — MTN » marquée `verification_status = 'verified'` et
`supported_for_transfer = 1` pour un maximum de **10 utilisateurs réels**.

C'est le plus grave des deux : une source vérifiée est traitée par le
`FundingSourceEngine` comme une **origine de fonds réellement autorisée**. Une
migration de structure ouvrait donc un droit de transfert à partir d'une donnée
fictive — en contradiction directe avec le §4 (« ne jamais afficher une source
comme disponible si elle n'est pas réellement vérifiée »).

**`2026_08_10_wallet_core.sql`** — figeait 12 taux de change dans
`fx_rates_cache` avec un TTL de 24 h.

Les deux blocs ont été déplacés vers `database/seeds/`, marqués
**SANDBOX / DEVELOPMENT ONLY**. `demo_payment_accounts.sql` **refuse de
s'exécuter** sans `SET @NEXUS_ALLOW_DEMO_SEED = 1` — vérifié : sans le
garde-fou, 0 compte créé ; avec, 2 comptes créés.

**Aucune régression :** `ManualRateProvider` fournit déjà ces taux en PHP.
Vérifié cache vide → `/api/wallets/rates` renvoie `fx_rate_xaf: 655.957`.

Résultat : **0 `INSERT` dans `schema.sql`, les migrations et `full_schema.sql`.**

---

## 5. Problèmes trouvés et NON corrigés

### 5.1 `oauth_identities` — table morte

Créée par `2026_08_10_oauth_phone.sql`, **référencée par aucun code PHP**. Les
identités Google sont en réalité stockées dans `users.auth_provider` +
`users.provider_id` (vérifié dans `AuthController::google()`).

**Non supprimée volontairement :** une installation existante peut contenir des
lignes. La suppression mérite une migration dédiée après vérification en
production, pas une décision unilatérale pendant un gel de schéma.

### 5.2 Seeding de démonstration dans les contrôleurs de production

**Le point le plus important de ce rapport, et il ne relève pas du SQL.**

Sortir les seeds des migrations ne suffit pas : le seeding est aussi **codé en
dur dans les contrôleurs**, et s'exécute **à l'inscription et à chaque
connexion** :

- `AuthController::WELCOME_WALLETS` — crédite EUR 2 500, USD 1 200, GBP 500,
  XAF 1 500 000, USDT 1 200, USDC 500 à tout nouveau compte ;
- `AuthController::DEMO_TRANSACTIONS` — 5 transactions fictives avec des
  providers nommés (Swan, pawaPay, Thunes, Currencycloud) ;
- `AccountController::seedDemoAccountsAtLogin()` — sources « Compte courant —
  Swan » et « Wallet USDT (Ethereum) », marquées `verified`, **rejouées à
  chaque login** ;
- `NotificationController::seedDemoNotificationsIfEmpty()`.

**Aucun garde-fou `APP_ENV`.** En l'état, un déploiement en production
créditerait de l'argent fictif à chaque inscription — violation frontale des
§8, §16, §27 et §30.

Point positif : le ledger reste cohérent (§28). Vérifié — pour chaque devise,
solde des wallets = net des écritures :

| Devise | Wallets | Net ledger |
|---|---|---|
| EUR | 5 000,00 | 5 000,00 |
| GBP | 1 000,00 | 1 000,00 |
| USD | 2 400,00 | 2 400,00 |
| USDC | 1 000,00 | 1 000,00 |
| USDT | 2 400,00 | 2 400,00 |
| XAF | 3 000 000,00 | 3 000 000,00 |

L'argent de démo est donc correctement comptabilisé — mais il ne devrait pas
exister hors sandbox.

**Non corrigé ici** car cela relève du backend, explicitement hors périmètre de
cette étape (§12). **C'est le premier chantier de l'étape suivante.**

---

## 6. KYC — Sumsub

| Élément | État |
|---|---|
| Sumsub architecture | **NOT READY** |
| Applicant mapping | **NOT READY** |
| Webhook idempotency | **NOT READY** |
| Credential slots | **NOT READY** |
| Personal KYC | **NOT READY** |
| Business KYB | **NOT READY** |

**Aucune table KYC n'a été créée.** C'est délibéré, et c'est l'application
stricte du §2 : *« Ne crée pas des tables simplement parce qu'elles pourraient
être utiles un jour. »*

Le §1 demande de « préparer proprement la structure » Sumsub, mais concevoir
`sumsub_applicants` et `sumsub_webhook_events` sans décision arrêtée sur les
points suivants produirait un schéma à refaire :

1. **Portée de l'applicant** — un applicant par utilisateur, ou un par couple
   (utilisateur, niveau de vérification) ? Sumsub autorise plusieurs niveaux par
   applicant, ce qui change la clé unique.
2. **KYB et représentants** — une entreprise implique un applicant société *et*
   des applicants bénéficiaires effectifs. Relation 1-N à modéliser
   explicitement, sachant qu'il n'existe **aucune table `businesses`** :
   l'entité business est portée par `users.account_type` + `team_members`.
   C'est structurant et doit être tranché avant d'écrire le SQL.
3. **Mapping des statuts** — Sumsub expose `reviewStatus`
   (`init`/`pending`/`completed`) et `reviewResult`
   (`GREEN`/`RED` + `retry`/`final`). Le `PolicyEngine` consomme aujourd'hui un
   `kyc_level` (`none`/`basic`/`standard`). La correspondance doit être décidée
   au niveau produit, pas devinée.
4. **Idempotence webhook** — le §11 impose une clé unique
   `(provider, environment, event_id)`. Il faut confirmer que Sumsub fournit un
   identifiant d'événement stable, sinon la clé doit se rabattre sur
   `applicantId + type + createdAt`.

Le point de branchement existe déjà : le `PolicyEngine` lit `kyc_level`,
applique des plafonds (LIMITED 200 EUR/mois, STANDARD 2 000) et un seuil KYC à
1 000 EUR. **La source de vérité manque, pas le raccordement.**

> Conformément au §37 : aucune vérification KYC n'est simulée. Écrire un schéma
> Sumsub spéculatif serait précisément le « maquillage » que le contrat
> interdit. Je propose de cadrer ces 4 points ensemble, puis de produire la
> migration en une passe.

---

## 7. Fichiers livrés

```
nexus-api/
├── database/
│   ├── full_schema.sql                     ← NOUVEAU (généré)
│   ├── README.md                           ← NOUVEAU
│   ├── migrate.sh                          ← rappelle que les seeds sont séparés
│   ├── migrations/
│   │   ├── schema.sql                      ← SUPPRIMÉ (orphelin divergent)
│   │   ├── 2026_08_10_kyc_origins.sql      ← seed retiré
│   │   └── 2026_08_10_wallet_core.sql      ← seed retiré
│   └── seeds/                              ← NOUVEAU
│       ├── demo_fx_rates.sql
│       └── demo_payment_accounts.sql
└── scripts/
    ├── build_full_schema.sh                ← NOUVEAU
    ├── compare_schemas.sh                  ← NOUVEAU
    └── sql_contract_audit.php              ← NOUVEAU
```

---

## 8. Non-régression

| Contrôle | Résultat |
|---|---|
| Suite PHPUnit | **172 tests / 932 assertions OK** |
| Endpoints Personal | `/health`, `/dashboard/summary`, `/wallets`, `/accounts`, `/users/me/sessions` → **200** |
| Endpoints Business | `/business/overview`, `/business/treasury`, `/payments` → **200** |
| FX sans seed | `fx_rate_xaf: 655.957` servi par `ManualRateProvider` |

---

## 9. Étape suivante

Le SQL est figé, reproductible et vérifié. Conformément au §12, l'étape backend
peut commencer. Ordre proposé :

1. **Neutraliser le seeding de démonstration hors sandbox** (§5.2) — le seul
   point de ce rapport qui serait dangereux en production.
2. **RBAC 400 → 403** (§12 de l'étape précédente).
3. **Cadrer Sumsub** sur les 4 décisions ci-dessus, puis produire la migration.
4. **Supprimer `oauth_identities`** par migration dédiée.
