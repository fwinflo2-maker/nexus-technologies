<?php

declare(strict_types=1);

/**
 * Boucle du worker d'expiration des holds (Phase G+).
 *
 * Exécute `expire_holds.php` toutes les EXPIRE_HOLD_INTERVAL_SECONDS secondes
 * (défaut : 60 s). Conçu pour être lancé comme processus persistant :
 *
 *   php scripts/expire_holds_loop.php
 *
 * Chaque itération est un process isolé : les statistiques (Expired/Skipped/
 * Errors) sont affichées puis l'intervalle suivant est attendu.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

$interval = (int) (getenv('EXPIRE_HOLD_INTERVAL_SECONDS') ?: 60);
if ($interval < 5) {
    $interval = 60;
}

$script = __DIR__ . '/expire_holds.php';
if (!is_file($script)) {
    fwrite(STDERR, 'FATAL: expire_holds.php introuvable.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf('[%s] Expiration worker démarré (intervalle %ds)' . PHP_EOL, date('c'), $interval));

while (true) {
    fwrite(STDOUT, sprintf('[%s] --- Cycle ---' . PHP_EOL, date('c')));
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDOUT, sprintf('[%s] Cycle terminé avec le code %d' . PHP_EOL, date('c'), $exitCode));
    }

    sleep($interval);
}
