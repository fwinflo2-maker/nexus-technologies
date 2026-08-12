<?php
$MYSQL = 'C:\xampp\mysql\bin\mysql.exe';

// Test sans mot de passe
$cmd = "\"$MYSQL\" -u root -e \"SELECT 1 as test;\"";
exec($cmd, $out, $ret);
echo "Test sans mdp: ret=$ret\n";
echo implode("\n", $out) . "\n";

// Test avec -p (vide)
$cmd2 = "\"$MYSQL\" -u root -p -e \"SELECT 1 as test;\"";
exec($cmd2, $out2, $ret2);
echo "Test avec -p: ret=$ret2\n";
echo implode("\n", $out2) . "\n";
