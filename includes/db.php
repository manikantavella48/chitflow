<?php
require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        } else {
            try {
                self::$instance->query('SELECT 1');
            } catch (Throwable $e) {
                self::$instance = self::createConnection();
            }
        }
        return self::$instance;
    }

    private static function createConnection(): PDO {
        $port = defined('DB_PORT') && DB_PORT ? ';port=' . DB_PORT : '';
        $dsn = 'mysql:host=' . DB_HOST . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}

function db(): PDO {
    return Database::getConnection();
}
