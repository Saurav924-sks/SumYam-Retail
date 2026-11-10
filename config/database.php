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

function isProductionRuntime(): bool {
    return getenv('APP_ENV') === 'production' || getenv('VERCEL_ENV') === 'production';
}

function getDatabaseTimeoutOptions(): array {
    return [
        PDO::ATTR_TIMEOUT => 5
    ];
}

function configureDatabaseReadTimeout(): void {
    if ((int)ini_get('mysqlnd.net_read_timeout') !== 10) {
        $timeoutSet = ini_set('mysqlnd.net_read_timeout', '10');
        if ($timeoutSet === false || (int)ini_get('mysqlnd.net_read_timeout') !== 10) {
            throw new RuntimeException('Database timeout configuration is unavailable.');
        }
    }
}

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
        $production = isProductionRuntime();
        $requiredEnvironment = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        if ($production) {
            foreach ($requiredEnvironment as $name) {
                $value = getenv($name);
                if (!is_string($value) || trim($value) === '') {
                    throw new RuntimeException('Database service configuration is unavailable.');
                }
            }
        }

        if (DB_USER === '' || DB_PASS === '') {
            throw new RuntimeException('Database credentials are not configured.');
        }

        configureDatabaseReadTimeout();
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = getDatabaseTimeoutOptions() + [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        $caPem = getenv('DB_SSL_CA_PEM');
        $caPath = null;
        if ($production && (!is_string($caPem) || trim($caPem) === '')) {
            throw new RuntimeException('Database TLS configuration is unavailable.');
        }

        if (is_string($caPem) && trim($caPem) !== '') {
            if (!str_contains($caPem, '-----BEGIN CERTIFICATE-----')
                || !str_contains($caPem, '-----END CERTIFICATE-----')) {
                throw new RuntimeException('Database TLS configuration is invalid.');
            }

            $caPath = tempnam(sys_get_temp_dir(), 'sumyam-db-ca-');
            if ($caPath === false) {
                throw new RuntimeException('Database TLS configuration is unavailable.');
            }
            @chmod($caPath, 0600);
            if (file_put_contents($caPath, $caPem, LOCK_EX) === false) {
                @unlink($caPath);
                throw new RuntimeException('Database TLS configuration is unavailable.');
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } finally {
            if ($caPath !== null) {
                @unlink($caPath);
            }
        }
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
        if (function_exists('logSystemError')) {
            logSystemError('database connectivity', $e);
        }
        return [
            'connected' => false,
            'error'     => 'Database connection failed.'
        ];
    }
}
