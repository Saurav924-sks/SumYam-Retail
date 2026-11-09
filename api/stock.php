<?php
/**
 * SumYam Retail - Stock Adjustment & Movement History API
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
    // GET: Movement history log
    // ==========================================
    if ($method === 'GET') {
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
        $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;

        $where = ["1=1"];
        $params = [];

        if ($productId) {
            $where[] = "sm.product_id = ?";
            $params[] = $productId;
        }

        $sql = "
            SELECT
                sm.id,
                sm.product_id,
                p.name AS product_name,
                p.sku,
                sm.movement_type,
                sm.quantity,
                sm.previous_stock,
                sm.new_stock,
                sm.reference_id,
                sm.notes,
                sm.created_at
            FROM stock_movements sm
            JOIN products p ON p.id = sm.product_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sm.id DESC
            LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $movements = $stmt->fetchAll();

        sendJsonResponse(true, $movements, 'Stock movement log retrieved.');
    }

    // ==========================================
    // POST: Quick stock adjustment
    // ==========================================
    if ($method === 'POST') {
        $input = getRequestData();

        $productId = isset($input['id']) ? (int)$input['id'] : (isset($input['product_id']) ? (int)$input['product_id'] : 0);
        $delta = isset($input['delta']) ? (int)$input['delta'] : null;
        $newStockInput = isset($input['stock']) ? (int)$input['stock'] : (isset($input['new_stock']) ? (int)$input['new_stock'] : null);
        $notes = trim($input['notes'] ?? 'Manual stock adjustment');

        if ($productId <= 0) {
            sendJsonResponse(false, null, 'Valid product ID is required.', 400);
        }

        $db->beginTransaction();

        $stmt = $db->prepare("SELECT id, name, sku, stock_quantity, min_stock_level FROM products WHERE id = ? FOR UPDATE");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Product not found.', 404);
        }

        $prevStock = (int)$product['stock_quantity'];

        if ($delta !== null) {
            $calculatedNewStock = $prevStock + $delta;
            $quantityDiff = $delta;
        } elseif ($newStockInput !== null) {
            $calculatedNewStock = $newStockInput;
            $quantityDiff = $calculatedNewStock - $prevStock;
        } else {
            $db->rollBack();
            sendJsonResponse(false, null, 'Either delta or new stock value must be provided.', 400);
        }

        if ($calculatedNewStock < 0) {
            $db->rollBack();
            sendJsonResponse(false, null, "Stock cannot become negative (Current: {$prevStock}, Requested Change: {$delta}).", 400);
        }

        $movementType = $quantityDiff >= 0 ? 'ADJUSTMENT_ADD' : 'ADJUSTMENT_SUB';

        // Update product stock
        $upd = $db->prepare("UPDATE products SET stock_quantity = ? WHERE id = ?");
        $upd->execute([$calculatedNewStock, $productId]);

        // Insert stock movement record
        $insMv = $db->prepare("
            INSERT INTO stock_movements (
                product_id, user_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes
            ) VALUES (?, ?, ?, ?, ?, ?, 'MANUAL_ADJUST', ?)
        ");
        $insMv->execute([$productId, (int)$user['id'], $movementType, $quantityDiff, $prevStock, $calculatedNewStock, $notes]);

        $db->commit();

        sendJsonResponse(true, [
            'product_id'     => $productId,
            'name'           => $product['name'],
            'previous_stock' => $prevStock,
            'new_stock'      => $calculatedNewStock,
            'change'         => $quantityDiff,
            'status'         => $calculatedNewStock <= 0 ? 'out' : ($calculatedNewStock <= (int)$product['min_stock_level'] ? 'low' : 'healthy')
        ], "{$product['name']} stock updated to {$calculatedNewStock}.");
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logSystemError('api/stock.php', $e);
    sendJsonResponse(false, null, 'Stock adjustment failed.', 500);
}
