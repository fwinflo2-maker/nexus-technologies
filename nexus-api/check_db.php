<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=nexus;charset=utf8mb4', 'root', '');
$stmt = $pdo->query('DESCRIBE users');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . "\n";
}
