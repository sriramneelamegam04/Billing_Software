<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$org_id    = (int)($_REQUEST['org_id'] ?? 0);
$outlet_id = $_REQUEST['outlet_id'] ?? null;
$date_from = $_REQUEST['date_from'] ?? null;
$date_to   = $_REQUEST['date_to'] ?? null;

// Role filter
if ($authUser['role'] === 'manager') {
    $org_id = $authUser['org_id'];
    if (!empty($outlet_id) && $outlet_id != $authUser['outlet_id']) {
        sendError("Forbidden: cannot access other outlets", 403);
    }
    $outlet_id = $authUser['outlet_id'];
}

try {
    if ($org_id <= 0) sendError("org_id is required", 422);

    // Build WHERE + params for each scope
    $whereSales = "s.org_id = :org_id";
    $whereCust  = "c.org_id = :org_id";
    $whereProd  = "p.org_id = :org_id";

    $paramsSales = [':org_id' => $org_id];
    $paramsCust  = [':org_id' => $org_id];
    $paramsProd  = [':org_id' => $org_id];

    if ($outlet_id) {
        $whereSales .= " AND s.outlet_id = :outlet_id";
        $whereCust  .= " AND c.outlet_id = :outlet_id";
        $whereProd  .= " AND p.outlet_id = :outlet_id";
        $paramsSales[':outlet_id'] = $outlet_id;
        $paramsCust[':outlet_id']  = $outlet_id;
        $paramsProd[':outlet_id']  = $outlet_id;
    }
    if ($date_from) {
        $whereSales .= " AND DATE(s.created_at) >= :df";
        $paramsSales[':df'] = $date_from;
    }
    if ($date_to) {
        $whereSales .= " AND DATE(s.created_at) <= :dt";
        $paramsSales[':dt'] = $date_to;
    }

    // Customers count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereCust");
    $stmt->execute($paramsCust);
    $customers = (int)$stmt->fetchColumn();

    // Products count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $whereProd");
    $stmt->execute($paramsProd);
    $products = (int)$stmt->fetchColumn();

    // Sales + Discounts
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.amount),0) AS items_amount,
               COALESCE(SUM(s.discount),0) AS discount
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE $whereSales
    ");
    $stmt->execute($paramsSales);
    $row = $stmt->fetch();
    $items_amount = (float)$row['items_amount'];
    $discount     = (float)$row['discount'];
    $net_sales    = $items_amount - $discount;

    // Collections
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.amount),0)
        FROM sales s
        LEFT JOIN payments p ON p.sale_id = s.id
        WHERE $whereSales
    ");
    $stmt->execute($paramsSales);
    $collections = (float)$stmt->fetchColumn();
    $outstanding = $net_sales - $collections;

    // Top Products
    $stmt = $pdo->prepare("
        SELECT si.product_id, pr.name AS product_name,
               SUM(si.quantity) AS qty, SUM(si.amount) AS amount
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        JOIN products pr   ON pr.id = si.product_id
        WHERE $whereSales
        GROUP BY si.product_id, pr.name
        ORDER BY qty DESC
        LIMIT 5
    ");
    $stmt->execute($paramsSales);
    $top_products = $stmt->fetchAll();

    // Sales Summary
    $stmt = $pdo->prepare("
        SELECT DATE(s.created_at) as sdate,
               COUNT(DISTINCT s.id) as bills,
               COALESCE(SUM(si.amount),0) as sales_amount,
               COALESCE(SUM(s.discount),0) as discount
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE $whereSales
        GROUP BY DATE(s.created_at)
        ORDER BY sdate ASC
    ");
    $stmt->execute($paramsSales);
    $sales_summary = $stmt->fetchAll();

    // Profit & Loss
    $stmt = $pdo->prepare("
        SELECT s.outlet_id, o.name AS outlet_name,
               COALESCE(SUM(si.amount),0) AS items_amount,
               COALESCE(SUM(s.discount),0) AS discount
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        JOIN outlets o ON o.id = s.outlet_id
        WHERE $whereSales
        GROUP BY s.outlet_id, o.name
    ");
    $stmt->execute($paramsSales);
    $pl_sales = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT s.outlet_id, COALESCE(SUM(p.amount),0) AS collections
        FROM sales s
        LEFT JOIN payments p ON p.sale_id = s.id
        WHERE $whereSales
        GROUP BY s.outlet_id
    ");
    $stmt->execute($paramsSales);
    $pl_collect = $stmt->fetchAll();
    $cmap = [];
    foreach ($pl_collect as $r) $cmap[$r['outlet_id']] = $r['collections'];

    $profit_loss = [];
    foreach ($pl_sales as $r) {
        $net = $r['items_amount'] - $r['discount'];
        $col = $cmap[$r['outlet_id']] ?? 0;
        $profit_loss[] = [
            'outlet_id'   => $r['outlet_id'],
            'outlet_name' => $r['outlet_name'],
            'net_sales'   => $net,
            'collections' => $col,
            'outstanding' => $net - $col
        ];
    }

    // Inventory
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.category,
               COALESCE(SUM(si.quantity),0) AS sold_qty,
               COALESCE(SUM(si.amount),0)   AS sold_value
        FROM products p
        LEFT JOIN sale_items si ON si.product_id = p.id
        LEFT JOIN sales s ON s.id = si.sale_id
        WHERE $whereProd
        GROUP BY p.id, p.name, p.category
        ORDER BY sold_value DESC
        LIMIT 10
    ");
    $stmt->execute($paramsProd);
    $inventory = $stmt->fetchAll();

    sendSuccess("Dashboard data", [
        'kpis' => [
            'customers'   => $customers,
            'products'    => $products,
            'total_sales' => $items_amount,
            'discount'    => $discount,
            'net_sales'   => $net_sales,
            'collections' => $collections,
            'outstanding' => $outstanding
        ],
        'sales_summary' => $sales_summary,
        'top_products'  => $top_products,
        'profit_loss'   => $profit_loss,
        'inventory'     => $inventory
    ]);
} catch (Throwable $e) {
    sendError($e->getMessage(), 500);
}
