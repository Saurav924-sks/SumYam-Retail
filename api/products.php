<?php
/**
 * SumYam Retail - Products API
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
    // GET: List or fetch single product
    // ==========================================
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if ($id) {
            $stmt = $db->prepare("
                SELECT
                    p.*,
                    c.name AS category_name,
                    CASE
                        WHEN p.stock_quantity <= 0 THEN 'out'
                        WHEN p.stock_quantity <= p.min_stock_level THEN 'low'
                        ELSE 'healthy'
                    END AS stock_status
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $product = $stmt->fetch();

            if (!$product) {
                sendJsonResponse(false, null, 'Product not found.', 404);
            }
            sendJsonResponse(true, $product, 'Product fetched.');
        }

        // Filters
        $search   = trim($_GET['search'] ?? '');
        $category = trim($_GET['category'] ?? '');
        $stockStatus = trim($_GET['stock_status'] ?? '');
        $sort     = trim($_GET['sort'] ?? 'default');

        $where = ["1=1"];
        $params = [];

        if (!empty($search)) {
            $where[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR c.name LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($category) && $category !== 'all') {
            if (is_numeric($category)) {
                $where[] = "p.category_id = ?";
                $params[] = (int)$category;
            } else {
                $where[] = "c.name = ?";
                $params[] = $category;
            }
        }

        if (!empty($stockStatus) && $stockStatus !== 'all') {
            if ($stockStatus === 'healthy') {
                $where[] = "p.stock_quantity > p.min_stock_level";
            } elseif ($stockStatus === 'low') {
                $where[] = "p.stock_quantity > 0 AND p.stock_quantity <= p.min_stock_level";
            } elseif ($stockStatus === 'out') {
                $where[] = "p.stock_quantity <= 0";
            }
        }

        // Sorting whitelist
        $orderBy = "p.id DESC";
        switch ($sort) {
            case 'name':
            case 'name_asc':
                $orderBy = "p.name ASC";
                break;
            case 'price_low':
            case 'priceLow':
                $orderBy = "p.selling_price ASC";
                break;
            case 'price_high':
            case 'priceHigh':
                $orderBy = "p.selling_price DESC";
                break;
            case 'stock_low':
            case 'stockLow':
                $orderBy = "p.stock_quantity ASC";
                break;
            case 'stock_high':
            case 'stockHigh':
                $orderBy = "p.stock_quantity DESC";
                break;
            default:
                $orderBy = "p.id DESC";
                break;
        }

        $sql = "
            SELECT
                p.id,
                p.category_id,
                COALESCE(c.name, 'Uncategorized') AS category,
                p.name,
                p.sku,
                p.barcode,
                CAST(p.cost_price AS DECIMAL(10,2)) AS cost_price,
                CAST(p.selling_price AS DECIMAL(10,2)) AS price,
                p.selling_price,
                p.stock_quantity AS stock,
                p.stock_quantity,
                p.min_stock_level AS min,
                p.min_stock_level,
                p.unit,
                p.status,
                p.created_at,
                p.updated_at,
                CASE
                    WHEN p.stock_quantity <= 0 THEN 'out'
                    WHEN p.stock_quantity <= p.min_stock_level THEN 'low'
                    ELSE 'healthy'
                END AS stock_status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $orderBy
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Convert types appropriately for JSON
        foreach ($products as &$p) {
            $p['id'] = (int)$p['id'];
            $p['category_id'] = $p['category_id'] !== null ? (int)$p['category_id'] : null;
            $p['cost_price'] = (float)$p['cost_price'];
            $p['price'] = (float)$p['price'];
            $p['selling_price'] = (float)$p['selling_price'];
            $p['stock'] = (int)$p['stock'];
            $p['stock_quantity'] = (int)$p['stock_quantity'];
            $p['min'] = (int)$p['min'];
            $p['min_stock_level'] = (int)$p['min_stock_level'];
        }

        sendJsonResponse(true, $products, 'Products retrieved successfully.');
    }

    // ==========================================
    // POST: Create a new product
    // ==========================================
    if ($method === 'POST') {
        $input = getRequestData();

        $name          = trim($input['name'] ?? '');
        $sku           = strtoupper(trim($input['sku'] ?? ''));
        $barcode       = trim($input['barcode'] ?? '');
        $categoryName  = trim($input['category'] ?? '');
        $categoryId    = !empty($input['category_id']) ? (int)$input['category_id'] : null;
        $costPrice     = isset($input['cost_price']) ? (float)$input['cost_price'] : 0.0;
        $sellingPrice  = isset($input['price']) ? (float)$input['price'] : (float)($input['selling_price'] ?? 0);
        $stockQuantity = isset($input['stock']) ? (int)$input['stock'] : (int)($input['stock_quantity'] ?? 0);
        $minStockLevel = isset($input['min']) ? (int)$input['min'] : (int)($input['min_stock_level'] ?? 5);
        $unit          = trim($input['unit'] ?? 'pcs');

        // Validation
        if (empty($name)) {
            sendJsonResponse(false, null, 'Product name is required.', 400);
        }
        if (empty($sku)) {
            sendJsonResponse(false, null, 'Product SKU is required.', 400);
        }
        if ($sellingPrice < 0) {
            sendJsonResponse(false, null, 'Selling price cannot be negative.', 400);
        }
        if ($stockQuantity < 0) {
            sendJsonResponse(false, null, 'Stock quantity cannot be negative.', 400);
        }
        if ($minStockLevel < 0) {
            sendJsonResponse(false, null, 'Minimum stock level cannot be negative.', 400);
        }
        if ($costPrice < 0 || !is_finite($costPrice) || !is_finite($sellingPrice)) {
            sendJsonResponse(false, null, 'Cost and selling prices must be finite non-negative amounts.', 400);
        }

        $db->beginTransaction();
        $chk = $db->prepare("SELECT id FROM products WHERE sku = ?");
        $chk->execute([$sku]);
        if ($chk->fetch()) {
            $db->rollBack();
            sendJsonResponse(false, null, "SKU '{$sku}' already exists. Please choose a unique SKU.", 409);
        }

        // Resolve or create category if category name is provided
        if (!$categoryId && !empty($categoryName)) {
            $catStmt = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
            $catStmt->execute([$categoryName]);
            $catRow = $catStmt->fetch();
            if ($catRow) {
                $categoryId = (int)$catRow['id'];
            } else {
                $catIns = $db->prepare("INSERT INTO categories (name) VALUES (?)");
                $catIns->execute([$categoryName]);
                $categoryId = (int)$db->lastInsertId();
            }
        }

        $stmt = $db->prepare("
            INSERT INTO products (
                category_id, name, sku, barcode, cost_price,
                selling_price, stock_quantity, min_stock_level, unit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $categoryId, $name, $sku, $barcode, $costPrice,
            $sellingPrice, $stockQuantity, $minStockLevel, $unit
        ]);
        $newId = (int)$db->lastInsertId();

        // Record initial stock movement if quantity > 0
        if ($stockQuantity > 0) {
            $mv = $db->prepare("
                INSERT INTO stock_movements (
                    product_id, user_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes
                ) VALUES (?, ?, 'INITIAL', ?, 0, ?, 'INITIAL_SETUP', 'Initial product stock creation')
            ");
            $mv->execute([$newId, (int)$user['id'], $stockQuantity, $stockQuantity]);
        }

        $db->commit();

        sendJsonResponse(true, [
            'id'             => $newId,
            'name'           => $name,
            'sku'            => $sku,
            'category'       => $categoryName,
            'category_id'    => $categoryId,
            'price'          => $sellingPrice,
            'selling_price'  => $sellingPrice,
            'stock'          => $stockQuantity,
            'stock_quantity' => $stockQuantity,
            'min'            => $minStockLevel,
            'min_stock_level'=> $minStockLevel
        ], 'Product added successfully.', 201);
    }

    // ==========================================
    // PUT / PATCH: Update product
    // ==========================================
    if ($method === 'PUT' || $method === 'PATCH') {
        $input = getRequestData();
        $id = isset($input['id']) ? (int)$input['id'] : null;

        if (!$id) {
            sendJsonResponse(false, null, 'Product ID is required for update.', 400);
        }

        $db->beginTransaction();
        $currStmt = $db->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
        $currStmt->execute([$id]);
        $existing = $currStmt->fetch();

        if (!$existing) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Product not found.', 404);
        }

        $name          = isset($input['name']) ? trim($input['name']) : $existing['name'];
        $sku           = isset($input['sku']) ? strtoupper(trim($input['sku'])) : $existing['sku'];
        $barcode       = isset($input['barcode']) ? trim($input['barcode']) : $existing['barcode'];
        $categoryName  = isset($input['category']) ? trim($input['category']) : null;
        $categoryId    = isset($input['category_id'])
            ? ((int)$input['category_id'] > 0 ? (int)$input['category_id'] : null)
            : ($existing['category_id'] !== null ? (int)$existing['category_id'] : null);
        $costPrice     = isset($input['cost_price']) ? (float)$input['cost_price'] : (float)$existing['cost_price'];
        $sellingPrice  = isset($input['price']) ? (float)$input['price'] : (isset($input['selling_price']) ? (float)$input['selling_price'] : (float)$existing['selling_price']);
        $newStock      = isset($input['stock']) ? (int)$input['stock'] : (isset($input['stock_quantity']) ? (int)$input['stock_quantity'] : (int)$existing['stock_quantity']);
        $minStockLevel = isset($input['min']) ? (int)$input['min'] : (isset($input['min_stock_level']) ? (int)$input['min_stock_level'] : (int)$existing['min_stock_level']);
        $unit          = isset($input['unit']) ? trim($input['unit']) : $existing['unit'];

        // Validation
        if (empty($name)) {
            sendJsonResponse(false, null, 'Product name cannot be empty.', 400);
        }
        if (empty($sku)) {
            sendJsonResponse(false, null, 'SKU cannot be empty.', 400);
        }
        if ($costPrice < 0 || $sellingPrice < 0 || $newStock < 0 || $minStockLevel < 0
            || !is_finite($costPrice) || !is_finite($sellingPrice)) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Prices and stock amounts must be finite non-negative values.', 400);
        }

        // Check SKU uniqueness (exclude current product)
        $chk = $db->prepare("SELECT id FROM products WHERE sku = ? AND id != ?");
        $chk->execute([$sku, $id]);
        if ($chk->fetch()) {
            $db->rollBack();
            sendJsonResponse(false, null, "SKU '{$sku}' is already taken by another product.", 409);
        }

        // If category name provided
        if ($categoryName) {
            $catStmt = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
            $catStmt->execute([$categoryName]);
            $catRow = $catStmt->fetch();
            if ($catRow) {
                $categoryId = (int)$catRow['id'];
            } else {
                $catIns = $db->prepare("INSERT INTO categories (name) VALUES (?)");
                $catIns->execute([$categoryName]);
                $categoryId = (int)$db->lastInsertId();
            }
        }

        $stmt = $db->prepare("
            UPDATE products SET
                name = ?,
                sku = ?,
                barcode = ?,
                category_id = ?,
                cost_price = ?,
                selling_price = ?,
                stock_quantity = ?,
                min_stock_level = ?,
                unit = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $name, $sku, $barcode, $categoryId, $costPrice,
            $sellingPrice, $newStock, $minStockLevel, $unit, $id
        ]);

        // Audit stock movement if stock quantity changed
        $prevStock = (int)$existing['stock_quantity'];
        if ($prevStock !== $newStock) {
            $diff = $newStock - $prevStock;
            $type = $diff > 0 ? 'ADJUSTMENT_ADD' : 'ADJUSTMENT_SUB';
            $mv = $db->prepare("
                INSERT INTO stock_movements (
                    product_id, user_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes
                ) VALUES (?, ?, ?, ?, ?, ?, 'MANUAL_EDIT', 'Stock manually adjusted via product edit')
            ");
            $mv->execute([$id, (int)$user['id'], $type, $diff, $prevStock, $newStock]);
        }

        $db->commit();

        sendJsonResponse(true, [
            'id'             => $id,
            'name'           => $name,
            'sku'            => $sku,
            'price'          => $sellingPrice,
            'selling_price'  => $sellingPrice,
            'stock'          => $newStock,
            'stock_quantity' => $newStock,
            'min'            => $minStockLevel,
            'min_stock_level'=> $minStockLevel
        ], 'Product updated successfully.');
    }

    // ==========================================
    // DELETE: Delete product
    // ==========================================
    if ($method === 'DELETE') {
        $input = getRequestData();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($input['id']) ? (int)$input['id'] : null);

        if (!$id) {
            sendJsonResponse(false, null, 'Product ID is required for deletion.', 400);
        }

        $db->beginTransaction();
        $currStmt = $db->prepare("SELECT id, name, status FROM products WHERE id = ? FOR UPDATE");
        $currStmt->execute([$id]);
        $prod = $currStmt->fetch();
        if (!$prod) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Product not found.', 404);
        }

        $stmt = $db->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        $db->commit();
        sendJsonResponse(true, ['id' => $id, 'action' => 'deactivated'], 'Product deactivated; its inventory and financial history were retained.');
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logSystemError('api/products.php', $e);
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        sendJsonResponse(false, null, 'A product with this SKU or category already exists.', 409);
    }
    sendJsonResponse(false, null, 'Product operation failed.', 500);
}
