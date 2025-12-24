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
   QUERY PARAMS
------------------------------------------------- */
$search    = trim($_GET['search'] ?? '');
$outlet_id = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;

/* -------------------------------------------------
   FETCH PRODUCTS
------------------------------------------------- */
$where  = ["p.org_id = :org_id"];
$params = ['org_id' => $org_id];

if ($outlet_id) {
    $where[] = "p.outlet_id = :outlet_id";
    $params['outlet_id'] = $outlet_id;
}

if ($search !== '') {
    $where[] = "(
        p.name LIKE :search
        OR JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.sku')) LIKE :search
    )";
    $params['search'] = "%$search%";
}

$whereSql = implode(" AND ", $where);

$sql = "
SELECT
    p.id,
    p.name,
    p.price,
    p.gst_rate,
    p.meta
FROM products p
WHERE $whereSql
ORDER BY p.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$products) {
    sendSuccess(['products' => []], "Product price tags fetched successfully");
}

/* -------------------------------------------------
   FETCH VARIANTS (ALL AT ONCE)
------------------------------------------------- */
$productIds = array_column($products, 'id');
$placeholders = implode(',', array_fill(0, count($productIds), '?'));

$vStmt = $pdo->prepare("
    SELECT
        id,
        product_id,
        name,
        price,
        gst_rate,
        meta
    FROM product_variants
    WHERE product_id IN ($placeholders)
    ORDER BY id ASC
");
$vStmt->execute($productIds);
$variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   GROUP VARIANTS BY PRODUCT
------------------------------------------------- */
$variantMap = [];

foreach ($variants as $v) {
    $meta = json_decode($v['meta'], true) ?: [];

    $gstAmount = round(($v['price'] * $v['gst_rate']) / 100, 2);
    $final     = round($v['price'] + $gstAmount, 2);

    $variantMap[$v['product_id']][] = [
        'variant_id' => (int)$v['id'],
        'name'       => $v['name'],
        'sku'        => $meta['sku'] ?? null,
        'size'       => $meta['size'] ?? null,
        'barcode'    => $meta['barcode'] ?? null,
        'price_tag'  => [
            'price' => '₹' . number_format($final, 0)
        ]
    ];
}

/* -------------------------------------------------
   BUILD FINAL RESPONSE
------------------------------------------------- */
$response = [];

foreach ($products as $p) {

    $meta = json_decode($p['meta'], true) ?: [];

    $gstAmount = round(($p['price'] * $p['gst_rate']) / 100, 2);
    $final     = round($p['price'] + $gstAmount, 2);

    $response[] = [
        'product_id' => (int)$p['id'],
        'name'       => $p['name'],
        'sku'        => $meta['sku'] ?? null,
        'size'       => $meta['size'] ?? null,
        'barcode'    => $meta['barcode'] ?? null,
        'price_tag'  => [
            'price' => '₹' . number_format($final, 0)
        ],
        'variants'   => $variantMap[$p['id']] ?? []
    ];
}

sendSuccess([
    'products' => $response
], "Product price tags fetched successfully");
