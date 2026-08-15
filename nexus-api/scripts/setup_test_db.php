<?php

declare(strict_types=1);

/**
 * Crée (ou recrée) la base `nexus_test` avec toutes les tables nécessaires.
 *
 * Portable Windows / Linux / macOS : le script n'appelle plus le binaire
 * `mysql.exe` de XAMPP — tout passe par PDO. La connexion est configurable
 * par variables d'environnement (mêmes clés que `.env` / `phpunit.xml`) :
 *
 *   DB_HOST (127.0.0.1)  DB_PORT (3306)  DB_USER (root)  DB_PASS ('')
 *   DB_TEST_NAME (nexus_test)  DB_NAME (nexus, base de dev vérifiée en fin)
 *
 * Usage :  php scripts/setup_test_db.php
 *          DB_USER=nexus DB_PASS=secret php scripts/setup_test_db.php
 */

$host    = getenv('DB_HOST') ?: '127.0.0.1';
$port    = (int) (getenv('DB_PORT') ?: 3306);
$user    = getenv('DB_USER') ?: 'root';
$pass    = getenv('DB_PASS');
$pass    = $pass === false ? '' : $pass;
$testDb  = getenv('DB_TEST_NAME') ?: 'nexus_test';
$devDb   = getenv('DB_NAME') ?: 'nexus';

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

/** Ouvre une connexion PDO, avec ou sans base sélectionnée. */
function connect(string $host, int $port, string $user, string $pass, array $opt, ?string $db = null): PDO
{
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if ($db !== null) {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    }

    return new PDO($dsn, $user, $pass, $opt);
}

/**
 * Découpe un script SQL en instructions exécutables.
 *
 * Gère les commentaires (`--`, `#`, `/* *\/`), les chaînes quotées et les
 * blocs `DELIMITER` absents ici mais neutralisés par prudence.
 */
function splitStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $inSingle   = false;
    $inDouble   = false;
    $inBacktick = false;
    $inLineCmt  = false;
    $inBlockCmt = false;

    for ($i = 0; $i < $len; $i++) {
        $c    = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineCmt) {
            if ($c === "\n") {
                $inLineCmt = false;
                $current .= $c;
            }
            continue;
        }

        if ($inBlockCmt) {
            if ($c === '*' && $next === '/') {
                $inBlockCmt = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if (($c === '-' && $next === '-') || $c === '#') {
                $inLineCmt = true;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $inBlockCmt = true;
                $i++;
                continue;
            }
        }

        if ($c === "'" && !$inDouble && !$inBacktick) {
            // Antislash d'échappement ?
            $backslashes = 0;
            for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                $backslashes++;
            }
            if ($backslashes % 2 === 0) {
                $inSingle = !$inSingle;
            }
        } elseif ($c === '"' && !$inSingle && !$inBacktick) {
            $backslashes = 0;
            for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                $backslashes++;
            }
            if ($backslashes % 2 === 0) {
                $inDouble = !$inDouble;
            }
        } elseif ($c === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($c === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $c;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

try {
    // 1. Drop + Create de la base de test.
    $root = connect($host, $port, $user, $pass, $pdoOptions);
    $root->exec("DROP DATABASE IF EXISTS `{$testDb}`");
    $root->exec(
        "CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    echo "Base `{$testDb}` recréée.\n";

    // 2. Application du schéma puis des migrations, dans l'ordre.
    // Liste lue depuis database/migrations.manifest — SOURCE DE VÉRITÉ UNIQUE.
    // Une liste codée en dur ici ferait tourner les tests sur un schéma périmé
    // (et donc verdir des tests qui devraient échouer).
    $manifest = dirname(__DIR__) . '/database/migrations.manifest';
    if (!is_file($manifest)) {
        fwrite(STDERR, "Manifeste de migrations introuvable : {$manifest}\n");
        exit(1);
    }
    $files = [dirname(__DIR__) . '/database/schema.sql'];
    foreach (file($manifest, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
        if ($line === '') {
            continue;
        }
        $files[] = dirname(__DIR__) . '/database/migrations/' . $line;
    }

    $pdo = connect($host, $port, $user, $pass, $pdoOptions, $testDb);

    foreach ($files as $file) {
        if (!is_file($file)) {
            fwrite(STDERR, "Fichier SQL introuvable : {$file}\n");
            exit(1);
        }

        $sql = (string) file_get_contents($file);

        // Les scripts ciblent la base `nexus` : on neutralise CREATE DATABASE / USE
        // pour que tout s'applique dans la base de test déjà sélectionnée.
        $sql = preg_replace('/CREATE\s+DATABASE.*?;/is', '', $sql) ?? $sql;
        $sql = preg_replace('/USE\s+`?\w+`?\s*;/i', '', $sql) ?? $sql;

        $count = 0;
        foreach (splitStatements($sql) as $statement) {
            $pdo->exec($statement);
            $count++;
        }

        echo '  ' . basename($file) . " — {$count} instruction(s)\n";
    }

    // 3. Vérifications.
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "\n{$testDb} : " . count($existing) . ' table(s) — ' . implode(', ', $existing) . "\n";

    $col = $pdo->query("SHOW COLUMNS FROM wallets LIKE 'hold_balance'")->fetch();
    echo 'wallets.hold_balance : ' . ($col ? 'OK' : 'MANQUANT') . "\n";

    $rates = (int) $pdo->query('SELECT COUNT(*) FROM fx_rates_cache')->fetchColumn();
    echo "fx_rates_cache : {$rates} taux\n";

    // 4. La base de dev ne doit pas avoir été touchée.
    try {
        $pdoDev     = connect($host, $port, $user, $pass, $pdoOptions, $devDb);
        $devUsers   = (int) $pdoDev->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $devWallets = (int) $pdoDev->query('SELECT COUNT(*) FROM wallets')->fetchColumn();
        echo "\n{$devDb} (dev) intacte : users={$devUsers}, wallets={$devWallets}\n";
    } catch (PDOException $e) {
        echo "\n{$devDb} (dev) non disponible — vérification ignorée.\n";
    }
} catch (PDOException $e) {
    fwrite(STDERR, 'ERREUR base de données : ' . $e->getMessage() . "\n");
    exit(1);
}
