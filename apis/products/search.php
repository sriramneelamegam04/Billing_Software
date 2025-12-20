<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendError("Method Not Allowed", 405);

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
$q         = isset($_GET['q']) ? trim($_GET['q']) : '';
$outlet_id = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : 0;

if (!$outlet_id) sendError("Parameter 'outlet_id' is required", 422);

/* -------------------------------------------------
   VALIDATE OUTLET
------------------------------------------------- */
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet_id", 403);

/* -------------------------------------------------
   FETCH PRODUCTS + PRODUCT INVENTORY
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
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN sub_categories sc ON sc.id = p.sub_category_id
    LEFT JOIN inventory i
        ON i.product_id = p.id
       AND i.variant_id IS NULL
       AND i.org_id = ?
       AND i.outlet_id = p.outlet_id
    WHERE p.org_id=? AND p.outlet_id=?
";
$params = [$authUser['org_id'], $authUser['org_id'], $outlet_id];

if ($q !== '') {
    $sql .= "
        AND (
            p.name LIKE ?
            OR c.name LIKE ?
            OR sc.name LIKE ?
            OR JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.barcode')) LIKE ?
        )
    ";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

$sql .= " ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    sendSuccess([], "Search results");
}

/* -------------------------------------------------
   FETCH VARIANTS + VARIANT INVENTORY
------------------------------------------------- */
$productIds = array_column($rows, 'id');
$in = implode(',', array_fill(0, count($productIds), '?'));

$vStmt = $pdo->prepare("
    SELECT
        v.id,
        v.product_id,
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
    WHERE v.product_id IN ($in)
");
$vStmt->execute(array_merge(
    [$authUser['org_id'], $outlet_id],
    $productIds
));
$variantRows = $vStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   GROUP VARIANTS BY PRODUCT
------------------------------------------------- */
$variantsByProduct = [];
foreach ($variantRows as $v) {
    $variantsByProduct[$v['product_id']][] = [
        "variant_id" => (int)$v['id'],
        "name"       => $v['name'],
        "price"      => (float)$v['price'],
        "gst_rate"   => (float)$v['gst_rate'],
        "quantity"   => (int)$v['quantity']
    ];
}

/* -------------------------------------------------
   FINAL RESPONSE (FULL META)
------------------------------------------------- */
$products = [];

foreach ($rows as $r) {

    $meta = json_decode($r['meta'], true) ?: [];

    $products[] = [
        "product_id"        => (int)$r['id'],
        "name"              => $r['name'],
        "price"             => (float)$r['price'],
        "gst_rate"          => (float)$r['gst_rate'],

        // 🔥 FULL META OBJECT
        "meta"              => $meta,

        // 🔥 kept for convenience
        "barcode"           => $meta['barcode'] ?? null,

        "category_name"     => $r['category_name'],
        "sub_category_name" => $r['sub_category_name'],
        "outlet_id"         => (int)$r['outlet_id'],
        "quantity"          => (int)$r['product_quantity'],
        "variants"          => $variantsByProduct[$r['id']] ?? []
    ];
}

sendSuccess($products, "Search results");
