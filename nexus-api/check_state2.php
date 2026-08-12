<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=nexus_test;charset=utf8mb4', 'root', '');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "nexus_test tables (" . count($tables) . "): " . implode(', ', $tables) . "\n";

// Vérifier nexus
$pdo2 = new PDO('mysql:host=127.0.0.1;port=3306;dbname=nexus;charset=utf8mb4', 'root', '');
$tables2 = $pdo2->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "nexus tables (" . count($tables2) . "): " . implode(', ', $tables2) . "\n";

$col = $pdo2->query("SHOW COLUMNS FROM wallets LIKE 'hold_balance'")->fetch();
echo "nexus.wallets.hold_balance: " . ($col ? 'EXISTS' : 'MISSING') . "\n";
