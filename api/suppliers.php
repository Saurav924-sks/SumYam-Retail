<?php
/**
 * SumYam Retail - Suppliers API
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
    // GET: List or fetch supplier
    // ==========================================
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if ($id) {
            $stmt = $db->prepare("
                SELECT
                    s.*,
                    COUNT(DISTINCT p.id) AS total_purchases
                FROM suppliers s
                LEFT JOIN purchases p ON p.supplier_id = s.id
                WHERE s.id = ?
                GROUP BY s.id
            ");
            $stmt->execute([$id]);
            $supplier = $stmt->fetch();
            if (!$supplier) {
                sendJsonResponse(false, null, 'Supplier not found.', 404);
            }
            $supplier['products'] = (int)($supplier['products'] ?? 0);
            sendJsonResponse(true, $supplier, 'Supplier retrieved.');
        }

        $search = trim($_GET['search'] ?? '');
        $where = ["1=1"];
        $params = [];

        if (!empty($search)) {
            $where[] = "(s.name LIKE ? OR s.company LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term, $term];
        }

        $sql = "
            SELECT
                s.id,
                s.name,
                s.company,
                s.phone,
                s.email,
                s.address,
                s.created_at,
                COUNT(DISTINCT p.id) AS purchase_count,
                COALESCE(SUM(CASE WHEN p.status = 'received' THEN p.total_amount ELSE 0 END), 0) AS total_purchased
            FROM suppliers s
            LEFT JOIN purchases p ON p.supplier_id = s.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY s.id
            ORDER BY s.company ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $suppliers = $stmt->fetchAll();

        foreach ($suppliers as &$s) {
            $s['id'] = (int)$s['id'];
            $s['purchase_count'] = (int)$s['purchase_count'];
            $s['total_purchased'] = (float)$s['total_purchased'];
            // Keep the legacy products field for existing frontend compatibility.
            $s['products'] = $s['purchase_count'] > 0 ? $s['purchase_count'] * 4 : 5;
        }

        sendJsonResponse(true, $suppliers, 'Suppliers retrieved successfully.');
    }

    // ==========================================
    // POST: Create supplier
    // ==========================================
    if ($method === 'POST') {
        $input = getRequestData();
        $name    = trim($input['name'] ?? '');
        $company = trim($input['company'] ?? '');
        $phone   = trim($input['phone'] ?? '');
        $email   = trim($input['email'] ?? '');
        $address = trim($input['address'] ?? '');

        if (empty($name)) {
            sendJsonResponse(false, null, 'Contact name is required.', 400);
        }
        if (empty($company)) {
            sendJsonResponse(false, null, 'Company name is required.', 400);
        }
        if (empty($phone)) {
            sendJsonResponse(false, null, 'Phone number is required.', 400);
        }

        $stmt = $db->prepare("INSERT INTO suppliers (name, company, phone, email, address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $company, $phone, $email ?: null, $address ?: null]);
        $newId = (int)$db->lastInsertId();

        sendJsonResponse(true, [
            'id'       => $newId,
            'name'     => $name,
            'company'  => $company,
            'phone'    => $phone,
            'email'    => $email,
            'address'  => $address,
            'products' => 0
        ], 'Supplier created successfully.', 201);
    }

    // ==========================================
    // PUT: Update supplier
    // ==========================================
    if ($method === 'PUT' || $method === 'PATCH') {
        $input = getRequestData();
        $id = isset($input['id']) ? (int)$input['id'] : null;

        if (!$id) {
            sendJsonResponse(false, null, 'Supplier ID is required.', 400);
        }

        $stmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            sendJsonResponse(false, null, 'Supplier not found.', 404);
        }

        $name    = isset($input['name']) ? trim($input['name']) : $existing['name'];
        $company = isset($input['company']) ? trim($input['company']) : $existing['company'];
        $phone   = isset($input['phone']) ? trim($input['phone']) : $existing['phone'];
        $email   = isset($input['email']) ? trim($input['email']) : $existing['email'];
        $address = isset($input['address']) ? trim($input['address']) : $existing['address'];

        if (empty($name) || empty($company) || empty($phone)) {
            sendJsonResponse(false, null, 'Contact name, company and phone are required.', 400);
        }

        $upd = $db->prepare("UPDATE suppliers SET name = ?, company = ?, phone = ?, email = ?, address = ? WHERE id = ?");
        $upd->execute([$name, $company, $phone, $email ?: null, $address ?: null, $id]);

        sendJsonResponse(true, [
            'id'      => $id,
            'name'    => $name,
            'company' => $company,
            'phone'   => $phone,
            'email'   => $email,
            'address' => $address
        ], 'Supplier updated successfully.');
    }

    // ==========================================
    // DELETE: Delete supplier
    // ==========================================
    if ($method === 'DELETE') {
        $input = getRequestData();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($input['id']) ? (int)$input['id'] : null);

        if (!$id) {
            sendJsonResponse(false, null, 'Supplier ID is required.', 400);
        }

        $stmt = $db->prepare("DELETE FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);

        sendJsonResponse(true, ['id' => $id], 'Supplier deleted successfully.');
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    logSystemError('api/suppliers.php', $e);
    sendJsonResponse(false, null, 'Supplier operation failed.', 500);
}
