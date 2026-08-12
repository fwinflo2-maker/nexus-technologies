<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=nexus_test;charset=utf8mb4', 'root', '');
echo "wallets: " . $pdo->query('SELECT COUNT(*) FROM wallets')->fetchColumn() . "\n";
echo "users: " . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
echo "wallet_operations: " . $pdo->query('SELECT COUNT(*) FROM wallet_operations')->fetchColumn() . "\n";
echo "ledger_entries: " . $pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() . "\n";
$rows = $pdo->query('SELECT id, user_id, currency, balance FROM wallets')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "  wallet #" . $r['id'] . " user=" . $r['user_id'] . " " . $r['currency'] . " = " . $r['balance'] . "\n"; }