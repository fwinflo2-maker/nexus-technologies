<?php

declare(strict_types=1);

/**
 * Crée (ou recrée) la base `nexus_test` avec schema.sql + migrations.manifest.
 *
 * Usage :
 *   php scripts/setup_test_db.php
 *   DB_USER=nexus DB_PASS=secret php scripts/setup_test_db.php
 */

$repoRoot = dirname(__DIR__, 3);
$sqlRoot  = $repoRoot . '/sql';

$host   = getenv('DB_HOST') ?: '127.0.0.1';
$port   = (int) (getenv('DB_PORT') ?: 3306);
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS');
$pass   = $pass === false ? '' : $pass;
$testDb = getenv('DB_TEST_NAME') ?: 'nexus_test';
$devDb  = getenv('DB_NAME') ?: 'nexus';

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

function connect(string $host, int $port, string $user, string $pass, array $opt, ?string $db = null): PDO
{
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if ($db !== null) {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    }

    return new PDO($dsn, $user, $pass, $opt);
}

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
    $root = connect($host, $port, $user, $pass, $pdoOptions);
    $root->exec("DROP DATABASE IF EXISTS `{$testDb}`");
    $root->exec(
        "CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    echo "Base `{$testDb}` recréée.\n";

    $manifest = $sqlRoot . '/migrations.manifest';
    if (!is_file($manifest)) {
        fwrite(STDERR, "Manifeste introuvable : {$manifest}\n");
        exit(1);
    }

    $files = [$sqlRoot . '/schema.sql'];
    foreach (file($manifest, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
        if ($line === '') {
            continue;
        }
        $files[] = $sqlRoot . '/migrations/' . $line;
    }

    $pdo = connect($host, $port, $user, $pass, $pdoOptions, $testDb);

    foreach ($files as $file) {
        if (!is_file($file)) {
            fwrite(STDERR, "Fichier SQL introuvable : {$file}\n");
            exit(1);
        }

        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/CREATE\s+DATABASE.*?;/is', '', $sql) ?? $sql;
        $sql = preg_replace('/USE\s+`?\w+`?\s*;/i', '', $sql) ?? $sql;

        $count = 0;
        foreach (splitStatements($sql) as $statement) {
            $pdo->exec($statement);
            $count++;
        }

        echo '  ' . basename($file) . " — {$count} instruction(s)\n";
    }

    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "\n{$testDb} : " . count($existing) . " table(s)\n";

    $hasTable = in_array('provider_customers', $existing, true);
    echo 'provider_customers : ' . ($hasTable ? 'OK' : 'MANQUANT') . "\n";
} catch (PDOException $e) {
    fwrite(STDERR, 'ERREUR base de données : ' . $e->getMessage() . "\n");
    exit(1);
}
