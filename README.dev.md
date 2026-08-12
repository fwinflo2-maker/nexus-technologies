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
- **XAMPP** (Apache + MySQL) ou MySQL 8+
- **PHP 8.1+** (inclus dans XAMPP)

## Lancement

### 1. Base de données MySQL

Lancer **XAMPP Control Panel** → démarrer **MySQL** et **Apache**.

Créer la base `nexus` avec le schéma :

```bash
mysql -u root < nexus-api/database/schema.sql
```

Ou via phpMyAdmin : importer `nexus-api/database/schema.sql`.

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
