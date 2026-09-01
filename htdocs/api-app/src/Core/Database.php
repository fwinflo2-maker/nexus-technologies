<?php

declare(strict_types=1);

namespace Nexus\Core;

use PDO;
use PDOException;

/**
 * Accès à la base de données MySQL via un singleton PDO.
 *
 * Une seule connexion est partagée par requête HTTP (une instance par process).
 */
final class Database
{
    private static ?PDO $pdo = null;

    private function __construct()
    {
        // Classe utilitaire : pas d'instanciation directe.
    }

    /** Réinitialise le singleton PDO (tests uniquement). */
    public static function resetConnection(): void
    {
        self::$pdo = null;
    }

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                // UTC partout : MySQL, PHP et le frontend utilisent la même référence.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        }

        return self::$pdo;
    }

    /** Vérifie que la connexion à la base est opérationnelle. */
    public static function ping(): bool
    {
        try {
            self::getConnection()->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
