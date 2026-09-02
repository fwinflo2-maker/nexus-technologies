<?php

declare(strict_types=1);

/**
 * Applique la migration cashramp_integration (idempotent).
 *
 * Usage (Hostinger SSH ou navigateur protégé) :
 *   php scripts/apply_cashramp_integration_migration.php
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/app.php';
require BASE_PATH . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nexus\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Nexus\Core\Database;

if (!defined('DB_USER') || !defined('DB_PASS')) {
    fwrite(STDERR, "DB credentials missing.\n");
    exit(1);
}

$sqlFile = BASE_PATH . '/migrations/2026_09_01_cashramp_integration.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Migration file missing: {$sqlFile}\n");
    exit(1);
}

$sql = (string) file_get_contents($sqlFile);
$pdo = Database::getConnection();

try {
    $pdo->exec($sql);
    echo "cashramp_integration migration applied.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
