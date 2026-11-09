<?php
/**
 * SumYam Retail - Categories API
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $db = getDBConnection();
    requireApiRole($db, $method === 'GET' ? ['admin', 'staff'] : ['admin']);

    if ($method === 'GET') {
        // Fetch all categories with product count
        $stmt = $db->query("
            SELECT
                c.id,
                c.name,
                c.description,
                c.created_at,
                COUNT(p.id) AS product_count,
                COALESCE(SUM(p.stock_quantity), 0) AS total_units
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id
            GROUP BY c.id
            ORDER BY c.name ASC
        ");
        $categories = $stmt->fetchAll();
        sendJsonResponse(true, $categories, 'Categories retrieved successfully.');
    }

    if ($method === 'POST') {
        $input = getRequestData();
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');

        if (empty($name)) {
            sendJsonResponse(false, null, 'Category name is required.', 400);
        }

        // Check duplicate
        $chk = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
        $chk->execute([$name]);
        if ($chk->fetch()) {
            sendJsonResponse(false, null, 'A category with this name already exists.', 409);
        }

        $stmt = $db->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
        $stmt->execute([$name, $description]);
        $newId = (int)$db->lastInsertId();

        sendJsonResponse(true, [
            'id' => $newId,
            'name' => $name,
            'description' => $description
        ], 'Category created successfully.', 201);
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    logSystemError('api/categories.php', $e);
    sendJsonResponse(false, null, 'Category operation failed.', 500);
}
