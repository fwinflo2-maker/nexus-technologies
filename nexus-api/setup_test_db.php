<?php
// Crée la base nexus_test avec toutes les tables nécessaires.
// Retire les lignes CREATE DATABASE / USE de chaque fichier SQL pour
// qu'elles s'exécutent bien dans nexus_test.

// Charger les variables d'environnement si disponibles
if (is_file(__DIR__ . '/config/env.php')) {
    require __DIR__ . '/config/env.php';
}

function findMysqlBinary(): string {
    // 1. Vérifier si MYSQL_BIN est défini dans l'environnement
    $envBin = getenv('MYSQL_BIN');
    if ($envBin !== false && $envBin !== '') {
        return $envBin;
    }

    // 2. Tenter de détecter mysql dans le PATH
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $checkCmd = $isWin ? 'where mysql' : 'which mysql';
    $output = [];
    $returnVar = 1;
    @exec($checkCmd, $output, $returnVar);
    if ($returnVar === 0 && !empty($output)) {
        return trim($output[0]);
    }

    // 3. Fallback Windows XAMPP
    if ($isWin) {
        $xamppPath = 'C:\\xampp\\mysql\\bin\\mysql.exe';
        if (is_file($xamppPath)) {
            return $xamppPath;
        }
    }

    // 4. Fallback par défaut
    return 'mysql';
}

$MYSQL = findMysqlBinary();

$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';

// Options pour la ligne de commande mysql
$mysqlOpts = "-u " . escapeshellarg($dbUser);
if ($dbPass !== '') {
    $mysqlOpts .= " -p" . escapeshellarg($dbPass);
}
if ($dbHost !== '127.0.0.1' && $dbHost !== 'localhost') {
    $mysqlOpts .= " -h " . escapeshellarg($dbHost);
}
if ($dbPort !== '3306') {
    $mysqlOpts .= " -P " . escapeshellarg($dbPort);
}

echo "Utilisation de l'exécutable mysql : $MYSQL\n";

// 1. Drop + Create
$dropCreateCmd = "\"$MYSQL\" $mysqlOpts -e \"DROP DATABASE IF EXISTS nexus_test; CREATE DATABASE nexus_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\" 2>&1";
exec($dropCreateCmd, $outCreate, $retCreate);
if ($retCreate !== 0) {
    echo "Erreur lors de la création de la base de données :\n" . implode("\n", $outCreate) . "\n";
    exit(1);
}

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
    if (!is_file($file)) {
        echo "Fichier de migration manquant : $file\n";
        exit(1);
    }
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

$cmd = "\"$MYSQL\" $mysqlOpts nexus_test < " . escapeshellarg($tmpFile) . " 2>&1";
exec($cmd, $out, $ret);

if ($ret !== 0) {
    echo "ERREUR mysql d'importation :\n" . implode("\n", $out) . "\n";
    unlink($tmpFile);
    exit(1);
}

unlink($tmpFile);

// 4. Vérification
try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=nexus_test;charset=utf8mb4", $dbUser, $dbPass);
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "nexus_test tables (" . count($existing) . "): " . implode(', ', $existing) . "\n";

    $col = $pdo->query("SHOW COLUMNS FROM wallets LIKE 'hold_balance'")->fetch();
    echo "wallets.hold_balance: " . ($col ? 'OK' : 'MISSING') . "\n";

    $rates = (int) $pdo->query('SELECT COUNT(*) FROM fx_rates_cache')->fetchColumn();
    echo "fx_rates_cache: $rates rates\n";
} catch (PDOException $e) {
    echo "Erreur lors de la vérification de nexus_test : " . $e->getMessage() . "\n";
    exit(1);
}

// 5. Vérifier si la base de dev existe et n'est pas touchée
try {
    $pdo_dev = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=nexus;charset=utf8mb4", $dbUser, $dbPass);
    $devUsers = (int) $pdo_dev->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $devWallets = (int) $pdo_dev->query('SELECT COUNT(*) FROM wallets')->fetchColumn();
    echo "\nnexus (dev) intact: users=$devUsers, wallets=$devWallets\n";
} catch (PDOException $e) {
    echo "\nNote: base 'nexus' de dev non disponible ou non vérifiable (" . $e->getMessage() . ").\n";
}
