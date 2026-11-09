<?php
/**
 * SumYam Retail - Analytics & Business Intelligence API
 * Maintainer: Saurav Shah Teli
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

try {
    $db = getDBConnection();
    requireApiRole($db);
    $today = date('Y-m-d');

    // 1. Core Summary Metrics
    $prodMetrics = $db->query("
        SELECT
            COUNT(*) AS total_products,
            COALESCE(SUM(stock_quantity), 0) AS total_units,
            COALESCE(SUM(stock_quantity * selling_price), 0) AS retail_valuation,
            COUNT(CASE WHEN stock_quantity <= 0 THEN 1 END) AS out_of_stock,
            COUNT(CASE WHEN stock_quantity > 0 AND stock_quantity <= min_stock_level THEN 1 END) AS low_stock,
            COUNT(CASE WHEN stock_quantity > min_stock_level THEN 1 END) AS healthy_stock
        FROM products
        WHERE status = 'active'
    ")->fetch();

    $catCount = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $custCount = (int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();

    $salesMetrics = $db->query("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(grand_total), 0) AS total_revenue,
            COALESCE(AVG(grand_total), 0) AS average_bill
        FROM sales
        WHERE status = 'completed'
    ")->fetch();

    $todaySales = $db->prepare("
        SELECT
            COUNT(*) AS today_orders,
            COALESCE(SUM(grand_total), 0) AS today_revenue
        FROM sales
        WHERE sale_date = ? AND status = 'completed'
    ");
    $todaySales->execute([$today]);
    $todayData = $todaySales->fetch();

    // This estimate uses the historical per-sale cost snapshot; costing policy is not yet verified.
    $profitRow = $db->query("
        SELECT
            COALESCE(SUM((si.unit_price * si.quantity) - si.line_discount - (si.cost_price * si.quantity)), 0) AS gross_profit_estimate
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id
        WHERE s.status = 'completed'
    ")->fetch();

    // 2. 7-Day Sales Trend (Last 7 days including today)
    $salesChart = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = new DateTime();
        $d->modify("-{$i} days");
        $dateStr = $d->format('Y-m-d');
        $label = $d->format('D'); // e.g. Mon, Tue

        $dayStmt = $db->prepare("
            SELECT COALESCE(SUM(grand_total), 0) AS revenue, COUNT(*) AS count
            FROM sales
            WHERE sale_date = ? AND status = 'completed'
        ");
        $dayStmt->execute([$dateStr]);
        $dayRow = $dayStmt->fetch();

        $salesChart[] = [
            'date'    => $dateStr,
            'label'   => $label,
            'revenue' => (float)$dayRow['revenue'],
            'orders'  => (int)$dayRow['count']
        ];
    }

    // 3. Top Selling Products
    $topStmt = $db->query("
        SELECT
            si.product_name AS name,
            si.sku,
            COALESCE(SUM(si.quantity), 0) AS qty_sold,
            COALESCE(SUM(si.subtotal - si.line_discount), 0) AS revenue
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id AND s.status = 'completed'
        GROUP BY si.product_id, si.product_name, si.sku
        ORDER BY qty_sold DESC
        LIMIT 6
    ");
    $topProducts = $topStmt->fetchAll();

    // 4. Category Distribution
    $catStmt = $db->query("
        SELECT
            COALESCE(c.name, 'Uncategorized') AS category,
            COUNT(p.id) AS product_count,
            COALESCE(SUM(p.stock_quantity), 0) AS total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 'active'
        GROUP BY c.id, c.name
        ORDER BY product_count DESC
    ");
    $categories = $catStmt->fetchAll();

    // 5. Recent Sales (Latest 5)
    $recentStmt = $db->query("
        SELECT
            s.id,
            s.invoice_no,
            s.customer_name AS customer,
            s.sale_date AS date,
            s.grand_total AS total,
            s.status,
            COALESCE(SUM(si.quantity), 0) AS items_qty
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE s.status = 'completed'
        GROUP BY s.id
        ORDER BY s.id DESC
        LIMIT 5
    ");
    $recentSales = $recentStmt->fetchAll();

    // 6. Low Stock Alerts List
    $alertStmt = $db->query("
        SELECT
            p.id,
            p.name,
            p.sku,
            COALESCE(c.name, 'General') AS category,
            p.stock_quantity,
            p.min_stock_level,
            CASE WHEN p.stock_quantity <= 0 THEN 'out' ELSE 'low' END AS status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.stock_quantity <= p.min_stock_level AND p.status = 'active'
        ORDER BY p.stock_quantity ASC
    ");
    $lowStockAlerts = $alertStmt->fetchAll();

    sendJsonResponse(true, [
        'summary' => [
            'total_products'         => (int)$prodMetrics['total_products'],
            'total_units'            => (int)$prodMetrics['total_units'],
            'total_categories'       => $catCount,
            'total_customers'        => $custCount,
            'total_revenue'          => (float)$salesMetrics['total_revenue'],
            'total_orders'           => (int)$salesMetrics['total_orders'],
            'average_bill'           => (float)$salesMetrics['average_bill'],
            'today_revenue'          => (float)$todayData['today_revenue'],
            'today_orders'           => (int)$todayData['today_orders'],
            'gross_profit'           => null,
            'gross_profit_estimate'  => (float)$profitRow['gross_profit_estimate'],
            'gross_profit_is_verified' => false,
            'gross_profit_limitation' => 'Estimate uses stored sale cost snapshots; inventory costing policy and cost-basis maintenance are not verified.',
            'inventory_retail_value' => (float)$prodMetrics['retail_valuation'],
            'inventory_cost_value'   => null,
            'inventory_cost_value_is_verified' => false,
            'inventory_cost_limitation' => 'Product cost prices are not maintained from received purchase costs; inventory cost valuation is unavailable.',
            'healthy_stock_count'    => (int)$prodMetrics['healthy_stock'],
            'low_stock_count'        => (int)$prodMetrics['low_stock'],
            'out_of_stock_count'     => (int)$prodMetrics['out_of_stock']
        ],
        'chart_7days'       => $salesChart,
        'top_products'      => $topProducts,
        'category_breakdown'=> $categories,
        'recent_sales'      => $recentSales,
        'low_stock_alerts'  => $lowStockAlerts
    ], 'Analytics data aggregated successfully.');

} catch (Throwable $e) {
    logSystemError('api/analytics.php', $e);
    sendJsonResponse(false, null, 'Analytics calculation failed.', 500);
}
