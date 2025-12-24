<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Method Not Allowed. Use GET", 405);
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$org_id    = (int)($_REQUEST['org_id'] ?? 0);
$outlet_id = $_REQUEST['outlet_id'] ?? null;
$date_from = $_REQUEST['date_from'] ?? null;
$date_to   = $_REQUEST['date_to'] ?? null;

/* -------------------------------------------------
   ROLE RESTRICTION
------------------------------------------------- */
if ($authUser['role'] === 'manager') {
    $org_id = $authUser['org_id'];
    if (!empty($outlet_id) && $outlet_id != $authUser['outlet_id']) {
        sendError("Forbidden: cannot access other outlets", 403);
    }
    $outlet_id = $authUser['outlet_id'];
}

if ($org_id <= 0) sendError("org_id required", 422);

/* -------------------------------------------------
   WHERE CONDITIONS
------------------------------------------------- */
$where = "s.org_id = :org_id";
$params = [':org_id' => $org_id];

if ($outlet_id) {
    $where .= " AND s.outlet_id = :outlet_id";
    $params[':outlet_id'] = $outlet_id;
}
if ($date_from) {
    $where .= " AND DATE(s.created_at) >= :df";
    $params[':df'] = $date_from;
}
if ($date_to) {
    $where .= " AND DATE(s.created_at) <= :dt";
    $params[':dt'] = $date_to;
}

/* -------------------------------------------------
   SALES + COST (PURCHASE PRICE FROM META)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        s.outlet_id,
        o.name AS outlet_name,

        COALESCE(SUM(si.amount),0) AS sales_amount,
        COALESCE(SUM(s.discount),0) AS discount,

        COALESCE(SUM(
            si.quantity *
            COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(v.meta,'$.purchase_price')) AS DECIMAL(10,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.purchase_price')) AS DECIMAL(10,2)),
                0
            )
        ),0) AS cost_amount

    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    JOIN outlets o ON o.id = s.outlet_id
    JOIN products p ON p.id = si.product_id
    LEFT JOIN product_variants v ON v.id = si.variant_id

    WHERE $where
    GROUP BY s.outlet_id, o.name
");
$stmt->execute($params);
$salesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   COLLECTIONS
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        s.outlet_id,
        COALESCE(SUM(pay.amount),0) AS collections
    FROM sales s
    LEFT JOIN payments pay ON pay.sale_id = s.id
    WHERE $where
    GROUP BY s.outlet_id
");
$stmt->execute($params);

$collectionsMap = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $collectionsMap[$r['outlet_id']] = (float)$r['collections'];
}

/* -------------------------------------------------
   FINAL FORMAT
------------------------------------------------- */
$rows = [];
foreach ($salesRows as $r) {

    $sales      = (float)$r['sales_amount'];
    $discount   = (float)$r['discount'];
    $netSales   = $sales - $discount;
    $cost       = (float)$r['cost_amount'];
    $profit     = $netSales - $cost;
    $collected  = $collectionsMap[$r['outlet_id']] ?? 0;

    $rows[] = [
        'outlet_id'     => (int)$r['outlet_id'],
        'outlet_name'   => $r['outlet_name'],

        'sales_amount'  => $sales,
        'discount'      => $discount,
        'net_sales'     => $netSales,

        'cost_amount'   => $cost,
        'gross_profit'  => $profit,

        'collections'   => $collected,
        'outstanding'   => $netSales - $collected
    ];
}

/* -------------------------------------------------
   TOTALS
------------------------------------------------- */
$totals = [
    'sales_amount' => array_sum(array_column($rows, 'sales_amount')),
    'discount'     => array_sum(array_column($rows, 'discount')),
    'net_sales'    => array_sum(array_column($rows, 'net_sales')),
    'cost_amount'  => array_sum(array_column($rows, 'cost_amount')),
    'gross_profit' => array_sum(array_column($rows, 'gross_profit')),
    'collections'  => array_sum(array_column($rows, 'collections')),
    'outstanding'  => array_sum(array_column($rows, 'outstanding')),
];

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess("Profit & Loss Report", [
    'rows'   => $rows,
    'totals' => $totals
]);
