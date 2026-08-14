<?php

declare(strict_types=1);

/**
 * Constantes globales de l'API NEXUS.
 *
 * Ce fichier est chargé par le front controller après `config/app.php`
 * (il dépend de la constante APP_ENV). Les secrets proviennent de
 * l'environnement (`.env`) ; des défauts de développement sont fournis.
 * Hors développement, les secrets doivent être fournis par l'environnement,
 * sinon le contrôle fail-closed de `public/index.php` refuse de démarrer.
 */

// --- JWT -------------------------------------------------------------------
// Durée de vie du JWT en secondes (défaut : 24 h).
defined('JWT_TTL') || define('JWT_TTL', (int) (getenv('JWT_TTL') ?: 86400));

$jwtSecret = getenv('JWT_SECRET');
if (APP_ENV === 'development') {
    defined('JWT_SECRET') || define('JWT_SECRET', $jwtSecret ?: 'nexus-dev-secret-change-me');
} elseif ($jwtSecret !== false && $jwtSecret !== '') {
    defined('JWT_SECRET') || define('JWT_SECRET', $jwtSecret);
}

// --- Chiffrement des données sensibles (comptes, IBAN, etc.) ------------------
$appKey = getenv('APP_KEY');
if (APP_ENV === 'development') {
    defined('APP_KEY') || define('APP_KEY', $appKey ?: 'nexus-dev-data-key-change-me');
} elseif ($appKey !== false && $appKey !== '') {
    defined('APP_KEY') || define('APP_KEY', $appKey);
}

// --- Google OAuth -----------------------------------------------------------
// Client ID fourni par Google Cloud Console (identifiant public).
// §30 : aucune valeur en dur. Le client ID provient EXCLUSIVEMENT de
// l'environnement. Absent, la constante vaut '' et la vérification du jeton
// Google échoue explicitement plutôt que de valider contre un ID étranger.
$googleClientId = getenv('GOOGLE_CLIENT_ID');
defined('GOOGLE_CLIENT_ID') || define(
    'GOOGLE_CLIENT_ID',
    ($googleClientId !== false && $googleClientId !== '') ? $googleClientId : ''
);
