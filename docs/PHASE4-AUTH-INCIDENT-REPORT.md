# NEXUS — AUTHENTICATION INCIDENT REPORT

> Rapport basé UNIQUEMENT sur des vérifications réellement exécutées dans le
> repository. Date : 2026-08-15. Environnement d'audit : PHP 8.4 / Composer
> 2.10 / MariaDB 11.8 (local 127.0.0.1:3306) / Node 20 / Vite 8.

## 1. Cause exacte du 502

**Démonstration reproductible dans le repository :**

Le frontend Vite (`nexus-frontend/vite.config.ts`) proxifie **toutes** les
requêtes `/api/*` vers `http://localhost:8080` (le backend PHP) :

```ts
proxy: {
  '/api': { target: 'http://localhost:8080', changeOrigin: true, secure: false },
}
```

- Backend PHP actif (port 8080) → le proxy renvoie **200** pour `/api/login`
  et `/api/me`. → vérifié via `curl` sur le port 5173.
- Backend PHP **coupé/injoignable sur 8080** → Vite ne peut pas joindre
  l'upstream et renvoie **`502 Bad Gateway`** au navigateur pour `/api/login`
  et `/api/me`. → reproduit réellement (HTTP 502 sur `/api/login` et
  `/api/me` via le port 5173).

**Conclusion (cause exacte) :** le 502 n'est PAS un bug de code du backend.
Le code backend est sain et complet. Le 502 est un **problème
d'infrastructure/de lancement** : le processus PHP (et/ou MySQL) qui doit
écouter sur `localhost:8080` n'était pas démarré / joignable au moment du
test. Sur un hébergement type Hostinger, ce schéma « Vite → proxy →
localhost:8080 » ne peut d'ailleurs pas fonctionner tel quel (le frontend
statique ne tourne pas Vite et ne peut pas proxifier vers un `localhost`
interne) — voir §8.

## 2. Backend (testé en réel, port 8080)

| Endpoint | Résultat |
|---|---|
| `GET /api/health` | **PASS** — 200, `db: connected` |
| `POST /api/login` | **PASS** — 200 + JWT |
| `POST /api/register` | **PASS** — 201, `auth_provider: local` |
| `GET /api/me` | **PASS** — 200 (avec Bearer token valide) |
| `POST /api/google` | **404** — route supprimée |

Cas d'erreur vérifiés : mauvais mot de passe → **401** ; email inconnu →
**401** ; token absent → **401** ; token invalide → **401**.

## 3. Cause Google Auth

- **Références trouvées (avant correction)** :
  - Backend : route `POST /api/google` (`public/index.php`), méthodes
    `AuthController::google()` + `AuthController::verifyGoogleToken()`,
    constante `GOOGLE_CLIENT_ID` (`config/constants.php`), `GOOGLE_CLIENT_ID`
    dans `.env.example`.
  - Frontend : `src/components/GoogleButton.tsx` (chargement du script GIS
    Google au runtime), utilisé dans `LoginPage.tsx` et `RegisterPage.tsx` ;
    `apiGoogleAuth()` dans `src/api/client.ts` ; `loginWithGoogle` dans
    `AuthContext.tsx` ; clés i18n `auth_google_btn/separator/err` ;
    CSS `.google-btn-*` dans `AuthPages.css` ; bloc « Authentification
    Google » dans `SettingsPage.tsx`.
- **Dépendances** : **aucune** dépendance npm/Composer Google (le script GIS
  est chargé au runtime, pas via package manager). Aucune à supprimer de
  `package.json` / `composer.json`.
- **Le message « La connexion Google a échoué »** venait du composant
  `GoogleButton.tsx` qui, sans `VITE_GOOGLE_CLIENT_ID` configuré, appelait
  `onError` avec ce message. Le bouton n'était donc pas fonctionnel sans
  configuration — d'où le symptôme observé.

## 4. Corrections réellement effectuées

**Backend (`nexus-api/`)**
- `public/index.php` : retrait de la route `POST /api/google`.
- `src/Controllers/AuthController.php` : suppression de `google()` et de
  `verifyGoogleToken()` ; mise à jour du docblock de classe.
- `config/constants.php` : suppression du bloc `GOOGLE_CLIENT_ID`.
- `.env.example` : suppression de `GOOGLE_CLIENT_ID`.
- `src/Controllers/NotificationController.php` : commentaire nettoyé.

**Frontend (`nexus-frontend/`)**
- `src/api/client.ts` : suppression de `apiGoogleAuth` ; type `auth_provider`
  ramené à `'local'` (2 occurrences).
- `src/context/AuthContext.tsx` : suppression de `loginWithGoogle`.
- `src/views/auth/LoginPage.tsx` et `RegisterPage.tsx` : suppression du bouton
  Google, du handler et du séparateur.
- `src/data/translations.ts` : suppression des clés `auth_google_*` (fr + en).
- `src/components/GoogleButton.tsx` : **fichier supprimé**.
- `src/views/auth/AuthPages.css` : suppression du CSS `.google-*`.
- `src/views/dashboard/SettingsPage.tsx` : remplacement du bloc « Authentification
  Google » par un affichage local.
- `README.dev.md` : retrait de la ligne de route `/api/google`.

**Aucune modification de schéma SQL ni migration** n'a été nécessaire (aucune
table Google n'est référencée par du code actif — `oauth_identities` était déjà
mort, voir rapport BOUCLE A).

## 5. Database

- **DB accessible** : OUI — `GET /api/health` → `db: connected` (MariaDB,
  base `nexus`).
- **Tables vérifiées** : `users`, `wallets`, `revoked_tokens`,
  `idempotency_keys`, `ledger_entries`, `transactions`, `login_attempts`,
  `notifications`, etc. (21 tables installées).
- **SQL modifié** : **NON** (aucun changement de schéma nécessaire).
- **Migration créée** : **NON** (rien à migrer).
- **SQL exécuté** : OUI — installation du schéma + migrations sur la base de
  test `nexus_test` et de dev `nexus` (`scripts/setup_test_db.php`).
- **Résultat** : contrat SQL↔PHP **PASS** (aucune incohérence).

## 6. Tests (réellement exécutés)

| Type | Résultat |
|---|---|
| PHP lint (`composer run-script lint`) | **PASS** — 0 erreur de syntaxe |
| Suite PHPUnit (backup) | **OK (550 tests, 2311 assertions)** — 0 failure, 0 erreur |
| Contrat SQL↔PHP | **PASS** |
| TypeScript (`tsc -b`) | **PASS** |
| Lint frontend (`oxlint`) | 0 erreur / 5 warnings (non bloquants) |
| Build frontend (`npm run build`) | **PASS** |
| API réelles (/health /register /login /me /wallets /notifications) | **PASS** |
| Auth : mauvais mdp, email inconnu, token absent, token invalide | **tous 401** |
| Endpoint Google | **404** (désactivé) |

## 7. Git

- **branch** : `main`
- **commit** : à créer
- **push** : ⚠️ bloqué — le fine-grained PAT fourni n'a pas la permission
  d'écriture (`Contents: Read and write`) sur le dépôt. `git push` → 403
  « Permission to fwinflo2-maker/nexus-technologies denied ». À débloquer
  côté utilisateur (voir rapport BOUCLE A).
- **working tree** : contient les modifications (non commitées au moment de ce
  rapport si le push n'a pas abouti).

## 8. État final — authentification email/password

**Démontrée fonctionnelle de bout en bout** sur le repository réel :
REGISTER (201) → LOGIN (200 + JWT) → ME (200) → WALLETS/NOTIFICATIONS (200),
avec 401 corrects sur tous les cas d'erreur. Google Auth est **totalement
désactivée** (aucun bouton, route, handler, dépendance ou message Google).

**Recommandation infrastructure (pour lever le 502 en conditions réelles) :**
le 502 est un problème de mise en service du backend. Deux solutions selon le
déploiement :
1. **Local (XAMPP)** : démarrer Apache/MySQL puis `php -S localhost:8080 -t
   public` (ou configurer Apache pour servir `nexus-api/public`) **avant** de
   lancer `npm run dev`. Sans backend sur 8080, Vite renvoie 502.
2. **Hébergement (ex. Hostinger)** : servir le build statique du frontend
   (`npm run build` → `dist/`) et exposer l'API PHP via un vhost Apache/PHP-FPM
   sur une URL dédiée ; mettre à jour l'URL de l'API dans le client (au lieu du
   proxy Vite `localhost:8080`), car un hébergement statique ne peut pas
   proxifier vers un `localhost` interne.
