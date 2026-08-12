<?php
// Vérifier quelles tables ont été créées dans nexus (vs nexus_test)
$pdo_dev = new PDO('mysql:host=127.0.0.1;port=3306;dbname=nexus;charset=utf8mb4', 'root', '');
$tables_dev = $pdo_dev->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "nexus tables: " . count($tables_dev) . "\n";

$pdo_test = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '');
$dbs = $pdo_test->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
echo "Databases: " . implode(', ', $dbs) . "\n";
