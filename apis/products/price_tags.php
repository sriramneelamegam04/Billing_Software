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

$org_id = (int)$authUser['org_id'];

/* -------------------------------------------------
   QUERY PARAMS (SEARCH + PAGINATION)
------------------------------------------------- */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$search      = trim($_GET['search'] ?? '');
$outlet_id   = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;
$has_barcode = isset($_GET['has_barcode']) ? (int)$_GET['has_barcode'] : null;

/* -------------------------------------------------
   WHERE CONDITIONS
------------------------------------------------- */
$where  = ["p.org_id = :org_id"];
$params = ['org_id' => $org_id];

if ($search !== '') {
    $where[] = "(p.name LIKE :search OR v.name LIKE :search)";
    $params['search'] = "%$search%";
}

if ($outlet_id) {
    $where[] = "p.outlet_id = :outlet_id";
    $params['outlet_id'] = $outlet_id;
}

if ($has_barcode === 1) {
    $where[] = "JSON_EXTRACT(p.meta,'$.barcode') IS NOT NULL";
}

$whereSql = implode(" AND ", $where);

/* -------------------------------------------------
   FETCH PRODUCTS + VARIANTS
------------------------------------------------- */
$sql = "
SELECT
    p.id   AS product_id,
    p.name AS product_name,
    p.price AS product_price,
    p.gst_rate AS product_gst,
    JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.barcode')) AS barcode,

    v.id   AS variant_id,
    v.name AS variant_name,
    v.price AS variant_price,
    v.gst_rate AS variant_gst

FROM products p
LEFT JOIN product_variants v ON v.product_id = p.id
WHERE $whereSql
ORDER BY p.name ASC, v.name ASC
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
   BUILD PRICE TAG RESPONSE (FINAL PRICE ONLY)
------------------------------------------------- */
$items = [];

foreach ($rows as $r) {

    $base_price = $r['variant_price'] ?? $r['product_price'];
    $gst_rate   = $r['variant_gst'] ?? $r['product_gst'];

    $base_price = (float)$base_price;
    $gst_rate   = (float)$gst_rate;

    $gst_amount = round(($base_price * $gst_rate) / 100, 2);
    $total      = round($base_price + $gst_amount, 2);

    $items[] = [
        'product_id'   => (int)$r['product_id'],
        'variant_id'   => $r['variant_id'] ? (int)$r['variant_id'] : null,
        'product_name' => $r['product_name'],
        'variant_name' => $r['variant_name'],
        'barcode'      => $r['barcode'],
        'price'        => '₹' . number_format($total, 0) // 👈 TAG PRICE
    ];
}

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess([
    'page'  => $page,
    'limit' => $limit,
    'count' => count($items),
    'items' => $items
], "Product price tags fetched successfully");
