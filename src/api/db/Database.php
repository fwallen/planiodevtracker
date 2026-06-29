<?php
declare(strict_types=1);

namespace App\db;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $env = fn(string $k, string $d) => $_ENV[$k] ?? getenv($k) ?: $d;
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $env('DB_HOST', 'mysql'),
                $env('DB_PORT', '3306'),
                $env('DB_NAME', 'devtracker')
            );
            self::$instance = new PDO($dsn, $env('DB_USER', 'devtracker'), $env('DB_PASS', 'devtracker'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }
}
