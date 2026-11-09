<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$submittedIdempotencyKey = null;

function respondWithExistingPurchase(PDO $db, int $userId, string $idempotencyKey): void {
    $stmt = $db->prepare('SELECT id, po_number, supplier_name, total_amount, status FROM purchases WHERE created_by = ? AND idempotency_key = ?');
    $stmt->execute([$userId, $idempotencyKey]);
    $purchase = $stmt->fetch();
    if ($purchase) {
        sendJsonResponse(true, [
            'id' => (int)$purchase['id'],
            'po_number' => $purchase['po_number'],
            'supplier' => $purchase['supplier_name'],
            'total_amount' => (float)$purchase['total_amount'],
            'status' => $purchase['status'],
            'idempotent_replay' => true
        ], 'This purchase order was already created.');
    }
}

try {
    $db = getDBConnection();
    $user = requireApiRole($db, $method === 'GET' ? ['admin', 'staff'] : ['admin']);

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if ($id) {
            $stmt = $db->prepare("
                SELECT p.*, s.phone AS supplier_phone, s.email AS supplier_email
                FROM purchases p
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $purchase = $stmt->fetch();
            if (!$purchase) {
                sendJsonResponse(false, null, 'Purchase record not found.', 404);
            }

            $itemsStmt = $db->prepare('SELECT * FROM purchase_items WHERE purchase_id = ? ORDER BY id');
            $itemsStmt->execute([$id]);
            $purchase['items'] = $itemsStmt->fetchAll();
            sendJsonResponse(true, $purchase, 'Purchase record retrieved.');
        }

        $stmt = $db->query("
            SELECT
                p.id, p.po_number, p.supplier_id, p.supplier_name, p.total_amount,
                p.status, p.notes, p.purchase_date, p.received_at, p.created_at,
                COUNT(pi.id) AS item_count,
                COALESCE(SUM(pi.quantity), 0) AS total_units
            FROM purchases p
            LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
            GROUP BY p.id
            ORDER BY p.id DESC
        ");
        sendJsonResponse(true, $stmt->fetchAll(), 'Purchase orders retrieved.');
    }

    if ($method === 'POST') {
        $input = getRequestData();
        $submittedIdempotencyKey = strtolower(trim($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $submittedIdempotencyKey)) {
            sendJsonResponse(false, null, 'A valid purchase idempotency key is required.', 400);
        }
        respondWithExistingPurchase($db, (int)$user['id'], $submittedIdempotencyKey);

        $supplierId = isset($input['supplier_id']) ? (int)$input['supplier_id'] : 0;
        $items = $input['items'] ?? null;
        $notes = trim($input['notes'] ?? '');
        if ($supplierId <= 0 || !is_array($items) || $items === []) {
            sendJsonResponse(false, null, 'Select a supplier and at least one product for the purchase order.', 400);
        }
        if (strlen($notes) > 500) {
            sendJsonResponse(false, null, 'Purchase notes cannot exceed 500 characters.', 400);
        }

        $requested = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                sendJsonResponse(false, null, 'Invalid product in purchase order.', 400);
            }
            $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? $item['qty'] ?? 0);
            $unitCostRaw = $item['unit_cost'] ?? $item['cost_price'] ?? null;
            if ($productId <= 0 || $quantity <= 0 || $quantity > 100000) {
                sendJsonResponse(false, null, 'Each order item requires a valid product and positive quantity.', 400);
            }

            $unitCost = null;
            if ($unitCostRaw !== null && $unitCostRaw !== '') {
                if (!is_numeric($unitCostRaw) || !is_finite((float)$unitCostRaw) || (float)$unitCostRaw < 0) {
                    sendJsonResponse(false, null, 'Purchase unit cost must be a finite non-negative amount.', 400);
                }
                $unitCost = round((float)$unitCostRaw, 2);
            }
            if (!isset($requested[$productId])) {
                $requested[$productId] = ['quantity' => 0, 'unit_cost' => $unitCost];
            } elseif ($unitCost !== null && $requested[$productId]['unit_cost'] !== null
                && $requested[$productId]['unit_cost'] !== $unitCost) {
                sendJsonResponse(false, null, 'Duplicate product lines must use the same unit cost.', 400);
            } elseif ($requested[$productId]['unit_cost'] === null) {
                $requested[$productId]['unit_cost'] = $unitCost;
            }
            $requested[$productId]['quantity'] += $quantity;
            if ($requested[$productId]['quantity'] > 100000) {
                sendJsonResponse(false, null, 'Requested purchase quantity exceeds the allowed limit.', 400);
            }
        }
        ksort($requested, SORT_NUMERIC);

        $db->beginTransaction();
        $supplierStmt = $db->prepare('SELECT id, name, company FROM suppliers WHERE id = ? FOR UPDATE');
        $supplierStmt->execute([$supplierId]);
        $supplier = $supplierStmt->fetch();
        if (!$supplier) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Selected supplier was not found.', 404);
        }

        $validatedItems = [];
        $totalAmount = 0.0;
        foreach ($requested as $productId => $request) {
            $productStmt = $db->prepare('SELECT id, name, cost_price FROM products WHERE id = ? FOR UPDATE');
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product) {
                $db->rollBack();
                sendJsonResponse(false, null, 'A product in the purchase order was not found.', 404);
            }
            $unitCost = $request['unit_cost'] ?? (float)$product['cost_price'];
            $lineSubtotal = round($unitCost * $request['quantity'], 2);
            $totalAmount = round($totalAmount + $lineSubtotal, 2);
            $validatedItems[] = [
                'product_id' => (int)$product['id'],
                'product_name' => $product['name'],
                'unit_cost' => $unitCost,
                'quantity' => $request['quantity'],
                'subtotal' => $lineSubtotal
            ];
        }

        $supplierName = $supplier['company'] ?: $supplier['name'];
        $insertPurchase = $db->prepare("
            INSERT INTO purchases (
                idempotency_key, created_by, supplier_id, supplier_name, total_amount,
                status, notes, purchase_date
            ) VALUES (?, ?, ?, ?, ?, 'ordered', ?, ?)
        ");
        $insertPurchase->execute([
            $submittedIdempotencyKey, (int)$user['id'], $supplierId, $supplierName,
            $totalAmount, $notes ?: null, date('Y-m-d')
        ]);
        $purchaseId = (int)$db->lastInsertId();
        $poNumber = sprintf('PO-%s-%08d', date('Ymd'), $purchaseId);
        $db->prepare('UPDATE purchases SET po_number = ? WHERE id = ?')->execute([$poNumber, $purchaseId]);

        $insertItem = $db->prepare("
            INSERT INTO purchase_items (purchase_id, product_id, product_name, unit_cost, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($validatedItems as $item) {
            $insertItem->execute([
                $purchaseId, $item['product_id'], $item['product_name'],
                $item['unit_cost'], $item['quantity'], $item['subtotal']
            ]);
        }

        $db->commit();
        sendJsonResponse(true, [
            'id' => $purchaseId,
            'po_number' => $poNumber,
            'supplier' => $supplierName,
            'total_amount' => $totalAmount,
            'status' => 'ordered',
            'items_count' => count($validatedItems),
            'idempotent_replay' => false
        ], 'Purchase order created. Inventory will change only when the order is received.', 201);
    }

    if ($method === 'PUT') {
        $input = getRequestData();
        $purchaseId = (int)($input['id'] ?? 0);
        if ($purchaseId <= 0 || ($input['action'] ?? '') !== 'receive') {
            sendJsonResponse(false, null, 'A purchase ID and receive action are required.', 400);
        }

        $db->beginTransaction();
        $purchaseStmt = $db->prepare('SELECT id, po_number, status FROM purchases WHERE id = ? FOR UPDATE');
        $purchaseStmt->execute([$purchaseId]);
        $purchase = $purchaseStmt->fetch();
        if (!$purchase) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Purchase order not found.', 404);
        }
        if ($purchase['status'] === 'received') {
            $db->rollBack();
            sendJsonResponse(true, ['id' => $purchaseId, 'status' => 'received', 'already_received' => true], 'Purchase order was already received.');
        }
        if ($purchase['status'] !== 'ordered') {
            $db->rollBack();
            sendJsonResponse(false, null, 'Only an ordered purchase can be received.', 409);
        }

        $itemsStmt = $db->prepare('SELECT product_id, product_name, quantity FROM purchase_items WHERE purchase_id = ? ORDER BY product_id FOR UPDATE');
        $itemsStmt->execute([$purchaseId]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            $db->rollBack();
            sendJsonResponse(false, null, 'Purchase order has no items to receive.', 409);
        }

        $stockStmt = $db->prepare('SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE');
        $updateStock = $db->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?');
        $movementStmt = $db->prepare("
            INSERT INTO stock_movements (
                product_id, user_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes
            ) VALUES (?, ?, 'PURCHASE', ?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $stockStmt->execute([(int)$item['product_id']]);
            $currentStock = $stockStmt->fetchColumn();
            if ($currentStock === false) {
                $db->rollBack();
                sendJsonResponse(false, null, 'A product in this purchase order no longer exists.', 409);
            }
            $currentStock = (int)$currentStock;
            $quantity = (int)$item['quantity'];
            $newStock = $currentStock + $quantity;
            $updateStock->execute([$quantity, (int)$item['product_id']]);
            $movementStmt->execute([
                (int)$item['product_id'], (int)$user['id'], $quantity, $currentStock,
                $newStock, $purchase['po_number'], 'Received via purchase order ' . $purchase['po_number']
            ]);
        }

        $db->prepare('UPDATE purchase_items SET received_quantity = quantity WHERE purchase_id = ?')->execute([$purchaseId]);
        $db->prepare("UPDATE purchases SET status = 'received', received_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$purchaseId]);
        $db->commit();
        sendJsonResponse(true, ['id' => $purchaseId, 'status' => 'received'], 'Purchase order received and inventory updated.');
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($e instanceof PDOException && $e->getCode() === '23000' && $submittedIdempotencyKey && isset($user['id'])) {
        respondWithExistingPurchase($db, (int)$user['id'], $submittedIdempotencyKey);
    }
    logSystemError('api/purchases.php', $e);
    sendJsonResponse(false, null, 'Purchase operation failed.', 500);
}
