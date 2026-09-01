<?php

declare(strict_types=1);

/**
 * Configuration applicative : environnement de déploiement et origines CORS.
 *
 * Valeurs surchargées par le fichier `.env` (chargé par `config/env.php`).
 */

defined('APP_ENV') || define('APP_ENV', getenv('APP_ENV') ?: 'development');

// Origines autorisées pour le CORS, séparées par des virgules dans `.env`.
$origins = getenv('APP_ORIGINS') ?: '';
defined('APP_ORIGINS') || define(
    'APP_ORIGINS',
    array_values(array_filter(array_map('trim', explode(',', $origins))))
);
