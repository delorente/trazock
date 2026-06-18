<?php
declare(strict_types=1);

namespace Trazock;

use PDO;
use PDOException;
use RuntimeException;

final class DB
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public function __wakeup(): void
    {
        throw new RuntimeException('DB instance cannot be unserialized.');
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
                throw new RuntimeException(
                    'DB constants not loaded. Require config/config.php before calling DB::getInstance().'
                );
            }

            $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
            $host    = defined('DB_PORT')
                ? DB_HOST . ';port=' . DB_PORT
                : DB_HOST;

            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, DB_NAME, $charset);

            // PDOException propagates on connection failure — never swallowed.
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        }

        return self::$instance;
    }
}
