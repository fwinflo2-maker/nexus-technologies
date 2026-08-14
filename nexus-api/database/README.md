# NEXUS — Base de données

Trois familles de fichiers, strictement séparées (§8) :

```
database/
├── schema.sql              # Socle initial
├── migrations/             # Évolutions versionnées, dans l'ordre
├── full_schema.sql         # État complet GÉNÉRÉ — installation en une passe
└── seeds/                  # Données de démonstration — SANDBOX UNIQUEMENT
```

**Aucun fichier de structure ne contient de données métier.** Pas de solde, pas
de transaction, pas de taux de change, pas de provider actif.

---

## Installer

### Option A — installation complète (recommandée pour une base vierge)

```bash
mysql -u nexus -p -e "DROP DATABASE IF EXISTS nexus;
  CREATE DATABASE nexus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u nexus -p nexus < database/full_schema.sql
```

### Option B — par migrations (chemin d'évolution)

```bash
bash database/migrate.sh [hôte] [utilisateur] [motdepasse]
# défauts : 127.0.0.1 / nexus / nexus_dev_pw
```

Le runner est **idempotent** : le relancer est sans effet.

Les deux options produisent **exactement la même structure** — c'est vérifié
automatiquement (voir plus bas).

---

## `full_schema.sql` est généré, pas écrit à la main

Ne jamais l'éditer directement. Il est dérivé de la base reconstruite par les
migrations, ce qui rend la divergence impossible :

```bash
bash scripts/build_full_schema.sh
```

Après **toute** nouvelle migration :

1. `bash scripts/build_full_schema.sh` — régénérer ;
2. `bash scripts/compare_schemas.sh` — vérifier l'équivalence ;
3. `DB_USER=... php scripts/sql_contract_audit.php` — vérifier le contrat SQL ↔ PHP.

---

## Vérifications

### Équivalence des deux modes d'installation

```bash
bash scripts/compare_schemas.sh
```

Installe la base par les deux chemins dans deux bases distinctes, puis compare
tables, colonnes, types, nullabilité, valeurs par défaut, ENUM, index et clés
étrangères.

### Contrat SQL ↔ PHP

```bash
DB_USER=nexus DB_PASS=nexus_dev_pw php scripts/sql_contract_audit.php nexus
```

Confronte les tables et colonnes réellement citées dans le code PHP au schéma
en base. Détecte le cas classique « le code lit `available_balance` alors que la
colonne s'appelle `available` », invisible jusqu'à l'exécution.

Code de sortie 1 en cas d'incohérence : utilisable en CI.

### Base de test

```bash
DB_USER=nexus DB_PASS=nexus_dev_pw php setup_test_db.php
```

---

## Seeds — sandbox uniquement

> ⚠️ **Ne jamais exécuter en production.**

```bash
# Taux de change de démonstration
mysql -u nexus -p nexus < database/seeds/demo_fx_rates.sql

# Source de financement Ghana (garde-fou explicite obligatoire)
(echo "SET @NEXUS_ALLOW_DEMO_SEED = 1;"; \
 cat database/seeds/demo_payment_accounts.sql) | mysql -u nexus -p nexus
```

`demo_payment_accounts.sql` refuse de s'exécuter sans que la variable
`@NEXUS_ALLOW_DEMO_SEED` soit positionnée à 1 : il crée une source de
financement `verified`, donc autorisée aux transferts.

L'application **fonctionne sans aucun seed** : `ManualRateProvider` fournit les
taux de repli lorsque `fx_rates_cache` est vide.

---

## État actuel

| Élément | Valeur |
|---|---|
| Tables | 19 |
| Colonnes | 234 |
| Index | 58 |
| Contraintes uniques | 29 |
| Clés étrangères | 20 |
| Colonnes ENUM | 25 |
| Migrations | 12 (+ `schema.sql`) |

### Table à surveiller

`oauth_identities` est créée par `2026_08_10_oauth_phone.sql` mais **n'est
référencée par aucun code PHP** : les identités Google sont stockées dans
`users.auth_provider` + `users.provider_id`. Elle est conservée pour ne pas
détruire de données sur une installation existante, mais devrait être
supprimée par une migration dédiée après vérification.
