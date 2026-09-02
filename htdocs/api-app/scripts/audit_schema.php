<?php
$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=nexus_test';
$pdo = new PDO($dsn, 'root', '');

$tables = [
    'provider_credentials',
    'provider_customers',
    'provider_platform_config',
    'funding_intents',
    'wallets',
    'wallet_operations',
    'ledger_entries',
    'transactions',
    'audit_logs',
];

foreach ($tables as $table) {
    echo "=== $table ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo sprintf("  %-30s %-20s %s\n", $col['Field'], $col['Type'], $col['Null'] . ' ' . $col['Key']);
    }
    echo "\n";
}
