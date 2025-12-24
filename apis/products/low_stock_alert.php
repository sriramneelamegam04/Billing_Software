<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../models/Subscription.php';

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
   AUTH + SUBSCRIPTION
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$org_id = (int)$authUser['org_id'];

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($org_id)) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   PAGINATION + FILTERS
------------------------------------------------- */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$outlet_id = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;

/* -------------------------------------------------
   WHERE CONDITIONS
------------------------------------------------- */
$where  = [
    "i.org_id = :org_id",
    "i.low_stock_limit IS NOT NULL",
    "i.quantity <= i.low_stock_limit"
];
$params = ['org_id' => $org_id];

if ($outlet_id) {
    $where[] = "i.outlet_id = :outlet_id";
    $params['outlet_id'] = $outlet_id;
}

$whereSql = implode(" AND ", $where);

/* -------------------------------------------------
   TOTAL LOW STOCK COUNT (IMPORTANT ADD)
------------------------------------------------- */
$countSql = "
    SELECT COUNT(*)
    FROM inventory i
    WHERE $whereSql
";

$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) {
    $countStmt->bindValue(":$k", $v);
}
$countStmt->execute();
$totalLowStock = (int)$countStmt->fetchColumn();

/* -------------------------------------------------
   FETCH LOW STOCK LIST
------------------------------------------------- */
$sql = "
SELECT
    p.id AS product_id,
    p.name AS product_name,
    JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.barcode')) AS barcode,

    v.id AS variant_id,
    v.name AS variant_name,

    i.quantity,
    i.low_stock_limit,
    i.outlet_id

FROM inventory i
JOIN products p ON p.id = i.product_id
LEFT JOIN product_variants v ON v.id = i.variant_id

WHERE
    $whereSql

ORDER BY i.quantity ASC
LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $k => $v) {
    $stmt->bindValue(":$k", $v);
}
$stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
$stmt->bindValue(":offset", $offset, PDO::PARAM_INT);

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FORMAT RESPONSE
------------------------------------------------- */
$items = [];

foreach ($rows as $r) {
    $items[] = [
        "product_id"      => (int)$r['product_id'],
        "product_name"    => $r['product_name'],
        "variant_id"      => $r['variant_id'] ? (int)$r['variant_id'] : null,
        "variant_name"    => $r['variant_name'],
        "barcode"         => $r['barcode'],
        "outlet_id"       => (int)$r['outlet_id'],
        "quantity"        => (int)$r['quantity'],
        "low_stock_limit" => (int)$r['low_stock_limit'],
        "status"          => "LOW_STOCK"
    ];
}

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess([
    "page"             => $page,
    "limit"            => $limit,
    "count"            => count($items),        // current page count
    "total_low_stock"  => $totalLowStock,       // 🔥 FULL LOW STOCK COUNT
    "items"            => $items
], "Low stock alert list fetched successfully");
