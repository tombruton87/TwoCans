<?php
declare(strict_types=1);

/**
 * PDO connection, configured once.
 *
 * Real prepared statements (no emulation), exceptions on error, associative
 * fetches. Connects lazily so pages that touch no data pay nothing.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = getenv('DB_HOST') ?: 'mariadb';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'twocans';
        $user = getenv('DB_USER') ?: 'twocans';
        $pass = getenv('DB_PASSWORD') ?: '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return self::$pdo;
    }

    /** True if the database is reachable — used to show a useful error page. */
    public static function isAvailable(): bool
    {
        try {
            self::pdo()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
