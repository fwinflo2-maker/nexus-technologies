# NEXUS — Guide de Développement

## Architecture du projet

```
NEXUS CORP TECHNOLOGIES/
├── nexus-api/              # Backend PHP 8 (API REST JSON)
├── nexus-frontend/         # Frontend React 19 (SPA TypeScript)
├── agents/                 # Service d'agents IA (Express/Node.js)
├── docs/                   # Documentation produit et technique
└── README.md               # Vue d'ensemble
```

## Ports

| Service    | Port  | URL                       |
|------------|-------|---------------------------|
| Frontend   | 5173  | http://localhost:5173      |
| API PHP    | 8080  | http://localhost:8080      |
| MySQL      | 3306  | localhost:3306             |
| Agents IA  | 3001  | http://localhost:3001      |

## Prérequis

- **Node.js** ≥ 18
- **XAMPP** (Apache + MySQL) ou **MariaDB / MySQL 8+**
- **PHP 8.1+** avec les extensions **obligatoires** suivantes :
  - `pdo_mysql` — accès base de données
  - `bcmath` — arithmétique décimale du ledger (hold/capture, FX)
  - `mbstring` — validation des chaînes (inscription, KYC)
  - `openssl` — chiffrement des données sensibles (IBAN, références bénéficiaires)

  Vérifier avec : `php -m | grep -E "pdo_mysql|bcmath|mbstring|openssl"`
  (Debian/Ubuntu : `sudo apt install php-cli php-mysql php-bcmath php-mbstring php-openssl`)

> ⚠️ Sans `bcmath` ou `mbstring`, l'API démarre mais `/register` renvoie une
> erreur interne silencieuse (`Call to undefined function`).

## Lancement

### 1. Base de données MySQL / MariaDB

Lancer **XAMPP Control Panel** → démarrer **MySQL** et **Apache**.

**Méthode canonique : le runner de migrations** (schéma + toutes les migrations
en ordre de version, idempotent — ré-exécutable sans effet) :

```bash
cd nexus-api
bash database/migrate.sh [hôte] [utilisateur] [motdepasse]
# défauts : 127.0.0.1 / nexus / nexus_dev_pw
```

Créer l'utilisateur applicatif au préalable (une seule fois) :

```sql
CREATE USER IF NOT EXISTS 'nexus'@'127.0.0.1' IDENTIFIED BY 'nexus_dev_pw';
GRANT ALL PRIVILEGES ON nexus.* TO 'nexus'@'127.0.0.1';
FLUSH PRIVILEGES;
```

> Les migrations couvrent : auth étendue, wallets à états, notifications,
> comptes de paiement, credentials providers, quotes, KYC/origines, ledger
> double-entrée, holds, exécution de transfert, suite Business (bénéficiaires,
> paiements, équipe, rapprochement).

### 2. Backend PHP (API)

```bash
cd nexus-api
php -S localhost:8080 -t public
```

L'API est accessible sur `http://localhost:8080/api/health`.

### 3. Frontend React

```bash
cd nexus-frontend
npm install
npm run dev
```

Le frontend est accessible sur `http://localhost:5173`.
Les appels `/api/*` sont automatiquement proxyfiés vers le backend PHP (port 8080).

### 4. Agents IA (optionnel)

```bash
cd agents
npm install
npm run build
npm start
```

## Configuration

### Backend (`nexus-api/.env`)

| Variable         | Valeur par défaut            | Description                    |
|------------------|------------------------------|--------------------------------|
| `APP_ENV`        | `development`                | Environnement (dev/prod)       |
| `APP_ORIGINS`    | `http://localhost:5173`      | Origines CORS autorisées       |
| `DB_HOST`        | `127.0.0.1`                  | Hôte MySQL                     |
| `DB_PORT`        | `3306`                       | Port MySQL                     |
| `DB_NAME`        | `nexus`                      | Nom de la base                 |
| `DB_USER`        | `root`                       | Utilisateur MySQL              |
| `DB_PASS`        | *(vide)*                     | Mot de passe MySQL             |
| `JWT_SECRET`     | `nexus-dev-secret-change-me` | Secret de signature JWT        |
| `JWT_TTL`        | `86400`                      | Durée de vie du token (sec)    |

> ⚠️ Ne jamais commiter le fichier `.env` en production.

### Frontend (`nexus-frontend/`)

Le proxy Vite est configuré dans `vite.config.ts` :

```typescript
proxy: {
  '/api': {
    target: 'http://localhost:8080',
    changeOrigin: true,
  },
}
```

## Structure du frontend

```
nexus-frontend/src/
├── api/                    # Client API centralisé (client.ts)
├── context/                # Contextes React (Auth, I18n)
├── components/             # Composants réutilisables
│   └── dashboard/          # Sidebar, DashTopbar, GearsBackground
├── views/
│   ├── public/             # Pages publiques (LandingPage)
│   ├── auth/               # Pages auth (Login, Register, ForgotPassword)
│   └── dashboard/          # Pages dashboard (Dashboard, Wallet, Routing, Placeholder)
├── data/                   # Données statiques (pays, langues, traductions)
├── styles/
│   ├── design-system.css   # Thème public (violet/glass)
│   └── dashboard-system.css # Thème dashboard (fond sombre, cyan, or)
├── App.tsx                 # Routeur principal
└── main.tsx                # Point d'entrée React
```

## Structure du backend

```
nexus-api/
├── public/
│   └── index.php           # Front controller (toutes les routes /api/*)
├── src/
│   ├── Auth/               # JWT, AuthMiddleware
│   ├── Controllers/        # AuthController, etc.
│   └── Core/               # Router, Request, Response, Database
├── config/                 # env.php, app.php, constants.php, database.php
├── database/
│   └── schema.sql          # Schéma MySQL principal
└── .env                    # Configuration (non versionnée)
```

## Routes API

| Méthode | Route                      | Auth    | Description                                       |
|---------|----------------------------|---------|---------------------------------------------------|
| GET     | `/api/health`              | Non     | Vérification DB + status                          |
| POST    | `/api/register`            | Non     | Inscription (email/mot de passe)                  |
| POST    | `/api/login`               | Non     | Connexion                                         |
| POST    | `/api/google`              | Non     | Auth Google OAuth                                 |
| POST    | `/api/logout`              | Oui     | Déconnexion (révocation token)                    |
| GET     | `/api/me`                  | Oui     | Profil utilisateur connecté                       |
| GET     | `/api/dashboard/summary`   | Oui     | Soldes, KPIs, transactions récentes, bannière     |
| GET     | `/api/dashboard/activity`  | Oui     | Série temporelle (7j / 30j / 12 mois)            |
| GET     | `/api/notifications`       | Oui     | Liste paginée (filtres: type, unread, page)       |
| GET     | `/api/notifications/unread-count` | Oui | Nombre de notifications non lues               |
| POST    | `/api/notifications/{id}/read` | Oui | Marque une notification comme lue                |
| POST    | `/api/notifications/read-all`  | Oui | Marque toutes les notifications comme lues       |
| GET     | `/api/wallets`             | Oui     | Soldes par devise (available/pending/in_transit/settlement), totaux EUR |
| GET     | `/api/wallets/rates`       | Oui     | Taux EUR de référence (MVP : 1 EUR = 655,957 XAF) |
| GET     | `/api/wallets/{currency}/transactions` | Oui | 10 dernières transactions de la devise (404 si inconnue) |

## Commandes utiles

```bash
# Vérifier la DB
mysql -u root nexus -e "SHOW TABLES;"

# Tester l'API
curl http://localhost:8080/api/health

# Build frontend pour production
cd nexus-frontend && npm run build

# Lint frontend
cd nexus-frontend && npm run lint
```

## Design rules

- **Pages publiques** : classes `design-system.css` (thème violet/glass)
- **Dashboard** : classes `dashboard-system.css` (fond sombre, cyan #00C8FF, or #EAB830)
- Ne jamais réinventer le style — toujours réutiliser les classes existantes
- Chaque écran gère : chargement, erreur, état vide

## ⚠️ SANDBOX vs REAL FUNDING (séparation explicite)

Les fonds visibles en **environnement de développement / sandbox** ne sont
**PAS** de vrais dépôts financiers :

| Élément de démo | Où | Ce que c'est |
|---|---|---|
| `welcome_bonus` (wallets EUR/USD/GBP/XAF/USDT/USDC à l'inscription) | `AuthController::register` | Bonus de bienvenue sandbox, crédité via le ledger (`LedgerService::credit`) |
| `seedDemoTransactions()` | `AuthController` | Historique de transactions fictives (alimente les KPI du dashboard) |
| `seedDemoAccountsAtLogin()` | `AccountController` | Comptes de financement de démo (Swan FR, wallet crypto…) marqués « vérifiés » pour permettre les parcours |
| `database/seed_dashboard.php` | script CLI | Seed optionnel pour les utilisateurs sans transactions |

- Ces seeds passent **par le ledger** (écritures réelles, traçables), mais leur
  **origine** est simulée : ils ne correspondent à aucun règlement bancaire.
- Un **vrai dépôt** devra passer par une intégration provider (carte / banque /
  virement) — **aucun faux endpoint de dépôt ne doit être créé** en attendant.
- En production, ces seeds doivent être **désactivés** (feature flag / env).

## Internationalisation

- **7 langues** : `fr`, `en`, `es`, `pt`, `de`, `ar`, `zh`.
- Pages publiques & auth : `data/translations.ts` (via `useI18n()`).
- Dashboards (navigation, statuts, KPI) : `data/dashboard-i18n.ts` (via
  `useDashT()` / `dashTranslate()`).
- Le sélecteur de langue est présent dans le topbar du dashboard
  (`LanguageSwitcher variant="dashboard"`).

## Providers — configuration (sans secrets dans le code)

Le Nexus Core est **provider-agnostic** : il ne connaît aucune clé et ne parle
jamais à un provider directement.

```text
Nexus Core → ProviderRegistry → ProviderAdapter → Credentials (env) → Provider API
```

### Où placer les credentials

Dans **l'environnement** (`.env` local, non versionné, ou secret manager).
**Jamais** dans le code, dans Git, ni en clair dans MySQL.

Convention (les slugs et les champs viennent de `ProviderCatalog`) :

```bash
PROVIDER_STRIPE_ENABLED=true
PROVIDER_STRIPE_ENV=sandbox
PROVIDER_STRIPE_SANDBOX_API_KEY=sk_test_...      # scope sandbox (prioritaire)
PROVIDER_STRIPE_PRODUCTION_API_KEY=sk_live_...  # scope production (prioritaire)
```

- `PROVIDER_{SLUG}_{ENV}_{CHAMP}` est lu **en priorité** ; sinon
  `PROVIDER_{SLUG}_{CHAMP}` (générique, appliqué à l'environnement actif).
- La séparation sandbox / production est **stricte** : une clé sandbox ne peut
  jamais être utilisée en production (et inversement).

### Activer / désactiver un provider

- `PROVIDER_{SLUG}_ENABLED=true|false`.
- Tant qu'**aucun** provider n'est activé/renseigné, Nexus reste en « mode démo » :
  tous les providers du catalogue sont éligibles au routing.
- Dès qu'**au moins un** provider est configuré, le **mode strict** s'active :
  seuls les providers configurés participent au routing (les autres sont ignorés
  sans casser le Core). Forcer : `PROVIDERS_STRICT_MODE=true`.

### Vérifier la configuration & le health check

```bash
# Statut de tous les providers (configuré / manquant / désactivé) — SANS secrets
curl -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/providers/status
```

Statuts possibles : `configured`, `missing_credentials`, `invalid_configuration`,
`disabled` (configuration) puis `healthy`, `degraded`, `unavailable` (santé).
`configured` ≠ `healthy` : la connectivité n'est testée que si
`PROVIDERS_CONNECTIVITY_CHECK=true` (sinon « connectivité non testée »).

### Changer sandbox → production

1. Renseigner les credentials `..._PRODUCTION_...` dans l'environnement ;
2. `PROVIDER_{SLUG}_ENV=production` ;
3. redémarrer l'API.

### Rotation d'une clé

Modifier la variable d'environnement correspondante et redémarrer — aucun
changement de code nécessaire (les adaptateurs relisent l'environnement).

### Webhooks

Architecture préparée (`Nexus\Providers\WebhookVerifier`) : vérification HMAC en
temps constant, formats `hex` ou `sha256=hex`. Aucun endpoint de webhook n'est
exposé tant qu'un provider n'est pas réellement intégré — ne jamais accepter
aveuglément un webhook externe.

### Tests

```bash
cd nexus-api && php vendor/bin/phpunit          # suite complète
php vendor/bin/phpunit --filter ProviderRegistryTest   # architecture providers
```



