# 🚀 Démarrage Rapide - NEXUS

## Prérequis

- PHP 8.1+
- MySQL 8.0+
- Composer

## Installation

### 1. Base de données

```bash
mysql -u root -p < database/nexus_complete.sql
```

### 2. Configuration

Copiez `.env.example` vers `.env` et ajustez les paramètres :

```env
DB_HOST=localhost
DB_DATABASE=nexus_db
DB_USERNAME=nexus_app
DB_PASSWORD=NexusApp@2024!Secure
```

### 3. Installation des dépendances

```bash
composer install
```

### 4. Lancer le serveur

```bash
php -S localhost:8000 -t public
```

## Connexion

Utilisez les identifiants dans `01-IDENTIFIANTS-TEST.md` pour vous connecter.

## Documentation Complète

- `01-IDENTIFIANTS-TEST.md` - Comptes de test
- `02-ARCHITECTURE.md` - Architecture technique
- `03-QUICKSTART.md` - Ce fichier

---
*NEXUS v1.0 - Prêt à l'emploi*
