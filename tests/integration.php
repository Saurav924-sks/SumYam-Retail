<?php
/**
 * Safe HTTP integration suite for SmartStore.
 *
 * This runner only operates when the configured and connected database is
 * exactly `smartstore_test`. It starts its own PHP HTTP server with an
 * isolated session directory, uses the production authentication/CSRF/role
 * paths, and removes only rows created by its unique test run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (getenv('DB_NAME') !== 'smartstore_test') {
    fwrite(STDERR, "REFUSED: DB_NAME must be exactly smartstore_test; no database or API requests were made.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';

$requiredTables = [
    'users', 'login_attempts', 'categories', 'products', 'customers', 'suppliers',
    'sales', 'sale_items', 'purchases', 'purchase_items', 'stock_movements'
];

$passed = 0;
$failed = 0;
$skipped = 0;
$failureDetails = [];
$db = null;
$server = null;
$sessionDir = null;
$adminId = null;
$staffId = null;
$customerId = null;
$supplierId = null;
$productIds = [];
$savedLoginAttempt = null;
$fixturesCreated = false;
$loginIp = '127.0.0.1';
$lockHeld = false;
$serverPort = null;
$runId = bin2hex(random_bytes(8));

function reportTest(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed, $failureDetails;
    if ($ok) {
        echo "[PASS] {$name}\n";
        $passed++;
        return;
    }
    $detail = $detail !== '' ? $detail : 'condition was false';
    echo "[FAIL] {$name}: {$detail}\n";
    $failed++;
    $failureDetails[] = "{$name}: {$detail}";
}

function reportSkip(string $name, string $reason): void {
    global $skipped;
    echo "[SKIP] {$name}: {$reason}\n";
    $skipped++;
}

function makeClient(): array {
    return ['cookie' => null, 'csrf' => null];
}

function httpRequest(array &$client, string $method, string $path, ?array $body = null, bool $withCsrf = true): array {
    global $serverPort;
    $origin = "http://127.0.0.1:{$serverPort}";
    $headers = [
        'Accept: application/json',
        'Origin: ' . $origin,
        'Referer: ' . $origin . '/',
        'Connection: close'
    ];
    if ($client['cookie'] !== null && $client['cookie'] !== '') {
        $headers[] = 'Cookie: smartstore_session=' . $client['cookie'];
    }
    if ($withCsrf && $client['csrf']) {
        $headers[] = 'X-CSRF-Token: ' . $client['csrf'];
    }
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 8,
            'protocol_version' => 1.1
        ]
    ];
    if ($body !== null) {
        $options['http']['header'] .= "Content-Type: application/json\r\n";
        $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }

    $context = stream_context_create($options);
    $responseBody = @file_get_contents($origin . '/api/' . ltrim($path, '/'), false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
        if (stripos($header, 'Set-Cookie: smartstore_session=') === 0
            && preg_match('/Set-Cookie:\s*smartstore_session=([^;]*)/i', $header, $match)
            && $match[1] !== '' && strtolower($match[1]) !== 'deleted') {
            $client['cookie'] = $match[1];
        }
    }

    $json = is_string($responseBody) ? json_decode($responseBody, true) : null;
    return [
        'status' => $status,
        'json' => is_array($json) ? $json : [],
        'raw' => is_string($responseBody) ? $responseBody : ''
    ];
}

function uuidV4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function waitForHttpServer(array &$client, int $attempts = 40): bool {
    for ($i = 0; $i < $attempts; $i++) {
        $result = httpRequest($client, 'GET', 'status.php', null, false);
        if ($result['status'] === 200 && ($result['json']['data']['status'] ?? '') === 'healthy') {
            return true;
        }
        usleep(100000);
    }
    return false;
}

function createFixtureData(PDO $db, string $runId): array {
    global $adminId, $staffId, $customerId, $supplierId, $productIds;

    $adminEmail = "admin-{$runId}@test.invalid";
    $staffEmail = "staff-{$runId}@test.invalid";
    $adminPassword = 'TestAdmin-' . bin2hex(random_bytes(16));
    $staffPassword = 'TestStaff-' . bin2hex(random_bytes(16));

    $db->beginTransaction();
    try {
        $insertUser = $db->prepare('INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, ?)');
        $insertUser->execute([$adminEmail, 'Integration Test Admin', password_hash($adminPassword, PASSWORD_DEFAULT), 'admin']);
        $adminId = (int)$db->lastInsertId();
        $insertUser->execute([$staffEmail, 'Integration Test Staff', password_hash($staffPassword, PASSWORD_DEFAULT), 'staff']);
        $staffId = (int)$db->lastInsertId();

        $customer = $db->prepare('INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)');
        $customer->execute(['Integration Test Customer', '9000000001', "customer-{$runId}@test.invalid", 'Isolated integration fixture']);
        $customerId = (int)$db->lastInsertId();

        $supplier = $db->prepare('INSERT INTO suppliers (name, company, phone, email, address) VALUES (?, ?, ?, ?, ?)');
        $supplier->execute(['Integration Test Contact', 'Integration Test Supplier', '9000000002', "supplier-{$runId}@test.invalid", 'Isolated integration fixture']);
        $supplierId = (int)$db->lastInsertId();

        $product = $db->prepare('INSERT INTO products (name, sku, cost_price, selling_price, stock_quantity, min_stock_level) VALUES (?, ?, ?, ?, ?, ?)');
        $product->execute(['Integration Test Item', 'IT-' . strtoupper($runId), 7.00, 20.00, 20, 2]);
        $productIds['standard'] = (int)$db->lastInsertId();
        $product->execute(['Integration Test Overflow Sale', 'IS-' . strtoupper($runId), 1.00, 9999999999.99, 100000, 1]);
        $productIds['sale_overflow'] = (int)$db->lastInsertId();
        $product->execute(['Integration Test Overflow Receipt', 'IR-' . strtoupper($runId), 1.00, 1.00, 4294967295, 1]);
        $productIds['receipt_overflow'] = (int)$db->lastInsertId();

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    return ['admin_email' => $adminEmail, 'admin_password' => $adminPassword, 'staff_email' => $staffEmail, 'staff_password' => $staffPassword];
}

function cleanupFixtures(PDO $db): void {
    global $adminId, $staffId, $customerId, $supplierId, $productIds, $savedLoginAttempt, $loginIp, $fixturesCreated;
    if (!$fixturesCreated) return;

    $productIdList = array_values(array_filter($productIds, static fn($id) => (int)$id > 0));
    $db->beginTransaction();
    try {
        if ($adminId) {
            $db->prepare('DELETE FROM sales WHERE user_id = ?')->execute([$adminId]);
            $db->prepare('DELETE FROM purchases WHERE created_by = ?')->execute([$adminId]);
        }
        if ($productIdList) {
            $marks = implode(',', array_fill(0, count($productIdList), '?'));
            $db->prepare("DELETE FROM stock_movements WHERE product_id IN ({$marks})")->execute($productIdList);
            $db->prepare("DELETE FROM products WHERE id IN ({$marks})")->execute($productIdList);
        }
        if ($customerId) $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        if ($supplierId) $db->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        if ($staffId) $db->prepare('DELETE FROM users WHERE id = ?')->execute([$staffId]);
        if ($adminId) $db->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);

        // Authentication throttling is keyed by loopback IP. Restore the exact
        // pre-run row so the suite leaves no login-attempt fixture behind.
        $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$loginIp]);
        if ($savedLoginAttempt) {
            $restore = $db->prepare('INSERT INTO login_attempts (ip_address, attempt_count, window_started_at) VALUES (?, ?, ?)');
            $restore->execute([$loginIp, $savedLoginAttempt['attempt_count'], $savedLoginAttempt['window_started_at']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function removeIsolatedSessionFiles(?string $directory): void {
    if (!$directory || !is_dir($directory)) return;
    foreach (glob($directory . '/sess_*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    $logDirectory = $directory . '/logs';
    if (is_dir($logDirectory)) {
        foreach (glob($logDirectory . '/*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        @rmdir($logDirectory);
    }
    @rmdir($directory);
}

try {
    $db = getDBConnection();
    $actualDatabase = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDatabase !== 'smartstore_test') {
        fwrite(STDERR, "REFUSED: connected database was not smartstore_test; no fixtures or API requests were made.\n");
        exit(2);
    }

    $tableRows = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missingTables = array_values(array_diff($requiredTables, $tableRows));
    if ($missingTables) {
        fwrite(STDERR, 'REFUSED: apply database/migrations/001_initial_schema.sql to smartstore_test first. Missing: ' . implode(', ', $missingTables) . "\n");
        exit(2);
    }

    $lockHeld = (int)$db->query("SELECT GET_LOCK('smartstore_test.integration', 0)")->fetchColumn() === 1;
    if (!$lockHeld) {
        fwrite(STDERR, "REFUSED: another SmartStore integration run holds the test database lock.\n");
        exit(2);
    }

    $attemptStmt = $db->prepare('SELECT attempt_count, window_started_at FROM login_attempts WHERE ip_address = ?');
    $attemptStmt->execute([$loginIp]);
    $savedLoginAttempt = $attemptStmt->fetch() ?: null;

    $run = createFixtureData($db, $runId);
    $fixturesCreated = true;
    $sqlMode = strtoupper((string)$db->query('SELECT @@GLOBAL.sql_mode')->fetchColumn());
    $strictSqlMode = str_contains($sqlMode, 'STRICT_TRANS_TABLES') || str_contains($sqlMode, 'STRICT_ALL_TABLES');

    $sessionDir = sys_get_temp_dir() . '/smartstore-test-sessions-' . getmypid() . '-' . $runId;
    if (!mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        throw new RuntimeException('Could not create isolated PHP session directory.');
    }

    $portSocket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketErrorMessage);
    if ($portSocket === false) throw new RuntimeException('Could not reserve a local HTTP port.');
    $socketName = stream_socket_get_name($portSocket, false);
    fclose($portSocket);
    $serverPort = (int)substr(strrchr($socketName, ':'), 1);
    if ($serverPort <= 0) throw new RuntimeException('Could not determine the local HTTP port.');

    $serverEnvironment = getenv();
    if (!is_array($serverEnvironment)) $serverEnvironment = [];
    $serverEnvironment['DB_NAME'] = 'smartstore_test';
    $serverEnvironment['SMARTSTORE_LOG_DIR'] = $sessionDir . '/logs';
    $serverCommand = [
        PHP_BINARY,
        '-d', 'session.save_path=' . $sessionDir,
        '-d', 'session.gc_maxlifetime=1440',
        '-d', 'session.gc_probability=1',
        '-d', 'session.gc_divisor=1',
        '-S', '127.0.0.1:' . $serverPort,
        '-t', dirname(__DIR__)
    ];
    $server = proc_open($serverCommand, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['file', '/dev/null', 'a']
    ], $serverPipes, dirname(__DIR__), $serverEnvironment);
    if (!is_resource($server)) throw new RuntimeException('Could not start isolated PHP test server.');

    $anonymous = makeClient();
    if (!waitForHttpServer($anonymous)) throw new RuntimeException('Isolated HTTP server did not connect to smartstore_test.');
    $status = httpRequest($anonymous, 'GET', 'status.php', null, false);
    reportTest('HTTP status API reaches the test database', $status['status'] === 200 && ($status['json']['data']['status'] ?? '') === 'healthy', 'expected HTTP 200 and healthy status');

    $invalidClient = makeClient();
    $invalidLogin = httpRequest($invalidClient, 'POST', 'auth.php?action=login', [
        'email' => 'missing-' . $runId . '@test.invalid', 'password' => 'incorrect-test-password'
    ], false);
    reportTest('Invalid credentials are rejected', $invalidLogin['status'] === 401 && ($invalidLogin['json']['success'] ?? true) === false, 'expected HTTP 401');

    $admin = makeClient();
    $adminLogin = httpRequest($admin, 'POST', 'auth.php?action=login', ['email' => $run['admin_email'], 'password' => $run['admin_password']], false);
    $admin['csrf'] = $adminLogin['json']['data']['csrf_token'] ?? null;
    reportTest('Admin credentials are accepted with a CSRF token', $adminLogin['status'] === 200 && ($adminLogin['json']['data']['authenticated'] ?? false) === true && (bool)$admin['csrf'], 'expected authenticated response and token');

    $session = httpRequest($admin, 'GET', 'auth.php?action=session', null, false);
    reportTest('Authenticated session returns the admin identity', ($session['json']['data']['authenticated'] ?? false) === true && ($session['json']['data']['user']['role'] ?? '') === 'admin', 'session identity/role mismatch');

    $csrfReject = httpRequest($admin, 'POST', 'stock.php', ['id' => 999999999, 'delta' => 1], false);
    reportTest('Missing CSRF token blocks mutations', $csrfReject['status'] === 403, 'expected HTTP 403 before stock lookup');

    $adminWrite = httpRequest($admin, 'POST', 'products.php', ['name' => '', 'sku' => 'INVALID', 'price' => 1], true);
    reportTest('Admin mutation permission passes authorization', $adminWrite['status'] === 400 && ($adminWrite['json']['success'] ?? true) === false, 'expected validation HTTP 400, not an authorization rejection');

    $saleKey = uuidV4();
    $salePayload = [
        'idempotency_key' => $saleKey,
        'customer_id' => $customerId,
        'payment_method' => 'cash',
        'discount' => 10,
        'items' => [
            ['id' => $productIds['standard'], 'qty' => 2, 'price' => 0.01, 'cost_price' => 0.01],
            ['id' => $productIds['standard'], 'qty' => 3, 'price' => 0.01, 'cost_price' => 0.01]
        ]
    ];
    $sale = httpRequest($admin, 'POST', 'sales.php', $salePayload, true);
    reportTest('Checkout succeeds with a customer and duplicate product lines', $sale['status'] === 201 && ($sale['json']['success'] ?? false) === true, 'expected HTTP 201');
    $saleId = (int)($sale['json']['data']['sale_id'] ?? 0);
    $saleRowStmt = $db->prepare('SELECT * FROM sales WHERE id = ? AND user_id = ?');
    $saleRowStmt->execute([$saleId, $adminId]);
    $saleRow = $saleRowStmt->fetch() ?: [];
    $lineStmt = $db->prepare('SELECT quantity, unit_price, subtotal FROM sale_items WHERE sale_id = ?');
    $lineStmt->execute([$saleId]);
    $saleLines = $lineStmt->fetchAll();
    reportTest('Customer association and server-side price are stored correctly', (int)($saleRow['customer_id'] ?? 0) === $customerId && (float)($saleRow['subtotal'] ?? 0) === 100.0 && (float)($saleRow['grand_total'] ?? 0) === 94.5 && count($saleLines) === 1 && (int)$saleLines[0]['quantity'] === 5 && (float)$saleLines[0]['unit_price'] === 20.0, 'expected customer fixture, one aggregated line, database price 20, subtotal 100, total 94.50');

    $stockStmt = $db->prepare('SELECT stock_quantity FROM products WHERE id = ?');
    $stockStmt->execute([$productIds['standard']]);
    $stockAfterSale = (int)$stockStmt->fetchColumn();
    reportTest('Successful sale deducts aggregated quantity once', $stockAfterSale === 15, 'expected stock 20 -> 15');

    $saleMovementStmt = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND user_id = ? AND movement_type = 'SALE' AND quantity = -5 AND previous_stock = 20 AND new_stock = 15");
    $saleMovementStmt->execute([$productIds['standard'], $adminId]);
    reportTest('Sale creates one actor-attributed stock movement', (int)$saleMovementStmt->fetchColumn() === 1, 'expected a single SALE movement by the admin');

    $saleReplay = httpRequest($admin, 'POST', 'sales.php', $salePayload, true);
    $saleCountStmt = $db->prepare('SELECT COUNT(*) FROM sales WHERE user_id = ? AND idempotency_key = ?');
    $saleCountStmt->execute([$adminId, $saleKey]);
    $stockStmt->execute([$productIds['standard']]);
    reportTest('Repeated checkout key replays without duplicate sale or stock change', ($saleReplay['json']['data']['idempotent_replay'] ?? false) === true && (int)$saleCountStmt->fetchColumn() === 1 && (int)$stockStmt->fetchColumn() === 15, 'replay must return the prior sale exactly once');

    $salesBeforeReject = (int)$db->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $movementBeforeReject = (int)$db->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
    $insufficient = httpRequest($admin, 'POST', 'sales.php', [
        'idempotency_key' => uuidV4(), 'customer_id' => $customerId, 'payment_method' => 'cash',
        'items' => [['id' => $productIds['standard'], 'qty' => 1000]]
    ], true);
    $stockStmt->execute([$productIds['standard']]);
    reportTest('Insufficient stock is rejected without side effects', $insufficient['status'] === 400 && (int)$db->query('SELECT COUNT(*) FROM sales')->fetchColumn() === $salesBeforeReject && (int)$db->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === $movementBeforeReject && (int)$stockStmt->fetchColumn() === 15, 'expected HTTP 400 with sale, movement, and stock unchanged');

    if ($strictSqlMode) {
        $salesBeforeRollback = (int)$db->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $overflowStockStmt = $db->prepare('SELECT stock_quantity FROM products WHERE id = ?');
        $overflowStockStmt->execute([$productIds['sale_overflow']]);
        $overflowStockBefore = (int)$overflowStockStmt->fetchColumn();
        $overflowSale = httpRequest($admin, 'POST', 'sales.php', [
            'idempotency_key' => uuidV4(), 'payment_method' => 'cash',
            'items' => [['id' => $productIds['sale_overflow'], 'qty' => 100000]]
        ], true);
        $overflowStockStmt->execute([$productIds['sale_overflow']]);
        reportTest('Failed checkout rolls back its transaction', $overflowSale['status'] === 500 && (int)$db->query('SELECT COUNT(*) FROM sales')->fetchColumn() === $salesBeforeRollback && (int)$overflowStockStmt->fetchColumn() === $overflowStockBefore, 'expected strict DECIMAL overflow HTTP 500 with no sale or stock change');
    } else {
        reportSkip('Failed checkout transaction rollback', 'MySQL global SQL mode is not strict; avoiding a potentially clamped large-value write.');
    }

    $purchaseKey = uuidV4();
    $purchasePayload = [
        'idempotency_key' => $purchaseKey,
        'supplier_id' => $supplierId,
        'notes' => 'Integration test fixture',
        'items' => [
            ['product_id' => $productIds['standard'], 'quantity' => 2, 'unit_cost' => 4.50],
            ['product_id' => $productIds['standard'], 'quantity' => 3, 'unit_cost' => 4.50]
        ]
    ];
    $purchase = httpRequest($admin, 'POST', 'purchases.php', $purchasePayload, true);
    $purchaseId = (int)($purchase['json']['data']['id'] ?? 0);
    $purchaseItemStmt = $db->prepare('SELECT quantity, received_quantity FROM purchase_items WHERE purchase_id = ?');
    $purchaseItemStmt->execute([$purchaseId]);
    $purchaseItems = $purchaseItemStmt->fetchAll();
    $stockStmt->execute([$productIds['standard']]);
    reportTest('Purchase order creation aggregates duplicate lines and leaves stock unchanged', $purchase['status'] === 201 && ($purchase['json']['data']['status'] ?? '') === 'ordered' && count($purchaseItems) === 1 && (int)$purchaseItems[0]['quantity'] === 5 && (int)$purchaseItems[0]['received_quantity'] === 0 && (int)$stockStmt->fetchColumn() === 15, 'expected ordered PO, one quantity-5 item, and unchanged stock 15');

    $purchaseReplay = httpRequest($admin, 'POST', 'purchases.php', $purchasePayload, true);
    $purchaseCountStmt = $db->prepare('SELECT COUNT(*) FROM purchases WHERE created_by = ? AND idempotency_key = ?');
    $purchaseCountStmt->execute([$adminId, $purchaseKey]);
    reportTest('Repeated purchase idempotency key does not create a second PO', ($purchaseReplay['json']['data']['idempotent_replay'] ?? false) === true && (int)$purchaseCountStmt->fetchColumn() === 1, 'expected exactly one PO for the key');

    $staff = makeClient();
    $staffLogin = httpRequest($staff, 'POST', 'auth.php?action=login', ['email' => $run['staff_email'], 'password' => $run['staff_password']], false);
    $staff['csrf'] = $staffLogin['json']['data']['csrf_token'] ?? null;
    reportTest('Staff credentials are accepted', $staffLogin['status'] === 200 && ($staffLogin['json']['data']['user']['role'] ?? '') === 'staff' && (bool)$staff['csrf'], 'expected authenticated staff session');

    $staffRead = httpRequest($staff, 'GET', 'products.php', null, false);
    reportTest('Staff can read product data', $staffRead['status'] === 200 && ($staffRead['json']['success'] ?? false) === true, 'expected HTTP 200');

    $staffWrite = httpRequest($staff, 'POST', 'products.php', ['name' => '', 'sku' => 'INVALID', 'price' => 1], true);
    reportTest('Staff cannot perform admin-only product writes', $staffWrite['status'] === 403, 'expected HTTP 403');

    $staffReceipt = httpRequest($staff, 'PUT', 'purchases.php', ['id' => $purchaseId, 'action' => 'receive'], true);
    $purchaseStatusStmt = $db->prepare('SELECT status FROM purchases WHERE id = ?');
    $purchaseStatusStmt->execute([$purchaseId]);
    $stockStmt->execute([$productIds['standard']]);
    reportTest('Staff cannot receive a purchase order', $staffReceipt['status'] === 403 && $purchaseStatusStmt->fetchColumn() === 'ordered' && (int)$stockStmt->fetchColumn() === 15, 'expected HTTP 403 with PO and stock unchanged');

    $receipt = httpRequest($admin, 'PUT', 'purchases.php', ['id' => $purchaseId, 'action' => 'receive'], true);
    $purchaseStatusStmt->execute([$purchaseId]);
    $stockStmt->execute([$productIds['standard']]);
    reportTest('Explicit admin receipt updates PO and inventory', $receipt['status'] === 200 && ($receipt['json']['data']['status'] ?? '') === 'received' && $purchaseStatusStmt->fetchColumn() === 'received' && (int)$stockStmt->fetchColumn() === 20, 'expected received status and stock 15 -> 20');

    $purchaseMovementStmt = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND user_id = ? AND movement_type = 'PURCHASE' AND quantity = 5 AND previous_stock = 15 AND new_stock = 20");
    $purchaseMovementStmt->execute([$productIds['standard'], $adminId]);
    reportTest('Receipt creates one actor-attributed purchase movement', (int)$purchaseMovementStmt->fetchColumn() === 1, 'expected a single PURCHASE movement by the admin');

    $repeatReceipt = httpRequest($admin, 'PUT', 'purchases.php', ['id' => $purchaseId, 'action' => 'receive'], true);
    $stockStmt->execute([$productIds['standard']]);
    $purchaseMovementStmt->execute([$productIds['standard'], $adminId]);
    reportTest('Repeated receipt does not duplicate inventory or movement', ($repeatReceipt['json']['data']['already_received'] ?? false) === true && (int)$stockStmt->fetchColumn() === 20 && (int)$purchaseMovementStmt->fetchColumn() === 1, 'expected unchanged stock and one PURCHASE movement');

    if ($strictSqlMode) {
        $failedPo = httpRequest($admin, 'POST', 'purchases.php', [
            'idempotency_key' => uuidV4(), 'supplier_id' => $supplierId,
            'items' => [['product_id' => $productIds['receipt_overflow'], 'quantity' => 1, 'unit_cost' => 1]]
        ], true);
        $failedPoId = (int)($failedPo['json']['data']['id'] ?? 0);
        $failedReceipt = httpRequest($admin, 'PUT', 'purchases.php', ['id' => $failedPoId, 'action' => 'receive'], true);
        $failedPoStatusStmt = $db->prepare('SELECT status FROM purchases WHERE id = ?');
        $failedPoStatusStmt->execute([$failedPoId]);
        $maxStockStmt = $db->prepare('SELECT stock_quantity FROM products WHERE id = ?');
        $maxStockStmt->execute([$productIds['receipt_overflow']]);
        $failedMovementStmt = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND movement_type = 'PURCHASE'");
        $failedMovementStmt->execute([$productIds['receipt_overflow']]);
        reportTest('Failed receipt rolls back stock, audit, and PO status', $failedPo['status'] === 201 && $failedReceipt['status'] === 500 && $failedPoStatusStmt->fetchColumn() === 'ordered' && (int)$maxStockStmt->fetchColumn() === 4294967295 && (int)$failedMovementStmt->fetchColumn() === 0, 'expected overflow failure with ordered PO, max stock, and no movement');
    } else {
        reportSkip('Failed purchase receipt rollback', 'MySQL global SQL mode is not strict; avoiding a potentially clamped unsigned stock write.');
    }

    $expiring = makeClient();
    $expiringLogin = httpRequest($expiring, 'POST', 'auth.php?action=login', ['email' => $run['staff_email'], 'password' => $run['staff_password']], false);
    $sessionId = $expiring['cookie'] ?? '';
    $sessionFile = $sessionId !== '' && preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) ? $sessionDir . '/sess_' . $sessionId : '';
    $expiredAt = time() - 86400;
    $expiredFileMarked = $sessionFile !== '' && is_file($sessionFile) && @touch($sessionFile, $expiredAt);
    $expiredFileRemoved = $expiredFileMarked && @unlink($sessionFile);
    $expiredSession = $expiredFileRemoved ? httpRequest($expiring, 'GET', 'auth.php?action=session', null, false) : ['status' => 0, 'json' => []];
    reportTest('Expired session is treated as unauthenticated', ($expiringLogin['json']['data']['authenticated'] ?? false) === true && $expiredFileRemoved && ($expiredSession['json']['data']['authenticated'] ?? true) === false, 'could not expire the isolated test session or it remained authenticated');

    $logout = httpRequest($admin, 'POST', 'auth.php?action=logout', [], true);
    $afterLogout = httpRequest($admin, 'GET', 'auth.php?action=session', null, false);
    reportTest('Logout invalidates the authenticated session', $logout['status'] === 200 && ($logout['json']['data']['authenticated'] ?? true) === false && ($afterLogout['json']['data']['authenticated'] ?? true) === false, 'session remained authenticated after logout');
} catch (Throwable $e) {
    $failed++;
    $failureDetails[] = 'Runner error: ' . $e->getMessage();
    fwrite(STDERR, '[FAIL] Runner error: ' . $e->getMessage() . "\n");
} finally {
    if ($server !== null && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($db instanceof PDO) {
        try {
            cleanupFixtures($db);
        } catch (Throwable $cleanupError) {
            $failed++;
            $failureDetails[] = 'Fixture cleanup failed: ' . $cleanupError->getMessage();
            fwrite(STDERR, '[FAIL] Fixture cleanup failed: ' . $cleanupError->getMessage() . "\n");
        }
        if ($lockHeld) {
            try { $db->query("SELECT RELEASE_LOCK('smartstore_test.integration')"); } catch (Throwable $ignored) {}
        }
    }
    removeIsolatedSessionFiles($sessionDir);
}

echo "\nRESULT: {$passed} passed, {$failed} failed, {$skipped} skipped\n";
if ($failureDetails) {
    echo "Failure details:\n - " . implode("\n - ", $failureDetails) . "\n";
}
echo "Database safety: configured and connected database was required to be smartstore_test.\n";
exit($failed > 0 ? 1 : 0);
