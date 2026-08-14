<?php

declare(strict_types=1);

/**
 * NEXUS — Audit de contrat SQL ↔ PHP (§4).
 *
 * Extrait les tables et colonnes référencées dans le code PHP, puis les
 * confronte au schéma réellement présent en base. Objectif : détecter les
 * situations du type « le code lit `available_balance` alors que la colonne
 * s'appelle `available` », qui ne se voient qu'à l'exécution.
 *
 * Usage :
 *   DB_USER=nexus DB_PASS=nexus_dev_pw php scripts/sql_contract_audit.php [base]
 *
 * Sortie : rapport texte + code de sortie 1 si au moins une incohérence.
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS');
$pass = $pass === false ? '' : $pass;
$db   = $argv[1] ?? (getenv('DB_NAME') ?: 'nexus');

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ── 1. Schéma réel ──────────────────────────────────────────────────────────
$schema = [];
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
    $schema[$table] = array_column($cols, 'Field');
}

// ── 2. Sources PHP ──────────────────────────────────────────────────────────
$root  = dirname(__DIR__);
$files = [];
$dirs  = [$root . '/src', $root . '/public'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}

/** Mots réservés SQL à ne pas confondre avec des colonnes. */
$reserved = [
    'select', 'from', 'where', 'and', 'or', 'not', 'null', 'as', 'on', 'in', 'is',
    'set', 'values', 'into', 'insert', 'update', 'delete', 'join', 'left', 'right',
    'inner', 'outer', 'group', 'order', 'by', 'having', 'limit', 'offset', 'asc',
    'desc', 'case', 'when', 'then', 'else', 'end', 'count', 'sum', 'avg', 'min',
    'max', 'coalesce', 'distinct', 'union', 'all', 'exists', 'between', 'like',
    'now', 'date_add', 'date_sub', 'interval', 'if', 'ifnull', 'nullif', 'cast',
    'duplicate', 'key', 'default', 'true', 'false', 'for', 'share', 'lock', 'mode',
    'inet6_aton', 'inet6_ntoa', 'json_extract', 'json_unquote', 'unix_timestamp',
    'timestampdiff', 'second', 'minute', 'hour', 'day', 'month', 'year', 'utc_timestamp',
];

$tableRefs  = [];   // table => [fichiers]
$columnRefs = [];   // "table.colonne" => [fichiers]
$problems   = [];

foreach ($files as $file) {
    $code  = (string) file_get_contents($file);
    $short = str_replace($root . '/', '', $file);

    // Les commentaires PHP décrivent souvent du SQL en prose (« SELECT ... FOR
    // UPDATE puis UPDATE dans une transaction ») : les analyser produirait de
    // fausses tables. On les retire via le tokenizer, qui ne confond jamais un
    // commentaire avec du SQL contenu dans une chaîne — contrairement à une
    // regex, qui tronquait ici les requêtes après un `//` intra-chaîne.
    $kept = [];
    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kept[] = $token[1];
        } else {
            $kept[] = $token;
        }
    }
    // Concaténation sans séparateur : insérer une espace couperait les
    // références qualifiées `table.colonne` présentes dans les chaînes SQL.
    $code = implode('', $kept);

    // `ON DUPLICATE KEY UPDATE col = ...` n'introduit pas une table : neutralisé
    // pour éviter de prendre la colonne mise à jour pour un nom de table.
    $code = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', ' ', $code) ?? $code;

    // 2a. Tables citées dans FROM / JOIN / INSERT INTO / UPDATE / DELETE FROM
    preg_match_all(
        // `INSERT IGNORE INTO` doit être reconnu au même titre que `INSERT INTO`,
        // sinon une table écrite uniquement par un INSERT IGNORE est signalée à
        // tort comme « jamais référencée » (faux positif observé sur
        // kyc_webhook_events).
        '/\b(?:FROM|JOIN|INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z_][a-z0-9_]*)`?/i',
        $code,
        $m
    );
    foreach ($m[1] as $t) {
        $t = strtolower($t);
        if (in_array($t, $reserved, true)) {
            continue;
        }
        $tableRefs[$t][$short] = true;
    }

    // 2b. Colonnes explicitement qualifiées (`table.colonne`).
    preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $code, $m2, PREG_SET_ORDER);
    foreach ($m2 as $pair) {
        $t = strtolower($pair[1]);
        $c = strtolower($pair[2]);
        if (!isset($schema[$t]) || in_array($c, $reserved, true)) {
            continue;
        }
        $columnRefs["{$t}.{$c}"][$short] = true;
    }

    // 2c. Colonnes non qualifiées des INSERT : `INSERT INTO t (a, b, c)`.
    // C'est la forme réellement utilisée par le code (le SQL de Nexus qualifie
    // rarement ses colonnes) et celle qui casse le plus visiblement en
    // production : une colonne absente ou NOT NULL oubliée fait échouer
    // l'écriture. C'est exactement le cas rencontré sur `revoked_tokens`.
    preg_match_all(
        '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z_][a-z0-9_]*)`?\s*\(([^)]*)\)/i',
        $code,
        $mi,
        PREG_SET_ORDER
    );
    foreach ($mi as $ins) {
        $t = strtolower($ins[1]);
        if (!isset($schema[$t])) {
            continue;
        }
        foreach (explode(',', $ins[2]) as $rawCol) {
            $c = strtolower(trim($rawCol, " \t\n\r`"));
            if ($c === '' || !preg_match('/^[a-z_][a-z0-9_]*$/', $c)) {
                continue;
            }
            $columnRefs["{$t}.{$c}"][$short] = true;
        }
    }
}

// ── 3. Tables référencées mais absentes ─────────────────────────────────────
foreach ($tableRefs as $table => $where) {
    if (!isset($schema[$table])) {
        $problems[] = [
            'type'    => 'TABLE_MANQUANTE',
            'detail'  => $table,
            'sources' => array_keys($where),
        ];
    }
}

// ── 4. Colonnes qualifiées mais absentes ────────────────────────────────────
foreach ($columnRefs as $ref => $where) {
    [$table, $column] = explode('.', $ref, 2);
    if (isset($schema[$table]) && !in_array($column, $schema[$table], true)) {
        $problems[] = [
            'type'    => 'COLONNE_MANQUANTE',
            'detail'  => $ref,
            'sources' => array_keys($where),
        ];
    }
}

// ── 5. Tables du schéma jamais référencées ──────────────────────────────────
$unused = [];
foreach ($schema as $table => $_) {
    if (!isset($tableRefs[$table])) {
        $unused[] = $table;
    }
}

// ── 6. Rapport ──────────────────────────────────────────────────────────────
echo "AUDIT DE CONTRAT SQL <-> PHP — base `{$db}`\n";
echo str_repeat('=', 64) . "\n\n";
echo 'Tables en base        : ' . count($schema) . "\n";
echo 'Tables référencées    : ' . count($tableRefs) . "\n";
echo 'Colonnes qualifiées   : ' . count($columnRefs) . "\n";
echo 'Fichiers PHP analysés : ' . count($files) . "\n\n";

if ($problems === []) {
    echo "[PASS] Aucune incohérence : toutes les tables et colonnes référencées existent.\n";
} else {
    echo '[FAIL] ' . count($problems) . " incohérence(s) :\n\n";
    foreach ($problems as $p) {
        echo "  - {$p['type']} : {$p['detail']}\n";
        foreach ($p['sources'] as $s) {
            echo "      dans {$s}\n";
        }
    }
}

if ($unused !== []) {
    echo "\n[INFO] Tables jamais référencées par le code PHP (" . count($unused) . ') : '
        . implode(', ', $unused) . "\n";
}

echo "\n";
exit($problems === [] ? 0 : 1);
