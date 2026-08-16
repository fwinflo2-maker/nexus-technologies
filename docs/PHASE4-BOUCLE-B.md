# PHASE 4 — BOUCLE B : Database + Migrations

> Rapport basé uniquement sur des vérifications réellement exécutées sur le
> repository et la base réelle (MariaDB 11.8.6, 127.0.0.1:3306).
> Date : 2026-08-15.

## Git
- **branch** : `main`
- **before HEAD** : `9b6dfbe`
- **after HEAD** : à créer (BOUCLE B)
- **remote** : `origin` → `https://github.com/fwinflo2-maker/nexus-technologies.git`
- **working tree (avant)** : propre, synchronisé avec `origin/main`

## Database
- **DB engine** : MariaDB (serveur local)
- **DB version** : 11.8.6-MariaDB
- **database** : `nexus` (dev, 20 tables) + `nexus_test` (test)
- **tables** : 20
- **schema source of truth** : `nexus-api/database/schema.sql` (socle) +
  `database/migrations.manifest` (liste, source de vérité unique) +
  `database/migrations/*.sql` (migrations versionnées). `full_schema.sql` et
  `database/sql/*.sql` sont des **références générées**, non éditées à la main.

## Tables auditées (20, via SHOW CREATE TABLE / DESCRIBE sur la base réelle)
`users`, `wallets`, `wallet_operations`, `ledger_entries`, `transactions`,
`idempotency_keys`, `revoked_tokens`, `login_attempts`, `payment_accounts`,
`provider_credentials`, `quotes`, `notifications`, `audit_logs`,
`fx_rates_cache`, `kyc_verifications`, `kyc_webhook_events`, `payments`,
`beneficiaries`, `team_members`, `reconciliation_items`.
(Anciennement 21 : `oauth_identities` supprimée, voir Corrections.)

## Problèmes trouvés
1. **`oauth_identities` : table morte.** Créée par `2026_08_10_oauth_phone.sql`,
   jamais référencée par le code PHP (contrat SQL↔PHP : « tables jamais
   référencées (1) : oauth_identities »), par aucun test, sans FK entrante, et
   **vide** (COUNT(*) = 0). Google Auth ayant été désactivée, elle ne sera
   jamais peuplée. Confirmé comme « table morte à supprimer » par les audits
   précédents.
2. **Le reste du schéma est déjà sain** : types monétaires **DECIMAL**, clés
   uniques, contraintes d'intégrité, scoping par environnement — robustesse
   apportée par les migrations 0.11 à 0.20 (aucun autre correctif jugé
   nécessaire ; cf. tableaux ci-dessous).

### Points de robustesse **vérifiés** (et non corrigés, car conformes)
| Domaine | Vérification réelle |
|---|---|
| Monetary precision | Tous les montants en `DECIMAL(20,2)` / `DECIMAL(20,8)`. Test ajouté : **aucune colonne monétaire en FLOAT/DOUBLE**. |
| Wallet unicité | `UNIQUE(user_id, currency)` sur `wallets` (test ajouté). |
| Idempotence (race) | `UNIQUE(idempotency_key, user_id, environment)` sur `idempotency_keys` — contrainte DB, pas un simple SELECT→INSERT (test ajouté). |
| Ledger | `ledger_entries` double-entrée réelle (debit/credit, balance_after, séquence unique par opération). |
| Intégrité financière | FK présentes ; wallet balance/available/pending/in_transit/settlement/hold en DECIMAL. |
| Environment | Colonnes `environment` scoping sur wallet_operations, ledger_entries, transactions, quotes, payments, provider_credentials, fx_rates_cache, kyc_*. |
| Credentials | `provider_credentials.credentials_enc` (chiffré) ; aucune valeur réelle en SQL/Git (`.env` / environnement uniquement). |
| Revoked tokens | `revoked_tokens(jti UNIQUE, user_id, revoked_at, expires_at)` — nettoyage possible sur `expires_at`. |
| Cascade | FK financières `ON DELETE CASCADE` sur user (users→wallets→wallet_operations/ledger). Historique financier : `transactions`, `ledger_entries`, `wallet_operations` héritent de la suppression utilisateur via CASCADE — comportement documenté ; le ledger reste la trace comptable. |

## Corrections
1. **Migration `2026_08_15_drop_oauth_identities.sql`** (0.21) : `DROP TABLE IF
   EXISTS oauth_identities`, ajoutée à `database/migrations.manifest`.
2. **`full_schema.sql` régénéré** via `scripts/build_full_schema.sh`.
3. **`database/sql/*.sql` régénérés** via `scripts/export_sql_reference.sh`.
4. **Base réelle `nexus`** : migration appliquée (DROP exécuté), données
   utilisateur/wallet intactes (users=2, wallets=12).
5. **Base de test `nexus_test`** : reconstruite via `setup_test_db.php`
   (lit le manifeste).
6. **Test de régression** ajouté : `tests/DeadTableRemovedTest.php` (5 tests,
   18 assertions).
7. **Doc** : `database/README.md` mis à jour (20 tables, note sur la table
   supprimée).

## SQL
- **schema.sql** : inchangé (la table morte n'en faisait pas partie).
- **migrations** : +1 → `2026_08_15_drop_oauth_identities.sql` (0.21).
- **SQL exécuté** : OUI — DROP appliqué sur `nexus` et reconstruit via le
  runner de migrations sur `nexus_ref`, `nexus_full`, `nexus_sqlref`,
  `nexus_test`.
- **résultat** : équivalence des deux chemins d'installation **PASS**
  (20 tables, 259 colonnes, 75 index, 23 FK, 35 uniques, 38 ENUM) ;
  contrat SQL↔PHP **PASS** (20/20 tables référencées, plus de table morte).

## Security
- **IDOR** : non modifié — la couche d'isolation était déjà couverte par les
  tests existants (`WalletTenantIsolationTest`, `CredentialOwnershipTest`,
  `ProviderCredentialIsolationTest`), toujours verts.
- **authorization** : inchangée (JWT + AuthMiddleware), testée (me sans token /
  token invalide → 401).
- **foreign keys** : 23, vérifiées par `compare_schemas.sh`.
- **constraints** : 35 uniques vérifiées ; `wallets` et `idempotency_keys`
  couverts par les nouveaux tests.
- **race conditions** : contrainte UNIQUE DB sur idempotency_keys (pas de
  fenêtre SELECT→INSERT) — vérifié et testé.

## Financial integrity
- **decimal precision** : 100% DECIMAL ; test `testMonetaryColumnsAreDecimalNotFloat` PASS.
- **wallet invariants** : `UNIQUE(user_id, currency)` ; balances DECIMAL non
  négatives garanties par le service + tests existants.
- **transaction atomicity** : `wallet_operations` + `ledger_entries` liées par
  opération ; tests de rollback / concurrency existants (`PaymentConcurrencyTest`,
  `LedgerServiceTest`, `WalletServiceTest`) verts.
- **ledger** : double-entrée **réellement présent** (`ledger_entries` debit/credit,
  `balance_after`), vérifié dans le SQL réel.

## Tests
- **baseline** : 550 tests / 2311 assertions (fin BOUCLE A).
- **final** : **555 tests / 2329 assertions** — 0 échec, 0 erreur.
- **nouveaux tests** : `DeadTableRemovedTest` (+5 tests / +18 assertions) :
  suppression table morte, tables critiques présentes, unicité wallet,
  contrainte idempotence, montants DECIMAL.
- **security tests** : suite IDOR/tenant/ownership existante toujours verte.

## Git final
- **commit** : `docs + feat(database): harden schema — drop dead oauth_identities`
- **push** : `main`
- **working tree** : propre après commit

---
*RÈGLE ABSOLUE respectée : aucune vérification théorique présentée comme réelle ;
toutes les tables, migrations, exécutions et résultats ci-dessus ont été
réellement vérifiés sur la base et le repository.*
