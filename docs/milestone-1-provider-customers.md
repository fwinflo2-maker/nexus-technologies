# MILESTONE 1 — PROVIDER CUSTOMER MAPPING + TEST HARNESS

Date : 2026-09-01  
Prérequis : [milestone-0-baseline.md](./milestone-0-baseline.md)

---

## Objectif

Mapper explicitement un utilisateur Nexus vers son identité customer chez un provider financier, de façon **provider-agnostic**, avec isolation sandbox/production et idempotence.

Premier cas d’usage futur : **Cashramp** (Milestone 3+). Ce milestone ne contient **aucun** appel API Cashramp.

---

## Schéma — `provider_customers`

| Colonne | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `user_id` | FK → `users.id` | CASCADE delete |
| `provider_slug` | VARCHAR(50) | ex. `cashramp`, `pawapay` |
| `provider_customer_id` | VARCHAR(191) | ID côté provider |
| `environment` | ENUM sandbox/production | |
| `status` | ENUM PENDING/ACTIVE/SUSPENDED/FAILED | |
| `metadata` | JSON nullable | Données non normalisées, **sans secrets** |
| `created_at` / `updated_at` | DATETIME UTC | |

### Contraintes

- `UNIQUE (user_id, provider_slug, environment)`
- Index : `user_id`, `provider_slug`, `provider_customer_id`, `environment`, `status`

### Distinction des tables existantes

| Table | Rôle |
|---|---|
| `provider_customers` | **User → customer provider** (nouveau) |
| `provider_accounts` | Comptes trésorerie Nexus chez le provider |
| `payment_accounts` | Sources de financement / origines KYC |
| `provider_credentials` | Secrets API chiffrés |

Migration : `sql/migrations/2026_09_01_provider_customers.sql`

---

## Service — `ProviderCustomerService`

Emplacement : `htdocs/api-app/src/Services/ProviderCustomerService.php`

| Méthode | Comportement |
|---|---|
| `getCustomer()` | Lecture mapping existant ou `null` |
| `createCustomer()` | Insert ; 409 si déjà présent |
| `getOrCreateCustomer()` | Idempotent ; provisioner injectable |
| `syncCustomer()` | Mise à jour statut / ID / metadata |

### Idempotence

1. Contrainte `UNIQUE(user_id, provider_slug, environment)`
2. Transaction + `SELECT … FOR UPDATE`
3. Gestion `SQLSTATE 23000` (duplicate key)

Le provisioner callable simule le futur adapter :

```text
ProviderCustomerService → callable/adaptateur → create provider customer
```

Aucune branche `if ($provider === 'cashramp')` dans le service.

### Isolation environnement

`(user 42, cashramp, sandbox)` et `(user 42, cashramp, production)` sont deux lignes distinctes. Jamais de repli sandbox → production.

### Metadata

Interdit : clés contenant `secret`, `password`, `token`, `api_key`, `credentials`, `authorization`.

---

## Intégration Cashramp (futur)

Milestone 3+ :

```text
KYC/KYB → PolicyEngine → ProviderCustomerService::getOrCreateCustomer()
                              ↓
                         CashrampAdapter::createCustomer()
                              ↓
                         provider_customers
```

---

## Test harness

Emplacement : `htdocs/api-app/`

| Fichier | Rôle |
|---|---|
| `composer.json` | PHPUnit 10, script `composer test` |
| `phpunit.xml` | Base `nexus_test` |
| `tests/bootstrap.php` | Constantes + autoload |
| `scripts/setup_test_db.php` | Applique `sql/schema.sql` + manifeste |

### Commandes

```bash
cd htdocs/api-app
composer install
composer db:test
composer test
```

**Jamais** exécuter les tests contre la base de production Hostinger.

Variables : `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_TEST_NAME` (défaut `nexus_test`).

### Tests Milestone 1

- `ProviderCustomerSchemaTest` — table, FK, unicité
- `ProviderCustomerServiceTest` — CRUD logique, idempotence, isolation, metadata

---

## Sécurité

- Pas de route publique ajoutée
- Pas de secrets dans `metadata`
- Credentials restent dans `ProviderCredentialService`

---

## Régression

Aucune modification de :

- `PawaPayAdapter`, `ExecutionEngine`, webhooks pawaPay
- Comportement routing / ledger existant

Seul ajout transversal : `Database::resetConnection()` pour les tests.
