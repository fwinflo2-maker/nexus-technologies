# 🔑 Identifiants de Test - NEXUS

Ce fichier contient les identifiants pour accéder aux différentes interfaces de l'application.

## Comptes Disponibles

| Rôle | Email | Mot de passe | Interface |
| :--- | :--- | :--- | :--- |
| **Superadmin** | `admin@nexus.local` | `Admin@2024!Secure` | `/api/admin/`, `/api/control/` |
| **Client Personnel** | `client.perso@nexus.local` | `User@2024!Secure` | `/api/dashboard/` (Personal) |
| **Client Business** | `client.business@nexus.local` | `Business@2024!Secure` | `/api/dashboard/` (Business) |

## Accès par Type de Compte

### 1. Superadmin
- **Rôle** : `superadmin`
- **Accès complet** : Administration système, gestion des employés, supervision globale
- **Endpoints** : `/api/admin/*`, `/api/control/*`

### 2. Client Personnel
- **Rôle** : `user` (type: `personal`)
- **Accès** : Gestion de compte personnel, transactions, cartes
- **Endpoints** : `/api/dashboard/*`

### 3. Client Business
- **Rôle** : `user` (type: `business`)
- **Accès** : Gestion d'entreprise, multi-utilisateurs, facturation
- **Endpoints** : `/api/dashboard/*`

---
*Document généré pour environnement de test - NEXUS v1.0*
