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

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('smartstore_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

function requireSameOriginRequest(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        $origin = $_SERVER['HTTP_REFERER'] ?? '';
    }

    $originParts = parse_url($origin);
    $requestHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $originHost = is_array($originParts) ? strtolower($originParts['host'] ?? '') : '';
    $originPort = is_array($originParts) ? ($originParts['port'] ?? null) : null;
    $originScheme = is_array($originParts) ? ($originParts['scheme'] ?? '') : '';
    $requestScheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';

    if ($originHost === '' || $requestHost === '' || $originHost . ($originPort ? ':' . $originPort : '') !== $requestHost
        || $originScheme !== $requestScheme) {
        sendJsonResponse(false, null, 'Cross-origin requests are not allowed.', 403);
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
