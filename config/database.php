<?php
/**
 * SumYam Retail - Database Configuration & Connection
 * Maintainer: Saurav Shah Teli
 */

// Connection credentials must be supplied by the deployment environment.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'smartstore_db');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

$appTimezone = getenv('SMARTSTORE_TIMEZONE') ?: 'UTC';
try {
    new DateTimeZone($appTimezone);
} catch (Exception $e) {
    throw new RuntimeException('SMARTSTORE_TIMEZONE must be a valid timezone identifier.');
}
date_default_timezone_set($appTimezone);

/**
 * Returns a configured PDO database connection instance.
 * @return PDO
 * @throws PDOException
 */
function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        if (DB_USER === '' || DB_PASS === '') {
            throw new RuntimeException('Database credentials are not configured.');
        }

        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+00:00'");
    }

    return $pdo;
}

/**
 * Checks connection health and returns status array.
 * @return array
 */
function testDBConnection(): array {
    try {
        $db = getDBConnection();
        $stmt = $db->query("SELECT VERSION() AS db_version, DATABASE() AS current_db, NOW() AS server_time");
        $info = $stmt->fetch();
        return [
            'connected'   => true,
            'database'    => $info['current_db'] ?? DB_NAME,
            'version'     => $info['db_version'] ?? 'Unknown',
            'server_time' => $info['server_time'] ?? gmdate('Y-m-d H:i:s'),
            'host'        => DB_HOST . ':' . DB_PORT
        ];
    } catch (Throwable $e) {
        return [
            'connected' => false,
            'error'     => 'Database connection failed.'
        ];
    }
}
