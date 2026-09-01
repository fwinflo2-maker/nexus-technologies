<?php

declare(strict_types=1);

/**
 * Chargement du fichier `.env` (syntaxe simple KEY=VALUE).
 *
 * Exécuté en premier dans le front controller : les fichiers `app.php` et
 * `database.php` lisent ensuite les variables via getenv().
 */

$envFile = dirname(__DIR__) . '/.env';

if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Ne surcharge pas une variable d'environnement déjà définie.
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
