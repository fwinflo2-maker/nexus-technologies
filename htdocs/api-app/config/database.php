<?php

declare(strict_types=1);

/**
 * Configuration de la base de données MySQL (XAMPP).
 *
 * Les valeurs par défaut peuvent être surchargées via le fichier `.env`
 * placé à la racine de nexus-api/ (voir `.env.example`), chargé par
 * `config/env.php` avant ce fichier.
 */

// --- Constantes de connexion -------------------------------------------------
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'nexus');
defined('DB_CHARSET') || define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// Identifiants : défauts XAMPP (root sans mot de passe) uniquement en
// développement. Hors développement, ils doivent venir du `.env` ; sinon
// les constantes ne sont pas définies et l'API refuse de démarrer
// (contrôle fail-closed dans public/index.php).
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

if (APP_ENV === 'development') {
    defined('DB_USER') || define('DB_USER', $dbUser ?: 'root');
    defined('DB_PASS') || define('DB_PASS', $dbPass ?: '');
} elseif ($dbUser !== false && $dbPass !== false) {
    defined('DB_USER') || define('DB_USER', $dbUser);
    defined('DB_PASS') || define('DB_PASS', $dbPass);
}
