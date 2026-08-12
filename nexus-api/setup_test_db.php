<?php
// Crée la base nexus_test avec toutes les tables nécessaires.
// Retire les lignes CREATE DATABASE / USE de chaque fichier SQL pour
// qu'elles s'exécutent bien dans nexus_test.

$MYSQL = 'C:\xampp\mysql\bin\mysql.exe';

// 1. Drop + Create
exec("\"$MYSQL\" -u root -e \"DROP DATABASE IF EXISTS nexus_test; CREATE DATABASE nexus_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"");

// 2. Construire un SQL combiné avec USE nexus_test en en-tête
$combined = "USE nexus_test;\n\n";

$files = [
    __DIR__ . '/database/schema.sql',
    __DIR__ . '/database/migrations/2026_08_10_dashboard.sql',
    __DIR__ . '/database/migrations/2026_08_10_wallet_core.sql',
    __DIR__ . '/database/migrations/2026_08_11_add_hold_operation_type.sql',
    __DIR__ . '/database/migrations/2026_08_12_add_expires_at_to_wallet_operations.sql',
];

foreach ($files as $file) {
    $sql = file_get_contents($file);
    // Retirer les lignes USE/CREATE DATABASE/CREATE DATABASE IF NOT EXISTS
    $sql = preg_replace('/CREATE DATABASE.*?;/mi', '', $sql);
    $sql = preg_replace('/USE nexus\s*;/mi', '', $sql);
    $combined .= "-- === " . basename($file) . " ===\n\n";
    $combined .= $sql . "\n\n";
}

// 3. Écrire dans un fichier temporaire et l'envoyer à mysql
$tmpFile = tempnam(sys_get_temp_dir(), 'nexus_test_');
file_put_contents($tmpFile, $combined);

$cmd = "\"$MYSQL\" -u root nexus_test < \"$tmpFile\"";
exec($cmd, $out, $ret);

if ($ret !== 0) {
    echo "ERREUR mysql:\n" . implode("\n", $out) . "\n";
    unlink($tmpFile);
    exit(1);
}

unlink($tmpFile);

// 4. Vérification
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=nexus_test;charset=utf8mb4', 'root', '');
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "nexus_test tables (" . count($existing) . "): " . implode(', ', $existing) . "\n";

$col = $pdo->query("SHOW COLUMNS FROM wallets LIKE 'hold_balance'")->fetch();
echo "wallets.hold_balance: " . ($col ? 'OK' : 'MISSING') . "\n";

$rates = (int) $pdo->query('SELECT COUNT(*) FROM fx_rates_cache')->fetchColumn();
echo "fx_rates_cache: $rates rates\n";

// 5. Vérifier la base dev n'est pas touchée
$pdo_dev = new PDO('mysql:host=127.0.0.1;port=3306;dbname=nexus;charset=utf8mb4', 'root', '');
$devUsers = (int) $pdo_dev->query('SELECT COUNT(*) FROM users')->fetchColumn();
$devWallets = (int) $pdo_dev->query('SELECT COUNT(*) FROM wallets')->fetchColumn();
echo "\nnexus (dev) intact: users=$devUsers, wallets=$devWallets\n";
