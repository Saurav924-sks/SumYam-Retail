<?php
/**
 * SumYam Retail - API Response & Request Helpers
 * Maintainer: Saurav Shah Teli
 */

// The browser app is served from the same origin; cross-origin access is not enabled.
function setApiHeaders(): void {
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Sends a standardized JSON response and exits.
 *
 * @param bool $success Whether the operation was successful
 * @param mixed $data Payload to return
 * @param string $message Friendly user-facing message
 * @param int $statusCode HTTP status code (200, 201, 400, 404, 409, 500)
 */
function sendJsonResponse(bool $success, $data = null, string $message = '', int $statusCode = 200): void {
    setApiHeaders();
    http_response_code($statusCode);

    $response = [
        'success'   => $success,
        'message'   => $message,
        'data'      => $data,
        'timestamp' => date('c')
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Safely parses the incoming JSON or form-encoded request body.
 *
 * @return array
 */
function getRequestData(): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        return $_GET;
    }

    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        sendJsonResponse(false, null, 'Request body must be a valid JSON object.', 400);
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}

/**
 * Sanitizes a string or recursively sanitizes an array.
 *
 * @param mixed $input
 * @return mixed
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    if (is_string($input)) {
        return trim(strip_tags($input));
    }
    return $input;
}

/**
 * Logs private technical errors to a server-side log file.
 *
 * @param string $context
 * @param Throwable $e
 */
function logSystemError(string $context, Throwable $e): void {
    if (function_exists('isProductionRuntime') && isProductionRuntime()) {
        error_log(sprintf('[%s] [%s] %s', gmdate('c'), $context, get_class($e)));
        return;
    }

    $logDir = getenv('SMARTSTORE_LOG_DIR') ?: sys_get_temp_dir() . '/smartstore-logs';
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0700, true) && !is_dir($logDir)) {
            error_log(sprintf('[%s] [%s] %s', gmdate('c'), $context, $e->getMessage()));
            return;
        }
    }
    $logFile = $logDir . '/error_' . gmdate('Y-m-d') . '.log';
    $message = sprintf(
        "[%s] [%s] %s in %s:%d\nStack Trace:\n%s\n\n",
        gmdate('Y-m-d H:i:s'),
        $context,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    if (file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX) === false) {
        error_log(sprintf('[%s] [%s] %s', gmdate('c'), $context, $e->getMessage()));
    }
}

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $production = function_exists('isProductionRuntime') && isProductionRuntime();
    if ($production) {
        if (getCanonicalAppOrigin() === null) {
            throw new RuntimeException('Application origin configuration is unavailable.');
        }
        configureRedisSessionHandler();
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('smartstore_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $production || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    if (!@session_start()) {
        throw new RuntimeException('Session service is unavailable.');
    }
}

/**
 * Configures PHP's native phpredis session handler from a TLS Redis URL.
 * The phpredis handler provides session locking when explicitly enabled.
 */
function configureRedisSessionHandler(): void {
    $redisUrl = getenv('REDIS_URL');
    if (!is_string($redisUrl) || trim($redisUrl) === '' || !extension_loaded('redis')) {
        throw new RuntimeException('Session service is unavailable.');
    }

    $parts = parse_url($redisUrl);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'rediss'
        || empty($parts['host']) || empty($parts['port']) || !isset($parts['pass'])
        || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Session service configuration is invalid.');
    }

    $host = $parts['host'];
    $port = (int)$parts['port'];
    $password = rawurldecode($parts['pass']);
    $username = isset($parts['user']) ? rawurldecode($parts['user']) : '';
    if ($port < 1 || $port > 65535 || $password === '' || str_contains($host, "\n") || str_contains($host, "\r")) {
        throw new RuntimeException('Session service configuration is invalid.');
    }

    $query = [
        'prefix' => 'sumyam_retail:session:',
        'timeout' => '1.5',
        'read_timeout' => '2.0',
        'persistent' => '0',
        'stream' => [
            'verify_peer' => '1',
            'verify_peer_name' => '1',
            'peer_name' => $host
        ]
    ];
    $query['auth'] = $username === '' ? $password : [$username, $password];
    $savePath = 'tls://' . $host . ':' . $port . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC1738);

    if (ini_set('session.save_handler', 'redis') === false
        || ini_set('session.save_path', $savePath) === false
        || ini_set('redis.session.locking_enabled', '1') === false
        || ini_set('redis.session.lock_expire', '60') === false
        || ini_set('redis.session.lock_wait_time', '50000') === false
        || ini_set('redis.session.lock_retries', '60') === false) {
        throw new RuntimeException('Session service configuration is unavailable.');
    }
}

/**
 * Returns the configured canonical origin, using Vercel's deployment URL for previews.
 */
function getCanonicalAppOrigin(): ?string {
    if (getenv('VERCEL') === '1' && getenv('VERCEL_ENV') === 'preview') {
        $deploymentHost = getenv('VERCEL_URL');
        if (!is_string($deploymentHost)
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+vercel\.app$/iD', $deploymentHost)) {
            return null;
        }
        return 'https://' . strtolower($deploymentHost);
    }

    $appBaseUrl = getenv('APP_BASE_URL');
    $parts = is_string($appBaseUrl) ? parse_url($appBaseUrl) : false;
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))) {
        return null;
    }

    $port = $parts['port'] ?? null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }
    $origin = 'https://' . strtolower($parts['host']);
    if ($port !== null && $port !== 443) $origin .= ':' . $port;
    return $origin;
}

function normalizeHttpOrigin(string $url): ?string {
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = $parts['port'] ?? null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }

    $origin = $scheme . '://' . $host;
    if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $origin .= ':' . $port;
    }
    return $origin;
}

/**
 * Resolves the IP used for login throttling without trusting client-supplied proxy chains.
 */
function resolveLoginThrottleIp(): ?string {
    if (getenv('VERCEL') === '1') {
        $forwardedIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwardedIp)) {
            return null;
        }

        $forwardedIp = trim($forwardedIp);
        if ($forwardedIp === '' || str_contains($forwardedIp, ',')
            || filter_var($forwardedIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $forwardedIp;
    }

    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($remoteIp) && filter_var($remoteIp, FILTER_VALIDATE_IP) !== false ? $remoteIp : null;
}

function getSameOriginRequestError(): ?string {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        $origin = $_SERVER['HTTP_REFERER'] ?? '';
    }

    $originParts = parse_url($origin);
    if (isProductionRuntime()) {
        $canonicalOrigin = getCanonicalAppOrigin();
        $requestOrigin = normalizeHttpOrigin($origin);
        if ($canonicalOrigin === null || $requestOrigin === null) {
            return 'Request origin could not be verified.';
        }
        if (!hash_equals($canonicalOrigin, $requestOrigin)) {
            return 'Cross-origin requests are not allowed.';
        }
        return null;
    }

    $requestHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $originHost = is_array($originParts) ? strtolower($originParts['host'] ?? '') : '';
    $originPort = is_array($originParts) ? ($originParts['port'] ?? null) : null;
    $originScheme = is_array($originParts) ? ($originParts['scheme'] ?? '') : '';
    $requestScheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';

    if ($originHost === '' || $requestHost === '' || $originHost . ($originPort ? ':' . $originPort : '') !== $requestHost
        || $originScheme !== $requestScheme) {
        return 'Cross-origin requests are not allowed.';
    }
    return null;
}

function requireSameOriginRequest(): void {
    $error = getSameOriginRequestError();
    if ($error !== null) {
        sendJsonResponse(false, null, $error, 403);
    }
}

function requireApiRole(PDO $db, array $allowedRoles = ['admin', 'staff']): array {
    startSecureSession();

    if (empty($_SESSION['user_id'])) {
        sendJsonResponse(false, null, 'Authentication required.', 401);
    }

    $stmt = $db->prepare('SELECT id, email, display_name, role FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $_SESSION = [];
        session_destroy();
        sendJsonResponse(false, null, 'Authentication required.', 401);
    }

    if (!in_array($user['role'], $allowedRoles, true)) {
        sendJsonResponse(false, null, 'You are not authorized to perform this operation.', 403);
    }

    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        requireSameOriginRequest();
        $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if ($providedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
            sendJsonResponse(false, null, 'Request verification failed. Reload the page and try again.', 403);
        }
    }

    return $user;
}
