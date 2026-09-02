<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/app.php';
require BASE_PATH . '/config/constants.php';
require BASE_PATH . '/config/database.php';
require BASE_PATH . '/vendor/autoload.php';

use Nexus\Core\Database;
use Nexus\Providers\CashrampAdapter;

$appKeyPresent = defined('APP_KEY') && is_string(APP_KEY) && strlen(APP_KEY) > 0;

$dbStatus = 'UNKNOWN';
$credRowMeta = null;
try {
    $pdo = Database::getConnection();
    $dbStatus = 'CONNECTED';
    $stmt = $pdo->query("SELECT provider_slug, environment, status, last_tested_at, last_error FROM provider_credentials WHERE provider_slug = 'cashramp'");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $credRowMeta = $rows;
} catch (Throwable $e) {
    $dbStatus = 'ERROR: ' . $e->getMessage();
}

$cashrampTest = null;
try {
    $adapter = new CashrampAdapter();
    $cashrampTest = $adapter->testConnection('sandbox');
} catch (Throwable $e) {
    $cashrampTest = ['status' => 'EXCEPTION', 'message' => $e->getMessage()];
}

echo json_encode([
    'app_key_present' => $appKeyPresent ? 'YES' : 'NO',
    'database'        => $dbStatus,
    'credentials_meta'=> $credRowMeta,
    'cashramp_test'   => $cashrampTest,
    'timestamp'       => gmdate(DATE_ATOM),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
