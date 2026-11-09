<?php
/**
 * SumYam Retail - Sales & Billing Transaction API
 * Maintainer: Saurav Shah Teli
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$submittedIdempotencyKey = null;

function respondWithExistingSale(PDO $db, int $userId, string $idempotencyKey): void {
    $stmt = $db->prepare('SELECT * FROM sales WHERE user_id = ? AND idempotency_key = ?');
    $stmt->execute([$userId, $idempotencyKey]);
    $sale = $stmt->fetch();
    if (!$sale) {
        return;
    }

    $itemsStmt = $db->prepare('SELECT product_name, quantity, unit_price, subtotal FROM sale_items WHERE sale_id = ? ORDER BY id');
    $itemsStmt->execute([$sale['id']]);
    $items = array_map(static function (array $item): array {
        return [
            'name' => $item['product_name'],
            'qty' => (int)$item['quantity'],
            'price' => (float)$item['unit_price'],
            'subtotal' => (float)$item['subtotal']
        ];
    }, $itemsStmt->fetchAll());

    sendJsonResponse(true, [
        'id' => $sale['invoice_no'],
        'sale_id' => (int)$sale['id'],
        'invoice_no' => $sale['invoice_no'],
        'customer' => $sale['customer_name'],
        'customer_id' => $sale['customer_id'] === null ? null : (int)$sale['customer_id'],
        'date' => $sale['sale_date'],
        'subtotal' => (float)$sale['subtotal'],
        'discount' => (float)$sale['discount'],
        'tax_rate' => (float)$sale['tax_rate'],
        'tax_amount' => (float)$sale['tax_amount'],
        'grand_total' => (float)$sale['grand_total'],
        'total' => (float)$sale['grand_total'],
        'payment_method' => $sale['payment_method'],
        'items' => $items,
        'idempotent_replay' => true
    ], 'This sale was already processed.');
}

try {
    $db = getDBConnection();
    $user = requireApiRole($db);

    // ==========================================
    // GET: List sales or get single invoice
    // ==========================================
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        $invoiceNo = trim($_GET['invoice_no'] ?? '');

        // Fetch single invoice
        if ($id || !empty($invoiceNo)) {
            $stmt = $db->prepare("
                SELECT
                    s.*,
                    c.phone AS customer_phone,
                    c.email AS customer_email
                FROM sales s
                LEFT JOIN customers c ON s.customer_id = c.id
                WHERE " . ($id ? "s.id = ?" : "s.invoice_no = ?")
            );
            $stmt->execute([$id ?: $invoiceNo]);
            $sale = $stmt->fetch();

            if (!$sale) {
                sendJsonResponse(false, null, 'Invoice not found.', 404);
            }

            // Fetch line items
            $itemStmt = $db->prepare("
                SELECT
                    id,
                    product_id,
                    product_name,
                    sku,
                    CAST(unit_price AS DECIMAL(10,2)) AS unit_price,
                    quantity,
                    CAST(subtotal AS DECIMAL(10,2)) AS subtotal
                FROM sale_items
                WHERE sale_id = ?
                ORDER BY id ASC
            ");
            $itemStmt->execute([$sale['id']]);
            $items = $itemStmt->fetchAll();

            $sale['id'] = (int)$sale['id'];
            $sale['subtotal'] = (float)$sale['subtotal'];
            $sale['discount'] = (float)$sale['discount'];
            $sale['tax_rate'] = (float)$sale['tax_rate'];
            $sale['tax_amount'] = (float)$sale['tax_amount'];
            $sale['grand_total'] = (float)$sale['grand_total'];
            $sale['total'] = (float)$sale['grand_total']; // backwards compatibility
            $sale['items'] = array_map(function($i) {
                return [
                    'id'           => (int)$i['id'],
                    'product_id'   => $i['product_id'] !== null ? (int)$i['product_id'] : null,
                    'product_name' => $i['product_name'],
                    'name'         => $i['product_name'], // backwards compatibility
                    'sku'          => $i['sku'],
                    'unit_price'   => (float)$i['unit_price'],
                    'price'        => (float)$i['unit_price'],
                    'quantity'     => (int)$i['quantity'],
                    'qty'          => (int)$i['quantity'], // backwards compatibility
                    'subtotal'     => (float)$i['subtotal']
                ];
            }, $items);

            sendJsonResponse(true, $sale, 'Invoice retrieved successfully.');
        }

        // List sales
        $search   = trim($_GET['search'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to'] ?? '');
        $limit    = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

        $where = ["1=1"];
        $params = [];

        if (!empty($search)) {
            $where[] = "(s.invoice_no LIKE ? OR s.customer_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if (!empty($dateFrom)) {
            $where[] = "s.sale_date >= ?";
            $params[] = $dateFrom;
        }

        if (!empty($dateTo)) {
            $where[] = "s.sale_date <= ?";
            $params[] = $dateTo;
        }

        $sql = "
            SELECT
                s.id,
                s.invoice_no,
                s.customer_id,
                s.customer_name AS customer,
                s.subtotal,
                s.discount,
                s.tax_rate,
                s.tax_amount,
                s.grand_total,
                s.grand_total AS total,
                s.payment_method,
                s.status,
                s.sale_date AS date,
                s.created_at,
                COUNT(si.id) AS item_count,
                COALESCE(SUM(si.quantity), 0) AS total_quantity
            FROM sales s
            LEFT JOIN sale_items si ON si.sale_id = s.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY s.id
            ORDER BY s.id DESC
            LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll();

        // Attach items preview to each sale
        if (!empty($sales)) {
            $saleIds = array_column($sales, 'id');
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $itemQuery = $db->prepare("
                SELECT sale_id, product_name AS name, quantity AS qty, unit_price AS price, subtotal
                FROM sale_items
                WHERE sale_id IN ($placeholders)
            ");
            $itemQuery->execute($saleIds);
            $allItems = $itemQuery->fetchAll();

            $groupedItems = [];
            foreach ($allItems as $it) {
                $groupedItems[$it['sale_id']][] = [
                    'name'     => $it['name'],
                    'qty'      => (int)$it['qty'],
                    'price'    => (float)$it['price'],
                    'subtotal' => (float)$it['subtotal']
                ];
            }

            foreach ($sales as &$s) {
                $s['id_num'] = (int)$s['id'];
                $s['id'] = $s['invoice_no']; // Preserve the invoice-number identifier expected by the frontend.
                $s['subtotal'] = (float)$s['subtotal'];
                $s['discount'] = (float)$s['discount'];
                $s['tax_amount'] = (float)$s['tax_amount'];
                $s['grand_total'] = (float)$s['grand_total'];
                $s['total'] = (float)$s['grand_total'];
                $s['items'] = $groupedItems[$s['id_num']] ?? [];
            }
        }

        sendJsonResponse(true, $sales, 'Sales retrieved successfully.');
    }

    // ==========================================
    // POST: COMPLETE TRANSACTION-SAFE SALE
    // ==========================================
    if ($method === 'POST') {
        $input = getRequestData();

        $items = $input['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            sendJsonResponse(false, null, 'Cannot process an empty sale. Add at least one item to cart.', 400);
        }

        $submittedIdempotencyKey = strtolower(trim($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $submittedIdempotencyKey)) {
            sendJsonResponse(false, null, 'A valid checkout idempotency key is required.', 400);
        }

        respondWithExistingSale($db, (int)$user['id'], $submittedIdempotencyKey);

        $discountRaw = $input['discount'] ?? 0;
        if (!is_numeric($discountRaw) || !is_finite((float)$discountRaw) || (float)$discountRaw < 0) {
            sendJsonResponse(false, null, 'Discount must be a non-negative number.', 400);
        }
        $discountInput = (float)$discountRaw;
        $paymentMethod = $input['payment_method'] ?? 'cash';
        if (!in_array($paymentMethod, ['cash', 'card', 'upi', 'credit'], true)) {
            sendJsonResponse(false, null, 'Unsupported payment method.', 400);
        }
        $notes = trim($input['notes'] ?? 'POS Checkout');
        if (strlen($notes) > 500) {
            sendJsonResponse(false, null, 'Sale notes cannot exceed 500 characters.', 400);
        }

        $db->beginTransaction();

        $requestedQuantities = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $db->rollBack();
                sendJsonResponse(false, null, 'Invalid product in checkout cart.', 400);
            }
            $productId = isset($item['id']) ? (int)$item['id'] : (isset($item['product_id']) ? (int)$item['product_id'] : 0);
            $requestedQty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['quantity']) ? (int)$item['quantity'] : 0);

            if ($productId <= 0 || $requestedQty <= 0 || $requestedQty > 100000) {
                $db->rollBack();
                sendJsonResponse(false, null, 'Each cart item requires a valid product and positive quantity.', 400);
            }
            $requestedQuantities[$productId] = ($requestedQuantities[$productId] ?? 0) + $requestedQty;
            if ($requestedQuantities[$productId] > 100000) {
                $db->rollBack();
                sendJsonResponse(false, null, 'Requested quantity exceeds the allowed limit.', 400);
            }
        }
        ksort($requestedQuantities, SORT_NUMERIC);

        $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
        $customerName = 'Walk-in Customer';
        if ($customerId !== null) {
            $customerStmt = $db->prepare('SELECT id, name FROM customers WHERE id = ? FOR UPDATE');
            $customerStmt->execute([$customerId]);
            $customer = $customerStmt->fetch();
            if (!$customer) {
                $db->rollBack();
                sendJsonResponse(false, null, 'Selected customer was not found.', 404);
            }
            $customerName = $customer['name'];
        }

        $validatedItems = [];
        $calculatedSubtotal = 0.0;
        foreach ($requestedQuantities as $productId => $requestedQty) {
            $stmt = $db->prepare("
                SELECT id, name, sku, cost_price, selling_price, stock_quantity, status
                FROM products
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                $db->rollBack();
                sendJsonResponse(false, null, "Product with ID {$productId} was not found in database.", 404);
            }

            if ($product['status'] === 'inactive') {
                $db->rollBack();
                sendJsonResponse(false, null, "Product '{$product['name']}' is currently marked inactive and cannot be sold.", 400);
            }

            $currentStock = (int)$product['stock_quantity'];
            if ($currentStock < $requestedQty) {
                $db->rollBack();
                sendJsonResponse(false, null, "Insufficient stock for '{$product['name']}'. Requested: {$requestedQty}, Available: {$currentStock}.", 400);
            }

            // Server-verified price calculation
            $unitPrice = (float)$product['selling_price'];
            $costPrice = (float)$product['cost_price'];
            $lineSubtotal = round($unitPrice * $requestedQty, 2);
            $calculatedSubtotal += $lineSubtotal;

            $validatedItems[] = [
                'product_id'     => (int)$product['id'],
                'name'           => $product['name'],
                'sku'            => $product['sku'],
                'unit_price'     => $unitPrice,
                'cost_price'     => $costPrice,
                'quantity'       => $requestedQty,
                'line_subtotal'  => $lineSubtotal,
                'line_discount'  => 0.0,
                'previous_stock' => $currentStock,
                'new_stock'      => $currentStock - $requestedQty
            ];
        }

        $discount = min($calculatedSubtotal, $discountInput);
        $taxableAmount = max(0, $calculatedSubtotal - $discount);
        $taxRate = 5.00;
        $taxAmount = round($taxableAmount * ($taxRate / 100), 2);
        $grandTotal = round($taxableAmount + $taxAmount, 2);
        $saleDate = date('Y-m-d');

        $discountRemaining = $discount;
        foreach ($validatedItems as $index => &$validatedItem) {
            if ($index === array_key_last($validatedItems)) {
                $validatedItem['line_discount'] = round($discountRemaining, 2);
            } else {
                $share = $calculatedSubtotal > 0
                    ? round($discount * $validatedItem['line_subtotal'] / $calculatedSubtotal, 2)
                    : 0.0;
                $validatedItem['line_discount'] = min($discountRemaining, $share);
                $discountRemaining = round($discountRemaining - $validatedItem['line_discount'], 2);
            }
        }
        unset($validatedItem);

        $insSale = $db->prepare("
            INSERT INTO sales (
                idempotency_key, user_id, customer_id, customer_name, subtotal, discount,
                tax_rate, tax_amount, grand_total, payment_method, status, notes, sale_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)
        ");
        $insSale->execute([
            $submittedIdempotencyKey,
            (int)$user['id'],
            $customerId,
            $customerName,
            $calculatedSubtotal,
            $discount,
            $taxRate,
            $taxAmount,
            $grandTotal,
            $paymentMethod,
            $notes,
            $saleDate
        ]);
        $saleId = (int)$db->lastInsertId();
        $invoiceNo = sprintf('INV-%s-%08d', date('Ymd'), $saleId);
        $db->prepare('UPDATE sales SET invoice_no = ? WHERE id = ?')->execute([$invoiceNo, $saleId]);

        $insItem = $db->prepare("
            INSERT INTO sale_items (
                sale_id, product_id, product_name, sku, unit_price, cost_price, quantity, subtotal, line_discount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $deductStock = $db->prepare("
            UPDATE products
            SET stock_quantity = stock_quantity - ?
            WHERE id = ?
        ");

        $insMovement = $db->prepare("
            INSERT INTO stock_movements (
                product_id, user_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes
            ) VALUES (?, ?, 'SALE', ?, ?, ?, ?, ?)
        ");

        foreach ($validatedItems as $v) {
            $insItem->execute([
                $saleId,
                $v['product_id'],
                $v['name'],
                $v['sku'],
                $v['unit_price'],
                $v['cost_price'],
                $v['quantity'],
                $v['line_subtotal'],
                $v['line_discount']
            ]);

            $deductStock->execute([$v['quantity'], $v['product_id']]);

            $insMovement->execute([
                $v['product_id'],
                (int)$user['id'],
                -$v['quantity'],
                $v['previous_stock'],
                $v['new_stock'],
                $invoiceNo,
                "Deducted for Sale {$invoiceNo}"
            ]);
        }

        $db->commit();

        sendJsonResponse(true, [
            'id'             => $invoiceNo,
            'sale_id'        => $saleId,
            'invoice_no'     => $invoiceNo,
            'customer'       => $customerName,
            'customer_id'    => $customerId,
            'date'           => $saleDate,
            'subtotal'       => $calculatedSubtotal,
            'discount'       => $discount,
            'tax_rate'       => $taxRate,
            'tax_amount'     => $taxAmount,
            'grand_total'    => $grandTotal,
            'total'          => $grandTotal,
            'payment_method' => $paymentMethod,
            'items'          => array_map(function($i) {
                return [
                    'name'     => $i['name'],
                    'qty'      => $i['quantity'],
                    'price'    => $i['unit_price'],
                    'subtotal' => $i['line_subtotal']
                ];
            }, $validatedItems),
            'idempotent_replay' => false
        ], "Sale {$invoiceNo} completed successfully. Stock updated.", 201);
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($e instanceof PDOException && $e->getCode() === '23000' && $submittedIdempotencyKey && isset($user['id'])) {
        respondWithExistingSale($db, (int)$user['id'], $submittedIdempotencyKey);
    }
    logSystemError('api/sales.php', $e);
    sendJsonResponse(false, null, 'Transaction failed.', 500);
}
