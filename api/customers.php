<?php
/**
 * SumYam Retail - Customers API
 * Maintainer: Saurav Shah Teli
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $db = getDBConnection();
    requireApiRole($db, $method === 'GET' ? ['admin', 'staff'] : ['admin']);

    // ==========================================
    // GET: List or fetch customer
    // ==========================================
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if ($id) {
            $stmt = $db->prepare("
                SELECT
                    c.*,
                    COUNT(s.id) AS purchases,
                    COALESCE(SUM(s.grand_total), 0) AS total_spent
                FROM customers c
                LEFT JOIN sales s ON s.customer_id = c.id AND s.status = 'completed'
                WHERE c.id = ?
                GROUP BY c.id
            ");
            $stmt->execute([$id]);
            $customer = $stmt->fetch();
            if (!$customer) {
                sendJsonResponse(false, null, 'Customer not found.', 404);
            }
            $customer['purchases'] = (int)$customer['purchases'];
            $customer['total_spent'] = (float)$customer['total_spent'];
            sendJsonResponse(true, $customer, 'Customer retrieved.');
        }

        $search = trim($_GET['search'] ?? '');
        $where = ["1=1"];
        $params = [];

        if (!empty($search)) {
            $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.address LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term, $term];
        }

        $sql = "
            SELECT
                c.id,
                c.name,
                c.phone,
                c.email,
                c.address,
                c.created_at,
                COUNT(s.id) AS purchases,
                COALESCE(SUM(s.grand_total), 0) AS total_spent
            FROM customers c
            LEFT JOIN sales s ON s.customer_id = c.id AND s.status = 'completed'
            WHERE " . implode(' AND ', $where) . "
            GROUP BY c.id
            ORDER BY c.name ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        foreach ($customers as &$c) {
            $c['id'] = (int)$c['id'];
            $c['purchases'] = (int)$c['purchases'];
            $c['total_spent'] = (float)$c['total_spent'];
        }

        sendJsonResponse(true, $customers, 'Customers retrieved successfully.');
    }

    // ==========================================
    // POST: Create customer
    // ==========================================
    if ($method === 'POST') {
        $input = getRequestData();
        $name    = trim($input['name'] ?? '');
        $phone   = trim($input['phone'] ?? '');
        $email   = trim($input['email'] ?? '');
        $address = trim($input['address'] ?? '');

        if (empty($name)) {
            sendJsonResponse(false, null, 'Customer name is required.', 400);
        }
        if (empty($phone)) {
            sendJsonResponse(false, null, 'Customer phone number is required.', 400);
        }
        if (!preg_match('/^[0-9+\-\s()]{7,15}$/', $phone)) {
            sendJsonResponse(false, null, 'Please provide a valid phone number (at least 7 to 15 digits).', 400);
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendJsonResponse(false, null, 'Please enter a valid email address.', 400);
        }

        $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $phone, $email ?: null, $address ?: null]);
        $newId = (int)$db->lastInsertId();

        sendJsonResponse(true, [
            'id'          => $newId,
            'name'        => $name,
            'phone'       => $phone,
            'email'       => $email,
            'address'     => $address,
            'purchases'   => 0,
            'total_spent' => 0.00
        ], 'Customer saved successfully.', 201);
    }

    // ==========================================
    // PUT: Update customer
    // ==========================================
    if ($method === 'PUT' || $method === 'PATCH') {
        $input = getRequestData();
        $id = isset($input['id']) ? (int)$input['id'] : null;

        if (!$id) {
            sendJsonResponse(false, null, 'Customer ID is required.', 400);
        }

        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            sendJsonResponse(false, null, 'Customer not found.', 404);
        }

        $name    = isset($input['name']) ? trim($input['name']) : $existing['name'];
        $phone   = isset($input['phone']) ? trim($input['phone']) : $existing['phone'];
        $email   = isset($input['email']) ? trim($input['email']) : $existing['email'];
        $address = isset($input['address']) ? trim($input['address']) : $existing['address'];

        if (empty($name) || empty($phone)) {
            sendJsonResponse(false, null, 'Name and phone are required.', 400);
        }

        $upd = $db->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
        $upd->execute([$name, $phone, $email ?: null, $address ?: null, $id]);

        sendJsonResponse(true, [
            'id'      => $id,
            'name'    => $name,
            'phone'   => $phone,
            'email'   => $email,
            'address' => $address
        ], 'Customer updated successfully.');
    }

    // ==========================================
    // DELETE: Delete customer
    // ==========================================
    if ($method === 'DELETE') {
        $input = getRequestData();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($input['id']) ? (int)$input['id'] : null);

        if (!$id) {
            sendJsonResponse(false, null, 'Customer ID is required.', 400);
        }

        $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->execute([$id]);

        sendJsonResponse(true, ['id' => $id], 'Customer deleted successfully.');
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    logSystemError('api/customers.php', $e);
    sendJsonResponse(false, null, 'Customer operation failed.', 500);
}
