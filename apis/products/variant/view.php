<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';
require_once __DIR__ . '/../../../models/Subscription.php';

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
if (empty($_GET['variant_id'])) {
    sendError("variant_id is required", 422);
}

$variant_id = (int)$_GET['variant_id'];

/* -------------------------------------------------
   FETCH VARIANT + PRODUCT + CATEGORY
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        v.id   AS variant_id,
        v.name AS variant_name,
        v.price,
        v.gst_rate,
        v.created_at,

        p.id   AS product_id,
        p.name AS product_name,
        p.meta,
        p.org_id,
        p.outlet_id,

        c.name  AS category_name,
        sc.name AS sub_category_name
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN sub_categories sc ON sc.id = p.sub_category_id
    WHERE v.id = ?
    LIMIT 1
");
$stmt->execute([$variant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) sendError("Variant not found", 404);
if ((int)$row['org_id'] !== (int)$authUser['org_id']) {
    sendError("Unauthorized access to variant", 403);
}

/* -------------------------------------------------
   FETCH INVENTORY QTY
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT quantity
    FROM inventory
    WHERE product_id = ?
      AND variant_id = ?
      AND org_id = ?
      AND outlet_id = ?
    LIMIT 1
");
$stmt->execute([
    $row['product_id'],
    $variant_id,
    $authUser['org_id'],
    $row['outlet_id']
]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

$quantity = $inv ? (int)$inv['quantity'] : 0;

/* -------------------------------------------------
   FORMAT RESPONSE
------------------------------------------------- */
$meta = json_decode($row['meta'], true) ?: [];

sendSuccess([
    "variant" => [
        "variant_id" => (int)$row['variant_id'],
        "name"       => $row['variant_name'],
        "price"      => (float)$row['price'],
        "gst_rate"   => (float)$row['gst_rate'],
        "quantity"   => $quantity,
        "created_at" => $row['created_at']
    ],
    "product" => [
        "product_id"        => (int)$row['product_id'],
        "name"              => $row['product_name'],
        "barcode"           => $meta['barcode'] ?? null,
        "category_name"     => $row['category_name'],
        "sub_category_name" => $row['sub_category_name'],
        "outlet_id"         => (int)$row['outlet_id']
    ]
], "Variant details fetched successfully");
