<?php

declare(strict_types=1);

/**
 * Réinjecte les taux FX sandbox de démonstration (TTL 30 j).
 * Usage : php scripts/seed_fx_rates.php
 */

define('BASE_PATH', dirname(__DIR__));

$envFile = BASE_PATH . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: 'nexus';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false && getenv('DB_PASS') !== '' ? (string) getenv('DB_PASS') : '';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pairs = [
    ['EUR', 'USD',  '1.08700000'],
    ['EUR', 'GBP',  '0.85500000'],
    ['EUR', 'XAF',  '655.95700000'],
    ['EUR', 'XOF',  '655.95700000'],
    ['EUR', 'NGN',  '1650.00000000'],
    ['EUR', 'GHS',  '14.80000000'],
    ['EUR', 'KES',  '141.00000000'],
    ['EUR', 'USDT', '1.08700000'],
    ['EUR', 'USDC', '1.08700000'],
    ['EUR', 'ETH',  '0.00038000'],
    ['EUR', 'BTC',  '0.00001100'],
    ['USD', 'EUR',  '0.92000000'],
    ['GBP', 'EUR',  '1.17000000'],
    ['XAF', 'EUR',  '0.00152400'],
    ['USD', 'XAF',  '603.45000000'],
    ['USD', 'GBP',  '0.78700000'],
    ['USD', 'USDT', '1.00000000'],
    ['USD', 'USDC', '1.00000000'],
    ['GBP', 'USD',  '1.27000000'],
    ['GBP', 'XAF',  '767.20000000'],
    ['XAF', 'USD',  '0.00165700'],
    ['USDT', 'EUR', '0.92000000'],
    ['USDT', 'USD', '1.00000000'],
    ['USDC', 'EUR', '0.92000000'],
    ['USDC', 'USD', '1.00000000'],
];

$pdo->beginTransaction();

$del = $pdo->prepare(
    "DELETE FROM fx_rates_cache
     WHERE environment = 'sandbox' AND source = 'manual'
       AND base_currency = :b AND quote_currency = :q"
);
$ins = $pdo->prepare(
    "INSERT INTO fx_rates_cache
        (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
     VALUES
        (:b, :q, :rate, 0, 'manual', 'sandbox', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))"
);

foreach ($pairs as [$base, $quote, $rate]) {
    $del->execute(['b' => $base, 'q' => $quote]);
    $ins->execute(['b' => $base, 'q' => $quote, 'rate' => $rate]);
}

$pdo->commit();

$check = $pdo->query(
    "SELECT base_currency, quote_currency, rate, expires_at
     FROM fx_rates_cache
     WHERE environment = 'sandbox' AND base_currency = 'EUR' AND quote_currency = 'USD'
       AND expires_at > NOW()
     ORDER BY fetched_at DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

echo "Seed FX sandbox OK — " . count($pairs) . " paires.\n";
if ($check) {
    echo "EUR→USD = {$check['rate']} (expire {$check['expires_at']})\n";
} else {
    fwrite(STDERR, "ATTENTION : EUR→USD introuvable après seed.\n");
    exit(1);
}
