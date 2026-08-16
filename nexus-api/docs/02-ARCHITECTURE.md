# 🏗 Architecture Technique - NEXUS

## Vue d'ensemble

NEXUS est une plateforme financière modulaire avec une architecture API-first.

## Structure des Interfaces

### 3 Types d'Interfaces Principales

1. **Dashboard Client** (`/api/dashboard/`)
   - Accessible par : Clients Personnel et Business
   - Fonctionnalités : Comptes, cartes, transactions, virements

2. **Control Center** (`/api/control/`)
   - Accessible par : Superadmin uniquement
   - Fonctionnalités : Gestion employés, supervision, configuration

3. **Admin Panel** (`/api/admin/`)
   - Accessible par : Superadmin uniquement
   - Fonctionnalités : Administration système, audits, logs

## Base de Données

- **Fichier unique** : `database/nexus_complete.sql`
- **Utilisateur DB** : `nexus_app`
- **Tables principales** : users, accounts, cards, transactions, employees, roles

## Sécurité

- Authentification JWT
- Hachage bcrypt pour les mots de passe
- Rôles et permissions granulaires
- Validation stricte des entrées

---
*NEXUS v1.0 - Architecture simplifiée*
