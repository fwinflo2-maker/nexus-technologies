<?php

declare(strict_types=1);

/**
 * Routeur pour le serveur de développement PHP (`php -S localhost:8080`).
 *
 * Reproduit le comportement du .htaccess Apache :
 *  - les fichiers et dossiers existants sont servis directement ;
 *  - tout le reste est routé vers public/index.php (front controller).
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    // Laisse le serveur intégré servir le fichier statique tel quel.
    return false;
}

require __DIR__ . '/index.php';
