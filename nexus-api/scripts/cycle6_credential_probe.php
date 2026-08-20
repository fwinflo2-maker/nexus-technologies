<?php

declare(strict_types=1);

/**
 * Cycle 6 — probe credentials WITHOUT printing secrets.
 * Outputs only presence / status / lengths.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_ENV', 'development');
define('APP_KEY', 'nexus-dev-data-key-change-me');

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/database.php';

use Nexus\Core\Database;

$pdo = Database::getConnection();
echo 'db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

$stmt = $pdo->prepare(
    "SELECT environment, status,
            (credentials_enc IS NOT NULL AND credentials_enc <> '') AS has_blob,
            (last_tested_at IS NULL) AS never_tested,
            CHAR_LENGTH(COALESCE(last_error, '')) AS err_len
     FROM provider_credentials
     WHERE user_id IS NULL AND provider_slug = 'pawapay'"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo 'platform_pawapay_rows=' . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo sprintf(
        "env=%s status=%s has_blob=%s never_tested=%s err_len=%s\n",
        $r['environment'],
        $r['status'],
        $r['has_blob'],
        $r['never_tested'],
        $r['err_len']
    );
}

$all = (int) $pdo->query(
    "SELECT COUNT(*) FROM provider_credentials WHERE provider_slug = 'pawapay'"
)->fetchColumn();
echo 'all_pawapay_rows=' . $all . PHP_EOL;

$envNames = [
    'PROVIDER_PAWAPAY_SANDBOX_API_TOKEN',
    'PROVIDER_PAWAPAY_PRODUCTION_API_TOKEN',
    'PAWAPAY_API_TOKEN',
];
foreach ($envNames as $name) {
    $v = getenv($name);
    if ($v === false || $v === '') {
        echo $name . '=absent' . PHP_EOL;
    } else {
        echo $name . '=present_len=' . strlen($v) . PHP_EOL;
    }
}

echo 'dotenv_file=' . (is_file(BASE_PATH . '/.env') ? 'present' : 'absent') . PHP_EOL;
