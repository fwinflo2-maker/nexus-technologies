# NEXUS — Audit de reprise (boucle 12)

Session de reprise. **Le dépôt est la source de vérité** : rien issu des
rapports précédents n'a été tenu pour acquis. Tout ce qui suit a été vérifié
par exécution réelle, pas par lecture.

## Baseline mesurée

| Contrôle | Commande | Résultat |
|---|---|---|
| Suite de tests | `phpunit` sur MySQL réel | **459 tests, 2087 assertions, OK** |
| Schéma ↔ migrations | `scripts/compare_schemas.sh` | **PASS** — 21 tables, 264 colonnes, 77 index, 24 FK, 37 ENUM |
| Installation par migrations | `scripts/setup_test_db.php` | schéma + 19 migrations appliqués sans erreur |
| Typecheck frontend | `tsc -b` | 0 erreur |
| Lint frontend | `oxlint` | 0 erreur, 6 avertissements connus |
| Build agents | `tsc` | 0 erreur |

Environnement : PHP 8.4.24, MariaDB 11.8.6, Node 20.

## Vérification des correctifs antérieurs (non-régression)

Vérifiés **présents dans le code actuel**, pas seulement dans les rapports :

- `EnvironmentGuard`, `ExecutionContext`, `ExecutionEnvironment` — présents ;
- `platform_role` : ENUM à 11 valeurs, `NOT NULL DEFAULT 'user'`, index dédié ;
- séparation `account_type` / `platform_role` — la colonne n'est écrite sur
  aucun chemin utilisateur (`grep` sur INSERT/UPDATE : aucun résultat) ;
- `register` valide `account_type` contre une liste blanche et n'accepte
  jamais `platform_role` ;
- `updateProfile` : allowlist stricte (`full_name`, `phone`,
  `country_of_residence`) — pas d'élévation de privilège par mass assignment ;
- honnêteté providers : `AbstractProviderAdapter` lève
  `ProviderOperationNotImplemented` sur les 6 opérations métier, jamais un
  faux succès ;
- honnêteté KYC : `KycController::session` renvoie 503
  `KYC_PROVIDER_NOT_CONFIGURED` si le provider est absent ;
- `DemoMode` : refus inconditionnel du seeding en production.

Aucune régression détectée sur ces points.

## File de travail

### CRITICAL

**C1 — Le filtrage des sanctions est un no-op qui se déclare conforme.**

`PolicyEngine::SANCTION_LIST` est une constante **vide**. La boucle de
contrôle (§3) itère donc sur zéro élément, ne teste rien, et la méthode
retourne :

> `Tous les contrôles de conformité sont passés.`

C'est exactement le motif interdit par la règle d'honnêteté (§37) : afficher
un succès pour une opération qui n'a pas eu lieu. Le sujet est aggravant sur
trois points :

1. `PolicyEngine::evaluate()` est sur le chemin de production réel — appelé
   par `QuoteController::create` et par `QuoteService::computeRoutes`, donc
   par le Send Personal **et** les paiements Business ;
2. le verdict est présenté à l'utilisateur et journalisé comme un contrôle
   de conformité effectué ;
3. **aucun test ne couvre les sanctions** (`grep -i sanction tests/` → vide).
   Les 459 tests verts ne disent donc rien de ce trou.

Un moteur de conformité qui affirme avoir filtré les sanctions alors qu'il
n'a consulté aucune liste est un risque réglementaire direct, pas une dette
technique.

### HIGH

**H1 — Le service d'agents Node n'a aucune authentification.**
`agents/src/index.ts` expose `POST /api/intent` et `POST /api/execute` sans
middleware d'auth, avec `cors()` grand ouvert. `ExecutionAgent.execute()`
fabrique un `transactionId`, une `idempotencyKey`, une `ledgerEntry` avec
`fees: 4.5` en dur et retourne `success: true` — sans jamais toucher le
ledger réel. Le frontend ne l'appelle pas (`AgentsPage.tsx` est une page
descriptive statique) et le README le qualifie de « conceptuel », donc
l'exposition n'est pas active en pratique — mais le service est démarrable
et ment sur son résultat. À isoler explicitement ou à supprimer.

**H2 — `ComplianceAgent.evaluate()` code les mêmes faux contrôles.**
`checks = { kyc: true, aml: true, sanctions: true, … }` en dur, puis
« Tous les contrôles sont passés ». Même violation que C1, dans la couche
agents.

### MEDIUM

**M1 — `CapabilityEngine::PERFORMANCE_SCORES` : scores de fiabilité en dur.**
Documenté comme « simulation démo », mais alimente le scoring du
`RoutingEngine`, donc le classement des routes proposées au client. Le
commentaire est honnête, l'affichage ne l'est pas nécessairement.

**M2 — 6 avertissements `react-refresh/only-export-components`.**
Fichiers exportant à la fois des composants et des constantes/fonctions.

### LOW

**L1 — Docs dupliquées / archivées** (`NEXUS TECHNOLOGIES.md` racine 58 Ko vs
`docs/NEXUS-TECHNOLOGIES.md`, HTML dupliqués entre `docs/` et
`nexus-api/docs/`).
**L2 — Captures PNG à la racine** (0,5 Mo) à déplacer dans `docs/assets/`.
**L3 — Gros fichiers** : `WalletService.php` (1076 l.), `LedgerService.php`
(942 l.), `client.ts` (1331 l.), `SendPage.tsx` (947 l.).

## Découvertes en cours de boucle (invisibles à la lecture du code)

Deux défauts trouvés en **observant la base après une inscription réelle**,
pas en relisant les sources — et qu'aucun des 470 tests ne détectait.

**H3 — `NEXUS_DEMO_SEED=0` n'éteignait rien.** `getenv()` rend la chaîne
`"0"`, falsy en PHP ; le `?: ''` la transformait en chaîne vide, absente de la
liste d'arrêt. La valeur d'extinction documentée était donc inopérante.
*Corrigé, 11 tests ajoutés (DemoMode n'en avait aucun), mutation vérifiée.*

**H4 — Le bonus de bienvenue était écrit en `production`.** La boucle 17 avait
corrigé `seedDemoTransactions()`, mais le crédit des wallets passe par
`LedgerService::credit()`, qui sans `ExecutionContext` retombe sur
`ProviderConfig::defaultEnvironment()`. Constaté en base : six
`wallet_operations` fictives marquées `production`.
*Corrigé, vérifié en HTTP avec `PROVIDERS_ENV=production`.*

**M3 — Quatre tests d'atomicité comptaient toute la base.** `COUNT(*)` global
attendu à 0 : faux rouge dès qu'une ligne étrangère existe, et faux vert si un
rollback défaillant écrivait sous un autre utilisateur. *Scopés aux fixtures.*

### BLOCKED (dépendance externe réelle)

- **Intégration réelle des 22 providers** — nécessite des credentials
  sandbox/production que le dépôt n'a pas, et ne doit pas avoir. Le
  comportement actuel (lever `ProviderOperationNotImplemented`) est le
  comportement correct : il refuse au lieu de simuler.
- **Liste de sanctions réelle** (OFAC/UE/ONU) — nécessite une source de
  données ou un provider de screening. Voir le traitement de C1 : en
  l'absence de source, le système doit **refuser ou signaler**, jamais
  déclarer un contrôle passé.

## État à la fin de la boucle 12

| | Avant | Après |
|---|---|---|
| Tests PHPUnit | 459 (2087 assertions) | **484 (2141 assertions)** |
| Schéma ↔ migrations | PASS | PASS |
| Faux succès sur chemin financier | 3 (sanctions, agents, bonus) | **0 détecté** |
| Service d'agents | ouvert, sans auth | jeton obligatoire, fail-closed |
| CI | aucune | 4 jobs (API ×2 PHP, schéma, front, agents) |

### Corrigé et vérifié

- **C1** filtrage des sanctions — no-op qui se déclarait conforme
- **H1** service d'agents sans authentification
- **H2** verdicts de conformité et devis fabriqués par les agents
- **H3** `NEXUS_DEMO_SEED=0` inopérant
- **H4** bonus de bienvenue écrit en production
- **M3** tests d'atomicité dépendant de l'état global de la base

Chaque correctif a suivi la boucle complète : FIX → TEST → MUTATION → HTTP →
SQL → SECURITY → RE-AUDIT. Les mutations sont documentées dans les messages
de commit (nombre de tests tués par mutation).

### Reste ouvert

- **M1** `CapabilityEngine::PERFORMANCE_SCORES` — scores de fiabilité en dur
  alimentant le classement des routes. À remplacer par des métriques mesurées
  (table `providers` ou service de métriques) ; en attendant, la valeur est
  affichée comme une estimation.
- **M2** 6 avertissements `react-refresh/only-export-components`.
- **L1–L3** docs dupliquées, captures à la racine, fichiers > 900 lignes.

### Note de méthode

Les deux défauts les plus intéressants de cette boucle (H3, H4) n'étaient pas
visibles à la lecture : le code *paraissait* correct, avec un garde-fou
explicite et un commentaire « §29 : jamais en production ». Ils n'ont été
trouvés qu'en exécutant le vrai parcours et en interrogeant la base derrière.
Une suite verte prouve l'absence des régressions qu'elle couvre, pas
l'honnêteté du système.

---

## Addendum — la CI a révélé une famille entière de défauts

Le premier run de la CI a échoué. Non pas à cause du workflow, mais parce
qu'il exécutait pour la première fois le projet **sur MySQL 8** au lieu de
MariaDB. Quatre défauts de portabilité en sont sortis, tous invisibles en
développement (XAMPP et la plupart des postes tournent sous MariaDB, plus
permissive) :

| # | Défaut | Erreur MySQL |
|---|---|---|
| P1 | `ADD COLUMN/KEY IF NOT EXISTS` — extension MariaDB, 27 clauses sur 7 migrations | 1064 |
| P2 | `PERSISTENT` au lieu de `STORED` (colonne générée) | 1064 |
| P3 | FK `ON DELETE CASCADE` sur la colonne de base d'une colonne générée `STORED` | 1215 |
| P4 | `full_schema.sql` figeait la forme MariaDB des colonnes JSON (`longtext + CHECK json_valid`) | divergence de types |

Conséquence réelle : **le projet était inapplicable sur MySQL 8**, alors que
`README.dev.md` annonce « MariaDB / **MySQL 8+** ». Aucun test ne pouvait le
détecter — ils tournaient tous sur le même moteur que le poste de
développement.

### Un faux PASS dans l'outil d'audit lui-même

En corrigeant le job de schéma, `compare_schemas.sh` s'est révélé capable
d'afficher un rapport rassurant sur **deux bases vides** :

```
MIGRATION INSTALLATION     FAIL
TABLE COUNT                MATCH (0)
COLUMN STRUCTURE           MATCH (0 colonnes)
INDEXES                    MATCH (0)
```

Quand la connexion échoue, rien n'est installé de part et d'autre — et deux
bases vides sont trivialement identiques. Le script refuse désormais de
conclure si aucune table n'a été installée. C'est le même motif que celui
traqué dans le code applicatif : *l'absence de vérification ne doit jamais se
présenter comme une vérification réussie.*

### État final de la CI

```
API PHP 8.1            success      484 tests sur MySQL 8
API PHP 8.3            success      484 tests sur MySQL 8
Cohérence du schéma    success      21 tables, 264 colonnes, 77 index, 24 FK
Frontend React         success      tsc + oxlint + build
Agents Node            success      tsc
```

La suite tourne désormais sur **deux versions de PHP et un moteur SQL
différent du poste de développement**. C'est précisément ce que la CI apporte
ici : elle a trouvé en un run une classe de bugs qu'aucune relecture n'aurait
mise en évidence.
