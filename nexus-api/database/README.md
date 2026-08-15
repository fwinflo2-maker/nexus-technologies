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
DB_USER=nexus DB_PASS=nexus_dev_pw php scripts/setup_test_db.php
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

Chiffres relevés sur la base réellement installée par le runner de migrations
(`information_schema`), et non recopiés à la main :

| Élément | Valeur |
|---|---|
| Tables | 20 |
| Colonnes | 259 |
| Index | 75 |
| Contraintes uniques | 35 |
| Clés étrangères | 23 |
| Colonnes ENUM | 38 |
| Migrations | 22 (+ `schema.sql`) |

## SQL de référence — `database/sql/`

Le dépôt doit pouvoir être reconstruit depuis GitHub seul. Trois fichiers de
référence, **générés depuis la base réellement installée** (jamais écrits à la
main) :

| Fichier | Contenu |
|---|---|
| `sql/nexus_schema.sql` | structure seule — aucune donnée |
| `sql/nexus_seed.sql` | données de démonstration (sandbox uniquement) |
| `sql/nexus_full.sql` | structure + données de démonstration |

Régénération après toute modification du schéma :

```bash
bash scripts/export_sql_reference.sh [hôte] [utilisateur] [motdepasse]
```

Le script reconstruit une base temporaire via le manifeste de migrations,
exporte les trois fichiers, puis supprime la base. Le SQL décrit donc ce que
le dépôt sait installer, pas l'état accidentel d'un poste de travail.

> ⚠️ `nexus_seed.sql` et la partie données de `nexus_full.sql` sont des jeux de
> **démonstration**. Ils ne doivent jamais être chargés en production.

Reproductibilité vérifiée : une base vierge reconstruite depuis
`sql/nexus_schema.sql` présente les mêmes 259 colonnes que l'installation par
migrations, et la suite complète (555 tests) passe dessus.

### Table supprimée — `oauth_identities` (migration 0.21)

`oauth_identities`, créée par `2026_08_10_oauth_phone.sql`, n'était **référencée
par aucun code PHP ni test** et n'avait **aucune clé étrangère entrante ni
aucune donnée**. Les identités Google sont stockées dans `users.auth_provider`
+ `users.provider_id`. Google Auth étant désactivée (commit `9b6dfbe`), la
table a été **supprimée** par la migration `2026_08_15_drop_oauth_identities.sql`
— voir `docs/PHASE4-BOUCLE-B.md`.
