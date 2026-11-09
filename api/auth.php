<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();
startSecureSession();

$action = $_GET['action'] ?? 'session';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'session' && $method === 'GET') {
        if (empty($_SESSION['user_id'])) {
            sendJsonResponse(true, ['authenticated' => false], 'Sign in required.');
        }

        $db = getDBConnection();
        $stmt = $db->prepare('SELECT id, email, display_name, role FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            $_SESSION = [];
            session_destroy();
            sendJsonResponse(true, ['authenticated' => false], 'Sign in required.');
        }

        sendJsonResponse(true, [
            'authenticated' => true,
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token'] ?? ''
        ], 'Session retrieved.');
    }

    if ($action === 'login' && $method === 'POST') {
        requireSameOriginRequest();
        $input = getRequestData();
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '' || strlen($password) > 1024) {
            sendJsonResponse(false, null, 'Invalid email or password.', 401);
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            sendJsonResponse(false, null, 'Unable to verify sign-in request.', 400);
        }

        $db = getDBConnection();
        $db->beginTransaction();
        $attemptStmt = $db->prepare('SELECT attempt_count, window_started_at FROM login_attempts WHERE ip_address = ? FOR UPDATE');
        $attemptStmt->execute([$ipAddress]);
        $attempt = $attemptStmt->fetch();
        $windowExpired = !$attempt || strtotime($attempt['window_started_at']) < time() - 900;
        if (!$windowExpired && (int)$attempt['attempt_count'] >= 10) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Too many sign-in attempts. Try again in 15 minutes.', 429);
        }

        $userStmt = $db->prepare('SELECT id, email, display_name, role, password_hash FROM users WHERE email = ? AND is_active = 1');
        $userStmt->execute([$email]);
        $user = $userStmt->fetch();
        $passwordMatches = $user ? password_verify($password, $user['password_hash']) : password_verify(
            $password,
            '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.'
        );

        if (!$user || !$passwordMatches) {
            if ($windowExpired) {
                $upsert = $db->prepare("
                    INSERT INTO login_attempts (ip_address, attempt_count, window_started_at)
                    VALUES (?, 1, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE attempt_count = 1, window_started_at = UTC_TIMESTAMP()
                ");
                $upsert->execute([$ipAddress]);
            } else {
                $increment = $db->prepare('UPDATE login_attempts SET attempt_count = attempt_count + 1 WHERE ip_address = ?');
                $increment->execute([$ipAddress]);
            }
            $db->commit();
            sendJsonResponse(false, null, 'Invalid email or password.', 401);
        }

        $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ipAddress]);
        $db->commit();

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate the session identifier.');
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        sendJsonResponse(true, [
            'authenticated' => true,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'role' => $user['role']
            ],
            'csrf_token' => $_SESSION['csrf_token']
        ], 'Signed in successfully.');
    }

    if ($action === 'logout' && $method === 'POST') {
        requireSameOriginRequest();
        $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if ($providedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
            sendJsonResponse(false, null, 'Request verification failed.', 403);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict'
            ]);
        }
        session_destroy();
        sendJsonResponse(true, ['authenticated' => false], 'Signed out.');
    }

    sendJsonResponse(false, null, 'Method or authentication action not allowed.', 405);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logSystemError('api/auth.php', $e);
    sendJsonResponse(false, null, 'Authentication service is unavailable.', 500);
}
