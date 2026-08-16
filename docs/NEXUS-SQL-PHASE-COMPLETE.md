# NEXUS — Rapport de phase SQL complète (avant backend)

Date : 2026-08-14
Périmètre : validation SQL de bout en bout avant tout travail backend.
Base de référence : gel SQL `docs/NEXUS-SQL-FREEZE.md` (commit `8062c93`).

Ce rapport ne réécrit pas le gel : il couvre les points **nouveaux** exigés
(matrice par domaine, précision monétaire, isolation multi-tenant, contrat
provider credentials, audit_logs) et re-confirme les contrôles structurants.

---

## Chiffres du schéma

| Mesure | Valeur |
|---|---|
| Tables | 19 |
| Colonnes | 234 |
| Index | 59 (+1) |
| Clés étrangères | 20 |
| Contraintes uniques | 30 (+1) |
| Colonnes FLOAT/DOUBLE/REAL | **0** |
| Migrations | 13 (+1) |

---

## §2 — Matrice par domaine

Aucune table n'a été inventée : la colonne « manquantes » décrit un état réel.

| Domaine | Tables existantes | Tables manquantes | Utilisé par le code | Action |
|---|---|---|---|---|
| **Auth** | `users`, `login_attempts`, `revoked_tokens`, `oauth_identities` | — | oui, sauf `oauth_identities` (0 fichier) | `oauth_identities` = table morte, à supprimer par migration (backlog) |
| **Personal** | `wallets`, `wallet_operations`, `transactions`, `payment_accounts`, `beneficiaries`, `notifications`, `quotes`, `fx_rates_cache` | — | oui (10, 5, 10, 2, 2, 2, 5, 3 fichiers) | RAS |
| **Business** | `team_members`, `payments`, `reconciliation_items`, `beneficiaries` | **`businesses`** (voir §10) | oui | Décision d'architecture à trancher côté backend, pas côté SQL |
| **Ledger** | `ledger_entries`, `idempotency_keys`, `wallet_operations` | — | oui | **CORRIGÉ** : unicité `(operation_id, sequence)` ajoutée |
| **Providers** | `provider_credentials` | — | oui (1 fichier) | **CORRIGÉ** : séparation sandbox/production |
| **KYC / Sumsub** | *(aucune)* — seulement `users.kyc_level`, `users.kyc_verified_at`, `users.country_of_residence`, `users.country_of_residence_verified_at` | `kyc_applicants`, `kyc_documents`, `kyc_webhook_events` (ou équivalent) | non | **NOT READY** — voir section dédiée |

---

## §3 — Précision monétaire — PASS

- **Aucune colonne FLOAT / DOUBLE / REAL dans toute la base** (vérifié via
  `information_schema`, résultat vide).
- Deux niveaux de précision, **intentionnels et documentés dans le code** :
  - source de vérité comptable à 8 décimales : `ledger_entries.amount`,
    `ledger_entries.balance_after`, `wallet_operations.*`, `fx_rate` → `DECIMAL(20,8)` ;
  - projection de solde au centime : `wallets.*`, `transactions.*`, `payments.*` → `DECIMAL(20,2)`.
- L'écart d'arrondi entre les deux est géré explicitement par
  `WalletService::HOLD_PROJECTION_TOLERANCE = '0.005'` (un demi-centime), avec
  justification en commentaire. Ce n'est pas une incohérence mais un choix assumé.

---

## §7 — Contrat `provider_credentials` — CORRIGÉ

| Contrôle | Résultat |
|---|---|
| Secrets en clair en base | **Non** — colonne unique `credentials_enc`, chiffrée applicativement (`Crypto::encrypt`) |
| APP_KEY présente en SQL | **Non** — aucune clé dans le SQL, chiffrement 100 % applicatif |
| Secret injecté par un script SQL | **Non** — aucun `INSERT INTO provider_credentials` dans `database/` |
| Convention `PROVIDER_{SLUG}_{ENV}_{FIELD}` | Compatible : `provider_slug` + `environment` + payload JSON chiffré (champs libres) |
| Coexistence SANDBOX / PRODUCTION | **était CASSÉE — corrigé** |

**Défaut trouvé (preuve à l'appui).** L'unicité portait sur
`(user_id, provider_slug)` sans `environment`. Conséquence démontrée en base :
enregistrer les identifiants de production **écrasait silencieusement** ceux de
sandbox (`ON DUPLICATE KEY UPDATE environment = VALUES(environment)`), ne
laissant qu'une seule ligne. Un même utilisateur ne pouvait pas détenir à la
fois un jeu sandbox et un jeu production — en contradiction directe avec
l'exigence SANDBOX ≠ PRODUCTION.

**Correction.** `uq_provider_creds_env (user_id, provider_slug, environment)`
remplace `uq_provider_creds`. Vérifié : les deux environnements coexistent
désormais, et le doublon strict (même environnement) reste rejeté.

> **Impact backend à traiter (hors périmètre SQL).**
> `ProviderCredentialController` suppose encore *une* ligne par provider :
> `SELECT ... WHERE user_id AND provider_slug LIMIT 1`, le `DELETE` et
> l'`UPDATE` de test ne filtrent pas sur `environment`. Ces requêtes doivent
> être qualifiées par environnement. Non corrigé ici : c'est du code backend,
> et la consigne est de s'arrêter au SQL.

---

## §9 — Ledger — CORRIGÉ

- Double-entrée confirmée : `entry_type ENUM('debit','credit')`, `sequence`
  ordonnant les écritures, `balance_after` pour la traçabilité, écriture dans
  une transaction `beginTransaction/commit/rollBack`.
- Traçabilité : `operation_id`, `reference_type` + `reference_id`, `metadata`.

**Défaut trouvé (preuve à l'appui).** Aucune contrainte n'empêchait deux
écritures identiques sur `(operation_id, sequence)` : l'insertion du même couple
deux fois de suite a bien produit **2 lignes**. L'idempotence applicative
(`uq_op_idempotency` sur `wallet_operations`) couvre le chemin nominal, mais la
base n'offrait aucun garde-fou contre un bug applicatif ou une écriture manuelle.

**Correction.** `uq_ledger_operation_sequence (operation_id, sequence)`.
Vérifié par test négatif : le doublon est rejeté (erreur 1062) tandis que le
couple légitime débit `sequence=1` / crédit `sequence=2` reste accepté. Le code
n'émet que les séquences 1 et 2 par opération : aucune régression possible.

---

## §10 — Isolation multi-tenant Business — PASS avec réserve

- **20 FK** couvrent l'intégralité des tables porteuses de données utilisateur ;
  chacune référence `users.id` (ou une entité elle-même rattachée), en
  `ON DELETE CASCADE` pour les données possédées et `SET NULL` pour les
  références historiques (`audit_logs.user_id`, `payments.beneficiary_id`,
  `reconciliation_items.transaction_id`, `wallet_operations.*_wallet_id`).
- **Orphelins impossibles** : vérifié en pratique — l'insertion d'un `wallet`
  pointant sur un `user_id` inexistant est rejetée (erreur 1452).
- Seules 4 tables n'ont pas de colonne propriétaire, et c'est légitime :
  `users` (la racine), `login_attempts` (indexée par email/IP, pré-authentification),
  `fx_rates_cache` (données de marché globales), `ledger_entries` (rattachée via
  `wallet_id` → `wallets.user_id`).
- `team_members` : unicité `(business_user_id, member_user_id)`, index sur les
  deux côtés, rôles en ENUM — pas d'ambiguïté d'appartenance.

**Réserve (structurelle, non corrigée à dessein).** Il n'existe **pas de table
`businesses`** : l'entité business est portée par `users.account_type='business'`
et `team_members.business_user_id`. L'isolation repose donc entièrement sur des
filtres applicatifs `WHERE user_id = ...`. La base ne peut pas, à elle seule,
empêcher un accès inter-business : aucune contrainte SQL ne relie une
`transaction` d'un membre à son business. Créer une table `businesses` est une
**décision d'architecture** qui impacte le backend entier — je ne l'ai pas prise
unilatéralement (§2 : ne pas inventer de tables). À arbitrer avant le backend.

---

## §11 — `audit_logs` — PASS

- Colonnes : `user_id`, `action`, `entity_type`, `entity_id`, `metadata`,
  `ip_address`, `created_at`. Aucune colonne destinée à un secret.
- Revue des 3 fichiers qui écrivent des audits : les métadonnées enregistrées
  sont `environment`, `reachable`, `latency_ms`, ou vides. Le changement de mot
  de passe journalise `password_changed` **sans aucune valeur**.
- Aucun mot de passe, token, clé d'API ni identifiant provider n'est écrit dans
  `audit_logs`. `WebhookVerifier` journalise explicitement « sans le secret ».

---

## §12 — Idempotence & fresh install — PASS

- **Fresh install** : base détruite puis recréée depuis zéro → 19 tables, 13
  migrations, sans erreur.
- **Second passage** : `migrate.sh` rejoué 2 fois de plus, exit 0, et l'index
  ajouté reste présent **une seule fois** (`ADD ... IF NOT EXISTS`).
- **Équivalence des deux chemins d'installation** : `compare_schemas.sh` → PASS
  (19 tables, 234 colonnes, 59 index, 20 FK, 25 ENUM identiques entre
  installation par migrations et par `full_schema.sql`).

---

## Défaut d'outillage corrigé — faux PASS silencieux

En régénérant `full_schema.sql`, `compare_schemas.sh` a répondu **PASS alors que
la nouvelle migration n'était appliquée nulle part**. Cause : les trois scripts
(`migrate.sh`, `build_full_schema.sh`, `compare_schemas.sh`) embarquaient chacun
**leur propre copie** de la liste des migrations. Ajouter une migration sans
éditer les trois produisait un `full_schema.sql` périmé **et** un contrôle
d'équivalence qui comparait deux installations périmées identiques — donc vert.

C'était le défaut le plus dangereux de la session : l'outil censé détecter les
divergences était précisément celui qui les masquait.

**Correction.** Création de `database/migrations.manifest`, **source de vérité
unique** lue par les trois scripts ; plus aucune liste codée en dur. Validé par
test négatif : désactiver une ligne du manifeste fait bien passer `migrate.sh`
de 13 à 12 migrations, puis la restauration ramène à 13.

---

## KYC / KYB (Sumsub) — NOT READY

Constat factuel, sans complaisance :

- **Aucune table KYC** en base (ni applicants, ni documents, ni événements webhook).
- **Aucune occurrence de « sumsub »** dans `src/`, `database/` ou `.env.example`.
- Le seul support KYC est déclaratif : `users.kyc_level`, `users.kyc_verified_at`,
  `users.country_of_residence(_verified_at)` — c'est-à-dire un niveau posé à la
  main, exactement ce qu'un « KYC maison » ne doit pas être.
- Aucune abstraction `KYCProvider`, aucun `SumsubAdapter`.

Rien n'a été simulé ni marqué comme vérifié. L'intégration Sumsub reste
entièrement à cadrer puis à construire (provider officiel obligatoire, KYC
maison interdit, webhook idempotent sur `provider+environment+event_id`,
secrets jamais en Git ni en SQL).

---

## §13 — Synthèse finale

### PASS
| Contrôle | Résultat |
|---|---|
| Tables | 19, cohérentes entre les deux chemins d'installation |
| Migrations | 13, ordonnées, idempotentes (rejouées 3×) |
| Foreign keys | 20, aucune orpheline possible (vérifié) |
| Indexes | 59, cohérents |
| Précision monétaire | 0 FLOAT/DOUBLE ; DECIMAL partout, écart 8dp/2dp assumé et documenté |
| Ledger | double-entrée atomique et traçable |
| Provider credentials | chiffrées, aucun secret en clair, aucun secret en SQL |
| Audit logs | aucun secret journalisé |
| Business isolation | 20 FK, aucun orphelin, `team_members` sans ambiguïté |
| Idempotence | `migrate.sh` rejouable sans effet de bord |
| Fresh install | base vierge → schéma complet, sans erreur |
| Second migration run | exit 0, aucun doublon d'index |
| SQL ↔ Backend | `sql_contract_audit.php` PASS, 171 colonnes, 0 incohérence |
| Tests backend | **172 tests / 932 assertions OK** (aucune régression) |

### FIXED
1. **Ledger** — ajout de `uq_ledger_operation_sequence (operation_id, sequence)` :
   les doublons d'écriture comptable étaient acceptés par la base.
2. **Provider credentials** — `uq_provider_creds_env (user_id, provider_slug, environment)`
   remplace `uq_provider_creds` : sandbox et production ne pouvaient pas coexister,
   la production écrasait silencieusement la sandbox.
3. **Outillage** — `database/migrations.manifest` : suppression des 3 listes de
   migrations dupliquées qui produisaient un faux PASS d'équivalence de schéma.

### REMAINING
1. **KYC / KYB Sumsub — NOT READY.** Rien n'existe. À cadrer puis construire.
2. **Pas de table `businesses`.** L'isolation multi-tenant repose sur des filtres
   applicatifs, pas sur des contraintes SQL. Décision d'architecture à arbitrer.
3. **`ProviderCredentialController` à qualifier par environnement** (SELECT/DELETE/UPDATE
   ne filtrent pas sur `environment`) — conséquence backend de la correction n°2.
4. **`oauth_identities`** : table morte (0 référence), à supprimer par migration.
5. **Seeding démo non gardé par `APP_ENV`** (chantier n°1 backend, inchangé).
6. RBAC 400→403, `GOOGLE_CLIENT_ID` en dur ×3, 1 erreur de lint frontend.

---

**STOP — fin de la phase SQL.** Aucun travail backend n'a été engagé.
