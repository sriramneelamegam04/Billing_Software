<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

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

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$barcode    = isset($_GET['barcode']) ? trim($_GET['barcode']) : null;

if (!$product_id && !$barcode) {
    sendError("Either product id or barcode is required", 422);
}

/* -------------------------------------------------
   FETCH PRODUCT + PRODUCT INVENTORY
------------------------------------------------- */
$sql = "
    SELECT
        p.id,
        p.name,
        p.price,
        p.gst_rate,
        p.outlet_id,
        p.meta,
        c.name  AS category_name,
        sc.name AS sub_category_name,
        COALESCE(i.quantity,0) AS product_quantity
    FROM products p
    INNER JOIN outlets o ON o.id = p.outlet_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN sub_categories sc ON sc.id = p.sub_category_id
    LEFT JOIN inventory i
        ON i.product_id = p.id
       AND i.variant_id IS NULL
       AND i.org_id = ?
       AND i.outlet_id = p.outlet_id
    WHERE o.org_id = ?
";

$params = [$authUser['org_id'], $authUser['org_id']];

/* -------------------------------------------------
   ROLE BASED FILTER
------------------------------------------------- */
if ($authUser['role'] !== 'admin') {
    $sql .= " AND p.outlet_id = ?";
    $params[] = $authUser['outlet_id'];
}

/* -------------------------------------------------
   PRODUCT FILTER
------------------------------------------------- */
if ($product_id) {
    $sql .= " AND p.id = ?";
    $params[] = $product_id;
} else {
    $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.barcode')) = ?";
    $params[] = $barcode;
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    sendError("Product not found", 404);
}

/* -------------------------------------------------
   FETCH VARIANTS + VARIANT INVENTORY
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        v.id,
        v.name,
        v.price,
        v.gst_rate,
        COALESCE(i.quantity,0) AS quantity
    FROM product_variants v
    LEFT JOIN inventory i
        ON i.variant_id = v.id
       AND i.product_id = v.product_id
       AND i.org_id = ?
       AND i.outlet_id = ?
    WHERE v.product_id = ?
    ORDER BY v.id ASC
");
$stmt->execute([
    $authUser['org_id'],
    $product['outlet_id'],
    $product['id']
]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FORMAT RESPONSE (SEARCH.PHP STYLE)
------------------------------------------------- */
$meta = json_decode($product['meta'], true) ?: [];

$response = [
    "product_id"        => (int)$product['id'],
    "name"              => $product['name'],
    "price"             => (float)$product['price'],
    "gst_rate"          => (float)$product['gst_rate'],
    "category_name"     => $product['category_name'],
    "sub_category_name" => $product['sub_category_name'],
    "outlet_id"         => (int)$product['outlet_id'],
    "quantity"          => (int)$product['product_quantity'],

    // 🔥 FULL META (barcode + brand + size + anything)
    "meta"              => $meta,

    "variants"          => []
];

foreach ($variants as $v) {
    $response['variants'][] = [
        "variant_id" => (int)$v['id'],
        "name"       => $v['name'],
        "price"      => (float)$v['price'],
        "gst_rate"   => (float)$v['gst_rate'],
        "quantity"   => (int)$v['quantity']
    ];
}

sendSuccess($response, "Product fetched successfully");
