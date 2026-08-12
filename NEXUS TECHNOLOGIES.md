# NEXUS — Prompts étape par étape (Vite + React + PHP/XAMPP)

**Projet** : NEXUS — Financial Orchestration Platform (Spec v5.3 + Document Technique DT01)
**Frontend** : `nexus-frontend/` — React 19 + TypeScript + Vite 8 + React Router 7 (SPA sur port 5173)
**Backend** : PHP 8 + MySQL via **XAMPP** (Apache + MySQL) — API REST JSON dans `nexus-api/`
**Déjà réalisé** : Landing, Login/Register, Dashboard, Wallet, Routing (ébauches) + design system double (public violet/glass, dashboard cyan/gold/green)
**À réaliser** : connecter le backend PHP, finir le dashboard, /send (avec Routing Engine intégré — cf. MODIFICATION-ENVOYER-ROUTING.md), /history, KYC/KYB, Business, Pro, back-office, sécurité, nettoyage, déploiement.

---

## 1. Consignes globales (à coller en tête de CHAQUE session)

```text
[CONTEXTE GLOBAL — NEXUS]
Tu construis NEXUS, une plateforme d'orchestration financière intelligente
("Intelligent. Multi-Rails. One Platform."). Références : NEXUS TECHNOLOGIES.md
(spec produit v5.3) et Document Technique.md (DT01). L'utilisateur choisit ce
qu'il veut faire, Nexus détermine les meilleures façons de le faire,
l'utilisateur choisit une route, Nexus exécute et réconcilie.

STACK RÉELLE DU PROJET :
- Frontend : nexus-frontend/ — React 19, TypeScript, Vite 8, React Router 7.
  Pages publiques : / (LandingPage), /login, /register.
  Pages dashboard (protégées, shell Sidebar + Topbar + Gears) : /dashboard,
  /wallet, /send (workflow complet incluant le Routing Engine en interne),
  /history, /nexus-pro, /treasury, /payments, /approvals, /team, /reporting,
  /kyc, /providers, /agents. La page /routing indépendante est SUPPRIMÉE de
  la navigation : son contenu est intégré au workflow /send. Un back-office
  admin (config routing) sera ajouté (étape 6.3).
- Backend : nexus-api/ — PHP 8 + MySQL (XAMPP). API REST JSON, front
  controller public/index.php, .htaccess, PDO, JWT (HMAC). Toutes les routes
  sous /api/*. En dev, Vite proxy /api → http://localhost:8080.
- Auth : JWT signé HMAC-SHA256 (classe maison, sans dépendance), mot de passe
  hashé avec password_hash/password_verify, Authorization: Bearer <token>.
- Aucun secret côté client ; les tokens et secrets PHP restent côté serveur
  (config/, hors du frontend).

RÈGLE DE DESIGN N°1 (CRITIQUE) :
Le design DÉJÀ UTILISÉ dans les pages existantes fait FOI. Ne réinvente
JAMAIS le style, n'introduis aucune nouvelle couleur ni nouveau composant.
- Pages publiques (landing, login, register) : réutilise exclusivement les
  classes de styles/design-system.css (thème violet/glass, composants actuels).
- Dashboard et pages connectées : réutilise exclusivement les classes de
  styles/dashboard-system.css, scoped sous .nexus-dash (fond sombre, cyan
  #00C8FF, or #EAB830, vert #00CFA0, bordures #1A2838, JetBrains Mono pour
  les montants, Sidebar/Topbar/GearsBackground existants).
- Toute nouvelle page utilise les composants et classes DÉJÀ présents
  (StatCard, badges, cards, tables…) et suit le même esprit que
  DashboardPage/WalletPage.

RÈGLE D'ARCHITECTURE N°1 (UNIFICATION « ENVOYER » + « ROUTING ENGINE ») —
cf. MODIFICATION-ENVOYER-ROUTING.md :
- ENVOYER = interface utilisateur. ROUTING ENGINE = intelligence interne
  d'ENVOYER. Le Routing Engine n'est JAMAIS exposé comme fonctionnalité
  utilisateur indépendante : aucune entrée « Routing Engine » dans le menu
  « Compte personnel » (menu Personal = Wallet, Envoyer, Historique,
  Nexus Pro uniquement).
- Le workflow /send déclenche automatiquement le Routing Engine en interne :
  collecte intention → corridor → devises → moyen de réception → recherche
  providers → Routing Engine → comparaison des routes → meilleure route →
  frais/taux/montant reçu → offre → confirmation → exécution.
- Le Routing Engine reste un composant métier central DÉCOUPLÉ du composant
  UI (service backend dédié). Il est RÉUTILISÉ tel quel, jamais recréé :
  conserver ses règles, providers, critères de sélection, scoring, fallbacks
  et paramètres existants.
- Interface d'administration (back-office/admin) uniquement : configuration
  providers, corridors, priorités, règles, fallbacks, supervision, perfs.
  Jamais exposée à l'utilisateur.

RÈGLES DE QUALITÉ :
- Réutilise les composants existants avant d'en créer de nouveaux.
- Chaque écran gère : chargement (skeleton/spinner), erreur, état vide.
- Formulaire = validation + message d'erreur + état de soumission.
- Tous les appels au backend passent par un service API centralisé
  (src/api/client.ts) qui gère le token et les erreurs 401.
- Code et commentaires en français. Réponds en français.
```

---

## 2. Récapitulatif des étapes

| # | Étape | Livrable principal | Dépend de |
|---|-------|--------------------|-----------|
| 0.0 | Setup XAMPP + base MySQL | nexus-api opérationnel, base `nexus` créée | — |
| 0.1 | Backend PHP : noyau + auth | Routeur, PDO, JWT, /api/auth/* | 0.0 |
| 0.2 | Schéma complet + seeds | tables + providers seedés (pawaPay…) | 0.0 |
| 0.3 | Brancher le frontend | AuthContext réel, Vite proxy, ProtectedRoute | 0.1 |
| 0.4 | Nettoyage du repo | supprimer nexus-dashboard, Layout inutile | 0.3 |
| 1.1 | Dashboard complet | KPIs + soldes réels + activité + actions rapides | 0.3 |
| 1.2 | Notifications | table + badge topbar + page /notifications | 1.1 |
| 2.1 | Wallet branché API | WalletPage avec soldes réels | 0.3 |
| 2.2 | Sources & Destinations | CRUD payment_accounts | 2.1 |
| 3.1 | /send — Intent Engine | collecte de l'intention (formulaire guidé) | 2.x |
| 3.2 | /send — intégration du Routing Engine | routes A/B/C affichées DANS /send (plus de page /routing indépendante) + suppression entrée nav | 3.1 |
| 3.3 | Exécution + machine à états | POST /api/transactions + suivi temps réel | 3.2 |
| 3.4 | /history + détail timeline | liste filtrée + page détail | 3.3 |
| 4.1 | Statuts & gating | VERIFIED/PENDING/LIMITED/BLOCKED | 0.3 |
| 4.2 | KYC personnel | parcours (docs en upload PHP/Storage) | 4.1 |
| 4.3 | KYB entreprise | onboarding société + UBO | 4.1 |
| 5.1 | /treasury | workspace Business + exposition | 4.3 |
| 5.2 | /team & RBAC | 6 rôles + invitations | 5.1 |
| 5.3 | /approvals | workflow approbation | 5.2 |
| 5.4 | /payments + /reporting | Mass Payments + rapports | 5.3 |
| 6.1 | /nexus-pro | GPM, spreads, alertes | 3.x |
| 6.2 | Agents IA en PHP | Orchestrator/Compliance/Routing/Execution + /agents | 6.1 |
| 6.3 | Back-office Routing Engine | admin : providers, corridors, priorités, règles, fallbacks, supervision, perfs (jamais exposé à l'utilisateur) | 3.2 |
| 7.1 | Sécurité | MFA, sessions, audit logs, rate limiting | 0.3 |
| 7.2 | /settings | profil, sécurité, préférences, abonnement | 7.1 |
| 8.1 | Polissage | responsive, a11y, états UI | tout |
| 8.2 | Tests | Vitest (front) + PHPUnit (API) | 8.1 |
| 8.3 | Déploiement | PHP+MySQL en prod, .env, checklist | 8.2 |
| 9.x | Horizons | IBAN virtuels, crypto, cartes, Nexus Connect | roadmap P2–P8 |

---

## 3. Mise en place XAMPP (à faire avant l'étape 0.1)

1. **Démarrer XAMPP Control Panel** : boutons **Start** sur **Apache** et **MySQL**.
2. **Créer la base de données** : ouvrir `http://localhost/phpmyadmin` → « Nouvelle » → nom `nexus` → « Créer » (utf8mb4_general_ci).
3. **Dossier du backend** : placer le code dans `C:\xampp\htdocs\nexus-api\` (ou lancer `php -S 127.0.0.1:8080 -t nexus-api/public` avec le PHP de XAMPP : `C:\xampp\php\php.exe`).
4. **Configurer la connexion** dans `nexus-api/config/database.php` : `host=127.0.0.1`, `port=3306`, `dbname=nexus`, `user=root`, `pass=` (vide par défaut XAMPP — créer ensuite un utilisateur dédié).
5. **Vite proxy** : dans `nexus-frontend/vite.config.ts`, ajouter :

```ts
server: {
  host: '0.0.0.0',
  port: 5173,
  proxy: {
    '/api': {
      target: 'http://localhost:8080',
      changeOrigin: true,
    },
  },
},
```

Ainsi, dans le frontend, `fetch('/api/login')` est redirigé vers PHP sans problème de CORS en dev.

---

## 4. Les prompts étape par étape

---

### Étape 0 — Fondations

#### Étape 0.0 — Setup XAMPP, base MySQL et health check

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 0.0 — Setup du backend PHP (XAMPP)

Crée dans nexus-api/ une base d'API REST en PHP 8 structurée ainsi :
public/index.php (front controller), public/.htaccess, config/database.php,
src/Core/Database.php (PDO singleton), src/Core/Router.php,
src/Core/Request.php, src/Core/Response.php.

1. public/index.php : routeur simple (méthode + pattern de chemin, pas de
   framework) qui charge config/, enregistre les routes puis exécute.
   public/.htaccess : RewriteEngine On + RewriteRule ^(.*)$ index.php [QSA,L]
   (Apache XAMPP). Toutes les routes sont préfixées /api.
2. config/database.php : constantes DB_HOST=127.0.0.1, DB_PORT=3306,
   DB_NAME=nexus, DB_USER=root, DB_PASS= (lisible depuis .env si présent).
3. Réponses JSON uniformes : { success, data?, error?, code? } ;
   en-têtes Content-Type: application/json; charset=utf-8. Gestion d'erreurs :
   400 (validation), 401 (non authentifié), 403 (interdit), 404, 409
   (conflit), 429 (trop de requêtes), 500 (erreur interne, message générique).
4. Route GET /api/health → { success: true, status: "ok", db: "connected",
   timestamp } qui teste la connexion PDO.

CRITÈRES D'ACCEPTATION :
- Avec XAMPP démarré, GET http://localhost:8080/api/health répond
  { success: true, status: "ok", db: "connected" }.
- Une route inexistante renvoie 404 JSON propre.
```

---

#### Étape 0.1 — Auth : JWT, inscription, connexion, profil

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 0.1 — Authentification PHP (JWT)

1. src/Auth/Jwt.php : classe maison de JWT (HMAC-SHA256, base64url,
   header { alg: HS256, typ: JWT }, payload { sub: user_id, iat, exp },
   secret dans config/constants.php). Fonctions encode() / decode() /
   verify() ; expiration 24 h.
2. src/Auth/AuthMiddleware.php : lit Authorization: Bearer <token>,
   décode le JWT, charge l'utilisateur, l'attache à la requête ;
   sinon 401.
3. Contrôleur AuthController :
   - POST /api/register : full_name, email, password, account_type
     (personal|business). Vérifie email unique, password ≥ 8 avec
     password_hash(). Crée l'utilisateur (status PENDING, kyc_level none),
     les wallets de bienvenue (EUR 2500.00, XAF 1500000.00 — données de
     démo), une notification de bienvenue. Retourne { token, user }.
   - POST /api/login : vérifie email + password_verify(), retourne
     { token, user } ; log d'audit (audit_logs) ; limite 5 essais / 5 min.
   - POST /api/logout : (optionnel) révoque le token côté serveur.
   - GET /api/me (protégé) : retourne le profil complet + kyc_level + status.
4. Renvoyer user SANS champs sensibles (pas de password_hash).

CRITÈRES D'ACCEPTATION :
- Inscription → login → GET /api/me avec le même token fonctionne.
- Mauvais mot de passe → 401 ; email dupliqué → 409.
- Le token expiré ou falsifié renvoie 401.
```

---

#### Étape 0.2 — Schéma MySQL complet + seeds providers

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 0.2 — Schéma MySQL et données de départ

Crée nexus-api/migrations/schema.sql exécutable dans phpMyAdmin (base
`nexus`), avec :

1. users : id INT UNSIGNED AUTO_INCREMENT PK, email VARCHAR(255) UNIQUE,
   password_hash VARCHAR(255), full_name VARCHAR(120), account_type
   ENUM('personal','business'), status ENUM('VERIFIED','PENDING','LIMITED',
   'BLOCKED') DEFAULT 'PENDING', kyc_level ENUM('none','personal_pending',
   'personal_verified','business_pending','business_verified') DEFAULT
   'none', ref_currency CHAR(3) DEFAULT 'EUR', lang VARCHAR(5) DEFAULT 'fr',
   created_at, updated_at.
2. wallets : id, user_id FK, currency CHAR(3), available DECIMAL(18,2),
   pending DECIMAL(18,2), in_transit DECIMAL(18,2), settlement
   DECIMAL(18,2), is_primary BOOL, UNIQUE(user_id, currency).
3. providers : id, name, capabilities JSON, countries JSON, currencies
   JSON, rails JSON, fees JSON, sla JSON, status, performance_score
   TINYINT, api_version VARCHAR(20), webhooks_supported BOOL. Seed :
   Swan, Modulr, Stripe, pawaPay (Pilote Congo), Onafriq, Thunes, NOAH,
   Currencycloud, Wise Platform, Bridge, BVNK, Yellow Card, CashRamp,
   Stripe Issuing, Nium, Marqeta, dLocal, EBANX, Xendit.
4. payment_accounts : id, user_id FK, kind ENUM('bank_iban',
   'mobile_money','crypto_wallet','card','virtual_iban'), direction
   ENUM('source','destination','both'), label, country, currency,
   details JSON (numéro, titulaire, réseau, IBAN…), is_default BOOL.
5. transactions : id, user_id FK, wallet_id FK, public_id VARCHAR(32)
   UNIQUE (ex. NX-XXXX-XXXX), idempotency_key VARCHAR(64) UNIQUE, type
   ENUM('send','receive','topup'), amount DECIMAL(18,2), currency CHAR(3),
   fees JSON, fx_rate DECIMAL(12,6), provider_id FK, route JSON, status
   ENUM('CREATED','QUOTED','AUTHORIZED','PROCESSING','PENDING','COMPLETED',
   'SETTLED','RECONCILED','FAILED','TIMEOUT','UNKNOWN','CANCELLED',
   'EXPIRED','REVERSED','REFUNDED'), reconciliation_state VARCHAR(40),
   created_at, updated_at, expires_at.
6. transaction_events : id, transaction_id FK, from_status, to_status,
   note, created_at (audit du cycle de vie).
7. notifications : id, user_id FK, type ENUM('transfert','quote','kyc',
   'securite','business','systeme'), title, body, read BOOL DEFAULT 0,
   created_at.
8. teams / team_members : teams(id, name, owner_id FK) ;
   team_members(id, team_id FK, user_id FK, role ENUM('owner',
   'administrator','finance_manager','accountant','operator','viewer'),
   UNIQUE(team_id, user_id)).
9. approval_requests : id, team_id FK, transaction_id FK, initiator_id FK,
   status ENUM('pending','approved','rejected','cancelled'), approver_id FK
   NULL, approved_at, note.
10. kyc_applications : id, user_id FK, kind ENUM('kyc','kyb'), status,
    provider VARCHAR(40) DEFAULT 'sumsub-simule', payload JSON,
    created_at, updated_at.
11. alerts : id, user_id FK, type ENUM('spread','price','liquidity',
    'route','market'), config JSON, triggered BOOL DEFAULT 0, created_at.
12. audit_logs : id, user_id FK, action, entity, entity_id, metadata JSON,
    ip VARCHAR(45), user_agent VARCHAR(255), created_at.
13. sessions : id, user_id FK, token_hash VARCHAR(64), device, ip,
    last_seen, revoked BOOL DEFAULT 0, created_at.

Toutes les tables ont des index sur les FK et les colonnes de recherche
(email, status, user_id, transaction_id). Moteur InnoDB, utf8mb4.

CRITÈRES D'ACCEPTATION :
- Le script s'exécute sans erreur dans phpMyAdmin et crée les 13 tables.
- La table providers contient les 19 providers seedés.
```

---

#### Étape 0.3 — Brancher le frontend sur le backend PHP

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 0.3 — Connexion frontend ↔ backend PHP

1. Crée src/api/client.ts : wrapper fetch qui ajoute automatiquement
   Authorization: Bearer <token> (depuis un stockage sécurisé —
   sessionStorage acceptable en démo, localStorage sinon), gère les
   erreurs (401 → déconnexion + redirection /login ; message d'erreur
   lisible depuis { error }), et exporte des fonctions typées par
   domaine : authApi (register, login, logout, me), walletApi, txApi…
2. Refactor context/AuthContext.tsx : remplacer le mock localStorage par
   de vrais appels à /api/register, /api/login, /api/me, /api/logout.
   Garder exactement la même interface (login, register, logout, user)
   pour ne rien casser dans les pages existantes.
3. Composants : LoginPage et RegisterPage → brancher sur les nouveaux
   appels (états loading/erreur, redirection vers /dashboard).
4. Activer components/ProtectedRoute.tsx (existe mais inutilisé) :
   redirige vers /login si non authentifié, affiche un loader pendant
   le refresh du profil.
5. Vite proxy /api → http://localhost:8080 (section 3).

CRÈTES D'ACCEPTATION :
- Inscription réelle → création en base MySQL → redirection dashboard.
- Recharger la page garde la session (token + /api/me).
- Déconnexion → retour /login ; /dashboard inaccessible sans token.
- Aucun changement visuel des pages existantes (design intact).
```

---

#### Étape 0.4 — Nettoyage du projet

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 0.4 — Nettoyage du dépôt

1. Supprime le dossier nexus-dashboard/ (obsolète, remplacé par
   nexus-frontend) après vérification qu'aucun import n'y fait référence.
2. Supprime components/Layout.tsx et son CSS (inutilisés après
   l'unification) et tout autre fichier mort (vérifie avec grep).
3. Déplace les images IA de la racine vers nexus-frontend/public/assets/
   et mets à jour les imports.
4. Place les 2 docs (NEXUS TECHNOLOGIES.md, Document Technique.md) et les
   rendus HTML dans un dossier docs/.
5. Ajoute un README.dev.md : commandes de lancement (XAMPP, API PHP,
   npm run dev), config .env, structure du projet, ports (5173 front,
   8080 API, 3306 MySQL).
6. Ajoute .gitignore propre (node_modules, dist, .env, uploads/…).

CRITÈRES D'ACCEPTATION :
- `npm run build` passe sans erreur après nettoyage.
- La structure du dépôt est claire et documentée.
```

---

### Étape 1 — Dashboard

#### Étape 1.1 — Dashboard complet branché sur l'API

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 1.1 — Dashboard : vue d'ensemble complète

Complète DashboardPage en gardant exactement le design actuel
(.nexus-dash) et en branchant les données réelles sur l'API PHP
(GET /api/dashboard ou agrégats dédiés) :

1. Section soldes : carte principale solde total (converti en
   ref_currency, EUR par défaut) + grille des wallets multi-devises
   (EUR/USD/GBP/XAF/USDT/USDC) avec available / pending / in_transit /
   settlement, drapeaux et code devise. Boutons « Recharger » et
   « Envoyer ».
2. Ligne de KPIs (StatCard existantes) : transactions ce mois, volume
   total (XAF équivalent), taux de réussite, temps moyen d'exécution,
   frais totaux — calculés côté PHP (endpoint dédié qui agrège la table
   transactions).
3. Graphique d'activité 30 jours : volume + nombre de transactions
   (barres/courbe, couleurs cyan/vert), sélecteur de période. Données
   depuis l'API (label, série).
4. « Activité récente » : 6 dernières transactions (montant, devise,
   statut avec badge coloré, date relative).
5. « Actions rapides » : Envoyer, Recharger, Recevoir, Vérifier mon
   identité (si PENDING), Payer des fournisseurs (si Business).
6. Bannière intelligente selon le statut : PENDING → inviter au KYC ;
   LIMITED → expliquer les restrictions ; wallets vides → suggérer le
   corridor EUR → XAF (MVP).
7. States : skeleton pendant le chargement, EmptyState si aucune donnée,
   erreur avec bouton « Réessayer ».

CRITÈRES D'ACCEPTATION :
- Les soldes/KPIs proviennent de MySQL, pas de valeurs en dur.
- Le design reste strictement celui de DashboardPage actuelle.
- Endpoints PHP dédiés créés (dashboard/summary, dashboard/activity).
```

---

#### Étape 1.2 — Notifications

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 1.2 — Centre de notifications

1. Backend PHP : GET /api/notifications (filtre type, page, non-lues),
   GET /api/notifications/unread-count, POST /api/notifications/:id/read,
   POST /api/notifications/read-all.
2. Topbar existante : cloche avec badge rouge du nombre de non-lues
   (fetch à intervalle + après chaque action), panneau des 5 dernières
   + lien « Tout voir ».
3. Nouvelle page /notifications : liste groupée par date, filtre par
   type (transfert, quote, kyc, securite, business, systeme), actions
   « Marquer comme lue » / « Tout marquer comme lu ».
4. Au premier login, insère 2–3 notifications de démo (KYC en attente,
   bienvenue, quote expirée).
5. Style : réutilise les badges et cards .nexus-dash existants.

CRITÈRES D'ACCEPTATION :
- Le compteur se met à jour sans rechargement complet.
- Icône et couleur par type de notification.
```

---

### Étape 2 — Wallet

#### Étape 2.1 — Wallet branché sur l'API

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 2.1 — Page Wallet (vue unifiée, données réelles)

1. Backend PHP : GET /api/wallets (soldes de l'utilisateur),
   GET /api/wallets/:currency/transactions (10 dernières), et un
   endpoint de conversion taux EUR de référence (taux fixe MVP :
   1 EUR = 655.957 XAF).
2. Refactor WalletPage (conserve le design actuel) :
   - En-tête : solde total (EUR de référence) + boutons « Recharger »
     et « Envoyer ».
   - Liste des wallets par devise avec available / pending /
     in_transit / settlement (visuellement distincts).
   - Onglets « Mes devises » / « Sources de financement » /
     « Destinations » (voir 2.2).
   - Bouton « + Ajouter une devise » (EUR, USD, GBP, XAF, USDT, USDC ;
     autres grisées « bientôt »).
   - Historique rapide du wallet sélectionné.
3. Formatage : montants en JetBrains Mono alignés à droite, format fr
   (EUR : 2 500,00 ; XAF : 1 500 000).

CRITÈRES D'ACCEPTATION :
- Sélectionner une devise met à jour l'aperçu et l'historique.
- Les soldes viennent de MySQL (table wallets).
```

---

#### Étape 2.2 — Sources de financement & destinations

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 2.2 — Sources & Destinations (CRUD)

1. Backend PHP : GET/POST/PUT/DELETE /api/accounts (table
   payment_accounts), POST /api/accounts/:id/default.
2. Frontend (nouvelle page ou sections dans /wallet) :
   - Sources : 💶 Compte bancaire IBAN, 📱 Mobile Money, 🔵 Crypto
     Wallet, 💳 Carte, 🏢 Compte virtuel IBAN. CRUD + « par défaut ».
     IBAN partiellement masqué à l'affichage.
   - Destinations : 🏦 Banque (IBAN local/international), 📱 Mobile
     Money (Airtel Money, MTN, Moov — liste selon pays depuis
     data/countries.ts), 🔵 Adresse blockchain (réseau + adresse),
     Cash pickup.
   - Formulaires : Mobile Money = pays + opérateur + numéro + titulaire ;
     Banque = IBAN + BIC + titulaire + pays ; Crypto = réseau + adresse.
   - Validation des formats (IBAN, numéro mobile, adresse).
3. La source/destination par défaut pré-remplit le formulaire /send.

CRITÈRES D'ACCEPTATION :
- CRUD complet fonctionnel contre MySQL, RLS simulée (scoping par user_id).
- Les données sensibles sont masquées à l'affichage.
```

---

### Étape 3 — Le cœur du produit : /send = UI, Routing Engine = intelligence (MVP EUR → XAF)

La « boucle fondamentale » de la spec : Intention → Capability → Quote → Routing → Policy/Risk → Présentation → Sélection → Execution → Settlement & Réconciliation. **Tout se passe à l'intérieur du workflow /send — aucune page « Routing Engine » séparée côté utilisateur** (cf. règle d'architecture n°1).

#### Étape 3.1 — /send : collecte de l'intention (Intent Engine)

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 3.1 — Formulaire guidé de transfert (/send)

Remplace le placeholder /send par un formulaire multi-étapes fidèle à la
spec (Intent Engine), en utilisant le design .nexus-dash existant :

1. Étape 1 « Que voulez-vous faire ? » : Envoyer / Recharger / Recevoir.
2. Étape 2 « Détails » : montant (devise d'envoi parmi les wallets),
   pays/ devise de destination (Congo, XAF par défaut), mode de
   réception (Mobile Money : Airtel Money/MTN/Moov ; Banque IBAN ;
   Crypto ; Cash pickup), référence bénéficiaire + nom, option
   « Objectif » : ⭐ Optimisé / 💰 Montant max reçu / ⚡ Plus rapide /
   💸 Moins cher.
3. Étape 3 « Résumé » : envoyé, frais estimés, reçu estimé, bouton
   « Voir les routes ».
4. Conversion en direct (1 EUR = 655.957 XAF) avec mention « estimation,
   frais et spread inclus dans les routes ».
5. Pré-remplissage depuis les sources/destinations par défaut.
6. L'intention est stockée structurée (objet intent) et RESTE dans le
   workflow /send : le Routing Engine est déclenché automatiquement à
   l'étape suivante (étape 3.2) — l'utilisateur ne navigue jamais vers
   une page « Routing Engine ».
7. Workflow d'ensemble de /send (à afficher en stepper) : Intention →
   Corridor & devises → Moyen de réception → Providers → [Routing Engine]
   → Comparaison des routes → Meilleure route → Frais/taux/montant reçu →
   Offre → Confirmation → Exécution.

CRITÈRES D'ACCEPTATION :
- Navigation entre étapes avec état conservé ; validation par étape.
- Aucun changement du design actuel ; nouveaux composants seulement si
  nécessaire et dans le style .nexus-dash.
- Aucune référence à une page « Routing Engine » dans la navigation
  utilisateur.
```

---

#### Étape 3.2 — /send : intégration du Routing Engine (routes A / B / C)

> **📌 NOTE DE CONTINUITÉ (si vous étiez déjà à l'ancien 3.2 « Quote & Routing »)** :
> Vous étiez sur le point de construire le backend PHP (IntentParser, CapabilityEngine,
> QuoteEngine, RoutingEngine, PolicyEngine, POST /api/quotes) + la page `/routing`.
> **Le backend ne change PAS** : construisez exactement les mêmes services PHP et
> endpoints que prévu. Seule la **partie frontend** change :
> 1. N'EXPOSEZ PAS `/routing` comme page du menu utilisateur (ni dans la Sidebar, ni
>    dans App.tsx). Si vous avez déjà créé RoutingPage, **conservez son composant de
>    comparaison de routes** et réutilisez-le comme **étape du workflow `/send`**.
> 2. Le formulaire d'intention (étape 3.1) enchaîne directement sur l'affichage des
>    routes A/B/C **dans la même page `/send`** (stepper), puis sur la confirmation.
> 3. Supprimez l'entrée « Routing Engine » de la Sidebar (menu « Compte personnel » :
>    Wallet, Envoyer, Historique, Nexus Pro).
> 4. Redirigez éventuellement l'ancienne route `/routing` vers `/send`.
> Rien d'autre ne change. La spec complète est dans MODIFICATION-ENVOYER-ROUTING.md.

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 3.2 — Intégration du Routing Engine dans le workflow /send

PRINCIPE (cf. MODIFICATION-ENVOYER-ROUTING.md) :
- ENVOYER = interface utilisateur ; ROUTING ENGINE = intelligence interne
  d'ENVOYER.
- Le Routing Engine N'EST PAS une page ni une entrée de menu : il est
  déclenché automatiquement à l'intérieur du workflow /send.
- On RÉUTILISE le Routing Engine existant (services du backend) — ne crée
  PAS un deuxième système. Conserve ses règles, providers, critères,
  scoring, fallbacks et paramètres.
- Architecture à respecter :
    Send UI (workflow /send)
      → Transfer Engine
          ├── Validation
          ├── Pricing
          └── Compliance
      → Routing Engine
          ├── Provider A / B / C / N
      → Selected Route
      → Transfer Execution

1. NAVIGATION : dans la Sidebar, menu « Compte personnel », SUPPRIME
   l'entrée « Routing Engine ». Conserve uniquement : Wallet, Envoyer,
   Historique, Nexus Pro. Supprime la route /routing de App.tsx (ou
   redirige-la vers /send). L'utilisateur ne doit jamais choisir entre
   « Envoyer » et « Routing Engine ».

2. BACKEND PHP (services/ — réutilise l'existant, migration de l'ancien
   agents/) :
   - src/services/IntentParser.php : valide/normalise l'intention
     (montant, devise, destination, objectif).
   - src/services/TransferEngine.php : orchestre le workflow d'envoi —
     enchaîne Validation → Pricing → Compliance → Routing Engine →
     Selected Route → passe le relais à l'Execution Engine (étape 3.3).
   - src/services/CapabilityEngine.php : détermine les providers éligibles
     (corridor EUR→XAF, Mobile Money, pays CG) depuis la table providers.
   - src/services/QuoteEngine.php : interroge les providers éligibles et
     calcule pour chacun montant reçu (taux fixe 655.957 − frais/spread),
     frais, délai estimé, fiabilité (depuis performance_score). Petite
     variation aléatoire bornée pour simuler la concurrence.
   - src/services/RoutingEngine.php : ROUTING ENGINE EXISTANT À RÉUTILISER
     (ne pas le recréer) — classe les routes selon l'objectif
     (⭐ Optimisé / ⚡ Plus rapide / 💰 Max reçu / 💸 Moins cher /
     🛡️ Plus fiable) et construit les 3 meilleures (A/B/C), ex. :
     Route A : reçu 327 000 XAF, frais 2.90 EUR, ~3 min, pawaPay,
     fiabilité Élevée ; Route B : 326 500 XAF, 3.40 EUR, ~8 min ;
     Route C : 328 100 XAF, 4.90 EUR, ~15 min, fiabilité Moyenne.
   - src/services/PolicyEngine.php : vérifie statut du compte (PENDING →
     refus transfert), plafonds LIMITED (200 EUR/mois), sanction simple
     (liste noire), seuils.
   - POST /api/quotes : reçoit l'intention → Capability → Quote → Routing
     → Policy → retourne { quotes: [ {id, badge, provider, method,
     received, fees, delay, reliability, recommended} ], expires_at }.
     Persiste la transaction en statut QUOTED avec expires_at (5 min).
   - GET /api/quotes/:id : quote + compte à rebours restant.

3. FRONTEND — étape « Routes » INTÉGRÉE dans le workflow /send (le
   composant de comparaison de l'ancienne RoutingPage est réutilisé comme
   étape du stepper /send ; conserve le design actuel) :
   - Affiche les 3 routes sous forme de cartes comparables : badge de mode
     (⭐ Optimisé / ⚡ Plus rapide / 💰 Max reçu), montant reçu en gros
     (JetBrains Mono), frais, délai, fiabilité, provider + méthode. La
     route recommandée est surlignée (bordure cyan + glow).
   - Barre de comparaison compacte en bas : récap route sélectionnée +
     bouton « Continuer ».
   - Compte à rebours (ex. 4:52) ; à expiration : « Cette quote a expiré,
     relancez une demande ».
   - Récupère aussi le statut des agents IA depuis GET /api/agents
     (si l'endpoint existe) pour afficher « Orchestrator : actif… ».
   - Pour l'utilisateur, tout doit apparaître comme UNE SEULE
     fonctionnalité : ENVOYER. Le routing est transparent ; seules des
     informations visibles (meilleure route, provider sélectionné, taux
     appliqué, frais, montant reçu, délai estimé, mode de réception) sont
     présentées comme le résultat de l'analyse automatique de Nexus.

CRITÈRES D'ACCEPTATION :
- Plus aucune entrée « Routing Engine » dans le menu utilisateur.
- Le workflow /send enchaîne intention → routes → sélection sans sortir
  de la page /send.
- Les routes sont cohérentes (mêmes frais de base, taux fixe, spreads
  variés) et réalistes pour EUR → XAF Mobile Money.
- Le RoutingEngine.php existant est réutilisé, pas dupliqué.
- L'expiration est vérifiée côté serveur (POST /api/transactions refuse
  si expirée).
```

---

#### Étape 3.3 — Confirmation & Execution Engine (machine à états)

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 3.3 — Confirmation & Exécution

BACKEND PHP :
1. src/services/ExecutionEngine.php + src/services/RecoveryEngine.php +
   src/services/ReconciliationEngine.php :
   - POST /api/transactions (idempotent via idempotency_key généré côté
     serveur) : passe la transaction QUOTED → AUTHORIZED → PROCESSING →
     PENDING → COMPLETED → SETTLED → RECONCILED, en écrivant chaque
     transition dans transaction_events et en débitant le wallet
     (available → in_transit à AUTHORIZED ; crédit destination à
     COMPLETED).
   - Simule l'exécution provider : 95 % succès, sinon FAILED puis
     tentative de récupération via une route alternative éligible
     (« Nexus Intelligent Recovery — Route B éligible ✓ ») ; cas TIMEOUT
     → UNKNOWN → réconciliation (match ou FAILED).
   - GET /api/transactions/:id : statut courant + events (timeline).
2. Écran de confirmation (avant POST) : récap complet (envoyé, reçu
   estimé, frais, provider, méthode, délai, fiabilité) + case
   « Je comprends que le montant reçu peut varier » + bouton
   « Confirmer et envoyer » (désactivé si quote expirée).

FRONTEND :
3. Page de suivi : timeline verticale animée des statuts avec
   horodatages (polling GET /api/transactions/:id toutes les 2 s),
   spinner pendant PROCESSING, note « Simulation : en production,
   l'exécution passe par le provider ».
4. Fin : écran de confirmation avec récap, numéro de transaction
   (NX-XXXX-XXXX), boutons « Voir le détail » et « Nouvel envoi »,
   notification créée.
5. Un rechargement de page ne perd pas l'état (statut lu depuis MySQL).

CRITÈRES D'ACCEPTATION :
- Double soumission impossible (idempotency_key unique).
- Le wallet reflète available / in_transit à chaque étape.
- transaction_events complet et horodaté.
```

---

#### Étape 3.4 — /history + détail de transaction

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 3.4 — Historique & détail

1. Backend PHP : GET /api/transactions (filtres status, type, période,
   devise ; recherche texte ; pagination 10/page), GET /api/transactions/
   :id (détail + events), GET /api/transactions/export (CSV).
2. Page /history : table des transactions (date, type, description
   ex. « → Airtel Money · +242 06 XX XX XX », montant signé, devise,
   statut avec badge coloré — mêmes styles que DashboardPage), filtres,
   pagination, export CSV.
3. Page /history/:id : en-tête (montant, statut, public_id en
   JetBrains Mono), résumé (envoyé / reçu / frais détaillés / taux /
   provider), timeline complète depuis transaction_events (annotations
   en français), bloc « Route » (A/B/C, provider, méthode), bouton
   « Refaire un envoi similaire » (pré-remplit /send), bloc
   « Tentative de récupération » si RecoveryEngine est intervenu.
4. Badges de statut unifiés : COMPLETED vert, PENDING or, FAILED rouge,
   TIMEOUT orange, SETTLED/RECONCILED cyan, CANCELLED/EXPIRED gris.

CRITÈRES D'ACCEPTATION :
- Les couleurs de statut sont cohérentes dans toute l'app.
- La timeline reflète exactement transaction_events.
```

---

### Étape 4 — KYC / KYB (Partie XIV)

#### Étape 4.1 — Statuts de compte & gating

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 4.1 — Statuts VERIFIED / PENDING / LIMITED / BLOCKED

1. Règles centralisées côté backend (src/services/PolicyEngine.php) :
   - VERIFIED : accès complet.
   - PENDING : lecture seule (dashboard/wallet), /send et /api/quotes
     refusés avec message « Vérification en cours », KYC mis en avant.
   - LIMITED : plafond 200 EUR/mois (vérifié côté serveur sur le cumul
     du mois), bannière persistante.
   - BLOCKED : aucune action ; écran « Compte suspendu » avec motif
     générique et contact support.
2. Frontend : badges et bannières cohérents (topbar, sidebar, pages
   sensibles) ; bouton « Vérifier mon identité » → /kyc. Le double
   contrôle UI + API est obligatoire.
3. Endpoint admin (dev) : POST /api/dev/set-status { user_id, status }
   pour tester.

CRITÈRES D'ACCEPTATION :
- Changer le statut en MySQL modifie immédiatement les permissions UI.
- /api/quotes et /api/transactions refusent un compte PENDING/BLOCKED.
```

---

#### Étape 4.2 — Parcours KYC personnel

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 4.2 — Parcours KYC (simulation Sumsub)

1. Backend PHP :
   - POST /api/kyc/applications : crée une kyc_applications
     (provider 'sumsub-simule', status pending) ; upload des documents
     dans nexus-api/uploads/ (bucket local, fichiers hors du public,
     accès contrôlé) ; vérifie MIME/taille (max 5 Mo, jpg/png/pdf).
   - POST /api/dev/approve-kyc : passe kyc_level à personal_verified,
     status VERIFIED, crée la notification.
2. Page /kyc (remplace le placeholder), parcours 4 étapes dans le style
   .nexus-dash :
   - « Identité » : prénom, nom, date de naissance, pays, adresse +
     upload pièce d'identité (aperçu).
   - « Vérification » : aperçu du document, capture « selfie/liveness »
     simulée (upload ou webcam), case d'acceptation biométrique.
   - « Revue » : résumé + mention « Sumsub est notre fournisseur de
     vérification d'identité. NEXUS conserve la responsabilité de
     l'orchestration et des règles internes. » + consentement.
   - Soumission → « Vérification en cours (moins de 24 h) ».
3. Les documents uploadés ne sont jamais servis publiquement ; endpoint
   GET /api/kyc/:id/document (auth) pour l'aperçu.

CRITÈRES D'ACCEPTATION :
- Aucun changement de statut sans passer par la logique applicative.
- Documents isolés dans uploads/ hors du webroot.
```

---

#### Étape 4.3 — KYB entreprise

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 4.3 — KYB entreprise

1. Création d'entreprise (bouton « Ouvrir un compte Business ») :
   - Étape société : nom légal, forme juridique, pays, adresse, secteur,
     upload registre de commerce / statuts.
   - Étape dirigeants : nom, rôle, part de détention ; UBO si part
     ≥ 25 %.
   - Étape revue + consentement (comme 4.2).
2. Après approbation (POST /api/dev/approve-kyb) : team « Owner » créée
   (owner_id = user), wallets business de démo (EUR 120 000, USD 85 000,
   XAF 40 000 000, USDC 25 000), menu « Nexus Business » visible.
3. Backend : POST /api/business/applications, GET /api/business/status.

CRITÈRES D'ACCEPTATION :
- La section Business n'apparaît que si KYB approuvé.
- UBO stockés dans kyc_applications.payload.
```

---

### Étape 5 — Nexus Business (Partie XV)

#### Étape 5.1 — /treasury : Workspace Business

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 5.1 — Treasury Dashboard Business

1. Backend PHP : GET /api/business/treasury (wallets de l'équipe,
   exposition par devise, variation 30 j), GET /api/business/activity.
2. Page /treasury (remplace le placeholder) :
   - En-tête : trésorerie totale (EUR de référence) + sélecteur d'entité.
   - Grille des wallets business (EUR, USD, XAF, USDC) avec available /
     pending / in_transit / settlement.
   - Carte « Exposition devise » : répartition (donut/barres) par devise
     + variation 30 jours.
   - Cartes de fonctionnalités (💼 Trésorerie, 💸 Mass Payments,
     📥 Collections, 💳 Cartes Business, 📊 Reporting, 🤖 AI Business)
     avec lien vers les pages (placeholders OK).
   - Liste des approbations en attente avec badge rouge.
3. Style : composants .nexus-dash existants uniquement.

CRITÈRES D'ACCEPTATION :
- Données issues des wallets business réels (équipe owner).
- Page inaccessible si account_type ≠ business (UI + API).
```

---

#### Étape 5.2 — /team : rôles & permissions (RBAC)

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 5.2 — Rôles & permissions

1. Backend PHP : GET/POST /api/team/members, DELETE /api/team/members/
   :id, POST /api/team/members/:id/role. Invitation = création
   d'utilisateur (si besoin) avec rôle + email de bienvenue (console).
2. src/services/RbacService.php — matrice de permissions :
   - owner : tout, gestion des membres, suppression d'équipe ;
   - administrator : configuration, provider settings, membres (sauf
     owner) ;
   - finance_manager : approbation, reporting, trésorerie ;
   - accountant : lecture + réconciliation + export ;
   - operator : initiation des paiements (sans approbation) ;
   - viewer : consultation uniquement.
3. Page /team : liste des membres (avatar, nom, email, rôle, badges de
   couleur), invitation (email + rôle), changement de rôle, retrait.
   Le rôle courant s'affiche en topbar.
4. Le RBAC est appliqué côté API (chaque endpoint vérifie le rôle) ET
   côté UI (masquage des boutons).

CRITÈRES D'ACCEPTATION :
- Un viewer n'a aucun bouton d'action (et l'API le refuse).
- Un operator ne peut pas approuver ses propres paiements.
```

---

#### Étape 5.3 — /approvals : workflow d'approbation

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 5.3 — Approval Workflows

1. Backend PHP : POST /api/approvals (créer une demande), GET
   /api/approvals?status=pending, POST /api/approvals/:id/approve,
   POST /api/approvals/:id/reject { note }, GET /api/approvals/history.
   Règles : montant max sans approbation + double approbation au-delà
   d'un seuil (configurable, ex. > 10 000 EUR) ; personne n'approuve sa
   propre demande.
2. Flux : Operator crée un paiement → statut « En attente
   d'approbation » (transaction en statut intermédiaire) +
   notification au Finance Manager → file « Approbations » (badge rouge
   dans le menu Business) → détail (paiement, historique du demandeur,
   boutons Approuver / Rejeter avec motif obligatoire) → à
   l'approbation : Policy → Routing → Execution (réutilise étape 3).
3. Page /approvals : file en attente + historique (qui, quand, motif).

CRITÈRES D'ACCEPTATION :
- Statut d'approbation visible sur la page de détail de la transaction.
- Les deux règles (anti-auto-approbation, seuils) sont testées.
```

---

#### Étape 5.4 — /payments (Mass Payments) + /reporting

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 5.4 — Mass Payments & Reporting

1. Backend PHP : POST /api/mass-payments (batch : lignes bénéficiaire /
   méthode / devise / montant), POST /api/mass-payments/import (CSV,
   validation ligne par ligne avec n° de ligne + motif), GET
   /api/mass-payments/:id/progress (x/y réussis, échecs, retry).
2. Page /payments : ajout manuel en tableau ou upload CSV (template à
   télécharger), résumé (total, frais estimés par route), soumission →
   une approval_requests ; après approbation, chaque paiement est une
   transaction individuelle (idempotency_key propre) ; tableau
   d'avancement temps réel.
3. /reporting : volume payé / collecté par mois et par devise, taux de
   réussite par provider, export CSV. Endpoint PHP GET
   /api/business/reporting?period=month.
4. Collections (lien de paiement simulé) : générer un lien/IBAN à
   partager, suivre les paiements entrants ; bouton « Simuler un
   paiement entrant ».

CRITÈRES D'ACCEPTATION :
- CSV invalide → erreurs par ligne ; chaque paiement traçable.
- Reporting alimenté par les transactions réelles.
```

---

### Étape 6 — Nexus Pro, Intelligence & Back-office (Parties VII, XVII, XXVII)

#### Étape 6.1 — /nexus-pro : GPM, spreads & alertes

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 6.1 — Nexus Pro : intelligence & alertes

1. Backend PHP : GET /api/pro/market (spreads EUR/XAF simulés,
   fourchettes providers, délais moyens), GET /api/pro/opportunities
   (calcul : Prix achat + frais + spread + réseau + slippage + risque =
   Coût réel → Prix de sortie − Coût réel = Profit potentiel — toujours
   affiché « Opportunité potentielle — jamais un gain garanti »), CRUD
   /api/pro/alerts, POST /api/pro/activate (abonnement simulé).
2. Page /nexus-pro (remplace le placeholder) : paywall simple
   (« Activer Nexus Pro », statut sur le profil) puis :
   - GPM : indicateurs de marché, courbes/histogrammes ;
   - Opportunités : cartes avec le disclaimer réglementaire obligatoire ;
   - Alertes configurables : seuils (spread, prix/taux, liquidité,
     route, marché) → déclenchement simulé → notification.
3. Style .nexus-dash, composants graphiques cohérents avec le dashboard.

CRITÈRES D'ACCEPTATION :
- Toute opportunité porte le disclaimer réglementaire.
- Les alertes créées apparaissent dans /notifications quand déclenchées.
```

---

#### Étape 6.2 — Agents IA en PHP + page /agents

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 6.2 — Agents IA (Orchestrator, Compliance, Routing, Execution)

L'ancien backend agents/ (Express) est remplacé par des services PHP
déterministes qui simulent les 4 agents (aucun LLM requis) :

1. src/agents/OrchestratorAgent.php : reçoit une intention, coordonne
   le pipeline (Intent → Capability → Quote → Routing → Policy →
   Execution) et expose un état d'avancement.
2. src/agents/ComplianceAgent.php : vérifie statut, KYC level, plafonds,
   sanctions simples, seuils d'approbation ; peut « bloquer » une route
   (ex. plafond LIMITED).
3. src/agents/RoutingAgent.php : sélection et classement des routes
   (délègue à RoutingEngine) ; choisit la route de secours en cas
   d'échec (Recovery).
4. src/agents/ExecutionAgent.php : exécute la machine à états,
   écrit transaction_events, gère timeout/recovery.
5. GET /api/agents : statut des 4 agents (actif, dernier run, temps,
   erreurs) → affiché sur la page /agents (remplace le placeholder) avec
   le composant AgentStatus existant (ou équivalent .nexus-dash).
6. GET /api/agents/:id/logs : derniers événements (pour l'UI).

CRITÈRES D'ACCEPTATION :
- /api/agents répond et la page /agents affiche les 4 agents avec état.
- Les agents respectent le principe : « L'IA recommande, les règles
  vérifient, l'utilisateur décide » — aucune décision financière seule.
```

---

#### Étape 6.3 — Back-office : administration du Routing Engine

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 6.3 — Back-office /admin/routing (jamais exposé à l'utilisateur)

Le Routing Engine reste un composant métier central : il n'a pas de page
utilisateur (étape 3.2), mais il a une interface d'ADMINISTRATION réservée
aux rôles administrateur (owner / administrator) ou aux opérateurs internes.

1. Accès : route /admin/routing protégée (rôle admin requis — RBAC
   étape 5.2). NON listée dans le menu « Compte personnel » ni dans la
   navigation utilisateur. Entrée visible uniquement dans un espace
   « Administration » séparé (ou via un chemin direct réservé).

2. Backend PHP (src/services/ + contrôleurs dédiés) :
   - CRUD /api/admin/providers : configurer les providers (statut,
     capabilities, pays, devises, rails, frais, SLA, performance_score,
     api_version, webhooks).
   - /api/admin/corridors : gérer les corridors (devises source/
     destination, providers autorisés, taux de référence, plafonds).
   - /api/admin/routing-rules : définir les priorités et règles de
     sélection (ordre des critères, poids du scoring, règles de
     fallback, seuils).
   - /api/admin/routing/fallbacks : configurer les fallbacks (routes
     de secours, ordre de tentative, politique en cas de timeout).
   - GET /api/admin/routing/overview : supervision des routes
     (routes récentes, statuts, quotes) et performances (taux de
     réussite par provider, délais, frais moyens — KPIs section 55).

3. Frontend /admin/routing (style .nexus-dash, sections) :
   - « Providers » : tableau avec statut on/off, score, frais, toggles.
   - « Corridors » : liste des corridors actifs (EUR→XAF par défaut) +
     ajout/modification.
   - « Règles & priorités » : configuration du scoring (objectif par
     défaut, poids frais/vitesse/fiabilité), règles de fallback.
   - « Supervision » : dernières routes exécutées, taux de réussite,
     délais — graphiques simples cohérents avec le dashboard.

CRITÈRES D'ACCEPTATION :
- Un utilisateur standard ne voit AUCUNE entrée ni route /admin/*.
- Les modifications (provider off, règle de scoring, fallback) sont
  prises en compte par le RoutingEngine dès la quote suivante.
- Toute modification est journalisée dans audit_logs.
```

---

### Étape 7 — Sécurité & Conformité (Parties XX & XXIII)

#### Étape 7.1 — Durcissement : MFA, sessions, audit, rate limiting

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 7.1 — Sécurité

1. MFA TOTP : classe PHP src/Auth/Totp.php (HMAC + RFC 6238, sans
   lib) ; POST /api/security/mfa/enable (QR code en data URI),
   POST /api/security/mfa/verify, POST /api/security/mfa/disable ;
   exigence MFA pour /api/transactions si activée.
2. Sessions : POST /api/security/sessions (liste), DELETE /api/security/
   sessions/:id (révoquer) ; notification « Nouvelle connexion détectée »
   au login si appareil inconnu (table sessions).
3. Audit logs : audit_logs alimentée par chaque action sensible
   (connexion, transfert, approbation, KYC, MFA, rôle) avec ip et
   user_agent ; GET /api/security/audit?filters (réservé aux rôles
   autorisés).
4. Rate limiting : middleware PHP — 30 req/min sur /api/quotes et
   /api/transactions (table ou cache fichier en dev, Redis optionnel en
   prod) → 429 { error: "Trop de requêtes. Réessayez dans X s." }.
5. Endpoints critiques : validation stricte des entrées, requêtes
   préparées PDO (jamais de concaténation), headers de sécurité
   (X-Content-Type-Options, X-Frame-Options, CSP).

CRITÈRES D'ACCEPTATION :
- Toute action sensible est tracée (testable).
- Un rate limit déclenché renvoie 429.
- Aucune requête SQL concaténée dans le code.
```

---

#### Étape 7.2 — /settings

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 7.2 — Page Paramètres

1. Backend PHP : PUT /api/settings/profile (nom, avatar — upload,
   langue fr/en, ref_currency), PUT /api/settings/notifications
   (email/in-app/off), POST /api/settings/export (données JSON/CSV),
   POST /api/settings/delete-account (confirmation forte), GET/POST
   /api/settings/subscription (Free/Pro).
2. Page /settings (remplace le placeholder ou nouvelle) avec onglets :
   Profil / Sécurité (mot de passe, MFA, sessions — étape 7.1) /
   Préférences / Données / Abonnement.
3. Les modifications se reflètent immédiatement (nom en sidebar,
   devise de référence dans les montants) via le contexte.

CRITÈRES D'ACCEPTATION :
- Chaque modification persiste en MySQL et se reflète en UI.
- Export = fichiers valides ; suppression = confirmation + hard delete
  des données utilisateur (ou anonymisation, selon ton choix).
```

---

### Étape 8 — Polissage, tests, déploiement

#### Étape 8.1 — Responsive & accessibilité

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 8.1 — Polissage

1. Audit responsive 320–1920 px : sidebar → drawer < 900 px, tables →
   cartes sur mobile, graphiques adaptatifs, aucun débordement
   horizontal.
2. Accessibilité : contrastes AA, focus visibles, labels de formulaires,
   aria sur modales/onglets, navigation clavier, alt sur images.
3. États systématiques : skeletons/spinners, EmptyState avec CTA,
   erreur avec « Réessayer », confirmation destructive en modale.
4. Micro-interactions légères : hover/active, transitions douces —
   dans l'esprit du design actuel, sans le dénaturer.
5. Cohérence : mêmes badges de statut, formats de montants/date (fr-FR)
   partout.

CRITÈRES D'ACCEPTATION :
- Lighthouse : performance ≥ 90, accessibilité ≥ 95 (desktop + mobile).
```

---

#### Étape 8.2 — Tests (Vitest + PHPUnit)

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 8.2 — Tests automatisés

1. Frontend (Vitest + Testing Library) : formatage montants/devises,
   machine à états client, validation des formulaires (send, KYC,
   sources), composants critiques (RouteCard, StatCard, badges de
   statut), AuthContext (login/logout/401).
2. Backend (PHPUnit — composer require --dev phpunit/phpunit) :
   RoutingEngine (3 routes cohérentes), PolicyEngine (statuts/plafonds),
   ExecutionEngine (machine à états + idempotence), Jwt (signature/
   expiration), RateLimiter (429), AuthController (register/login).
3. E2E (Playwright) : parcours « Inscription → KYC → Envoyer 500 € →
   sélection route → confirmation → suivi → historique » ; parcours
   Business « création paiement → approbation → exécution ».
4. CI (GitHub Actions) : lint (oxlint) + tsc + tests front + tests PHP
   (service php disponible) à chaque PR.

CRITÈRES D'ACCEPTATION :
- Couverture ≥ 70 % sur le cœur (routing, policy, execution, JWT).
- Le scénario e2e MVP passe de bout en bout.
```

---

#### Étape 8.3 — Déploiement (PHP + MySQL en production)

**Prompt à copier :**

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 8.3 — Mise en production

1. Backend : hébergeur PHP + MySQL (ou VPS) ; structure : document root
   pointé sur nexus-api/public (le reste hors webroot) ; .htaccess
   Apache ; .env de production (secrets hors repo) ; uploads/ non
   exécutable (php_flag engine off ou dossier hors webroot).
2. Frontend : `npm run build` → dist/ servi par l'hébergeur avec
   rewrite SPA vers index.html ; ou déploiement Vercel/Netlify avec
   proxy /api vers le backend PHP.
3. CORS en production : headers Access-Control-Allow-Origin = domaine
   du frontend (uniquement), Authorization autorisé, méthodes
   GET/POST/PUT/DELETE/OPTIONS.
4. SEO : métadonnées par page, Open Graph, sitemap.xml, robots.txt,
   favicon, page 404 personnalisée.
5. Monitoring : erreurs PHP loggées (fichiers rotatifs), page /_health,
   KPIs clés en base (success rate, quote latency, temps moyen).
6. Checklist go-live : env séparées, secrets hors code, audit logs
   alimentés, rate limiting actif, sauvegardes MySQL (mysqldump
   planifié), HTTPS forcé, mentions légales & RGPD.

CRITÈRES D'ACCEPTATION :
- L'app est accessible en https, l'auth fonctionne en prod, aucun secret
  dans le code client ni dans les logs.
- README de déploiement à jour.
```

---

### Étape 9 — Horizons avancés (Roadmap P2 → P8)

#### Étape 9.1 — IBAN virtuels & nouveaux corridors (P2)

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 9.1 — Comptes virtuels & IBAN (P2)

Ajoute les « Comptes Virtuels » : génération d'IBAN virtuel par devise
(simulé), page « Recevoir » (IBAN, BIC, titulaire à partager), suivi des
virements entrants avec réconciliation automatique simulée, et des
corridors supplémentaires (XAF → USD, EUR → USD) dans /send (étendre
CapabilityEngine et RoutingEngine).
```

#### Étape 9.2 — Crypto wallets & stablecoins (P4)

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 9.2 — Crypto & stablecoins (P4)

Wallets USDT/USDC existants : adresses de dépôt par réseau (TRC20, ERC20,
BEP20 simulées), on/off-ramp simulé (Bridge, Yellow Card, CashRamp),
« Crypto routing » dans /send (fiat → stablecoin → fiat, cross-asset),
suivi on-chain simulé (confirmations, hash, explorateur factice).
```

#### Étape 9.3 — Cartes virtuelles (P5)

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 9.3 — Cartes virtuelles (P5)

Module Cartes : demande de carte virtuelle (devise au choix), rendu 3D
de la carte (design sombre NEXUS), révélation du CVV (masqué par défaut,
JetBrains Mono), gel/dégel, plafonds, transactions de la carte, paiement
depuis un wallet ; Cartes Business avec règles et plafonds d'équipe.
```

#### Étape 9.4 — Nexus Connect : API publique (P8)

```text
[CONTEXTE GLOBAL] (voir section 1)

ÉTAPE 9.4 — Nexus Connect (P8)

Documentation API publique (page /developers, style NEXUS) : endpoints
Quotes / Transactions / Webhooks ; génération de clés API (API keys +
webhook signing), dashboard développeur (volume, erreurs, logs),
simulation d'appels ; page marketing « Embedded Finance / White-label ».
```

---

## 5. Conseils d'utilisation

1. **Ordre strict** : les étapes 0.x d'abord — tout le reste en dépend (le frontend doit parler au backend PHP avant d'aller plus loin).
2. **Un prompt par session** : donnez un prompt à la fois, validez (API répond, build passe, parcours manuel ok), puis enchaînez.
3. **Contexte global** : recopiez le bloc de la section 1 au début de chaque nouvelle conversation, puis collez le prompt de l'étape.
4. **Design intouchable** : si une IA propose de « moderniser » le style, refusez. Le design actuel (violet/glass public + cyan/gold/green `.nexus-dash`) est la charte officielle.
5. **Données de démo** : montants de référence de la spec (EUR 2 500,00 · XAF 1 500 000 · routes 327 000 / 326 500 / 328 100 XAF) — gardez-les cohérents dans les seeds.
6. **Le corridor EUR → XAF est la priorité** : étapes 0 → 3 = la « boucle fondamentale » du MVP (Phase 1). Si le temps manque, livrez ces étapes d'abord.
7. **Sécurité minimale** : requêtes préparées PDO, password_hash, JWT signé, secrets dans config/ hors repo, jamais de mot de passe en clair en base.
8. **L'ancien backend agents/ (Express)** peut être archivé ou supprimé : son rôle est repris par les services PHP (étape 6.2).
9. **Unification « Envoyer » / « Routing Engine »** : cf. MODIFICATION-ENVOYER-ROUTING.md — le Routing Engine n'est jamais une page utilisateur ni une entrée de menu ; toute fonctionnalité de transfert passe par /send, et l'administration du routing se fait uniquement dans /admin/routing (étape 6.3).
