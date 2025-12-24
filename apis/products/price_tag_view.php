<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

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
   INPUT
------------------------------------------------- */
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

if (!$product_id) {
    sendError("product_id is required", 422);
}

/* -------------------------------------------------
   FETCH PRODUCT (WITH META)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        name,
        price,
        gst_rate,
        meta,
        JSON_UNQUOTE(JSON_EXTRACT(meta,'$.barcode')) AS barcode,
        JSON_UNQUOTE(JSON_EXTRACT(meta,'$.sku'))     AS sku,
        JSON_UNQUOTE(JSON_EXTRACT(meta,'$.size'))    AS size
    FROM products
    WHERE id=? AND org_id=?
");
$stmt->execute([$product_id, $org_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    sendError('Product not found', 404);
}

/* -------------------------------------------------
   PRICE CALC FN
------------------------------------------------- */
function finalPrice(float $price, float $gst): float {
    return round($price + (($price * $gst) / 100), 2);
}

/* -------------------------------------------------
   PRODUCT FINAL PRICE
------------------------------------------------- */
$productFinal = finalPrice(
    (float)$product['price'],
    (float)$product['gst_rate']
);

/* -------------------------------------------------
   FETCH VARIANTS (WITH META)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        price,
        gst_rate,
        meta,
        JSON_UNQUOTE(JSON_EXTRACT(meta,'$.barcode')) AS barcode,
        JSON_UNQUOTE(JSON_EXTRACT(meta,'$.sku'))     AS sku,
        JSON_UNQUOTE(JSON_EXTRACT(meta,'$.size'))    AS size
    FROM product_variants
    WHERE product_id=?
    ORDER BY id ASC
");
$stmt->execute([$product_id]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   BUILD RESPONSE
------------------------------------------------- */
$response = [
    "product" => [
        "product_id" => $product_id,
        "name"       => $product['name'],
        "sku"        => $product['sku'] ?? null,
        "size"       => $product['size'] ?? null,
        "price_tag"  => [
            "final_price" => "₹" . number_format($productFinal, 0),
            "barcode"     => $product['barcode']
        ]
    ],
    "variants" => []
];

foreach ($variants as $v) {

    $variantFinal = finalPrice(
        (float)$v['price'],
        (float)$v['gst_rate']
    );

    $response['variants'][] = [
        "variant_id" => (int)$v['id'],
        "name"       => $v['name'],
        "sku"        => $v['sku'] ?? null,
        "size"       => $v['size'] ?? null,
        "price_tag"  => [
            "final_price" => "₹" . number_format($variantFinal, 0),
            "barcode"     => $v['barcode']
        ]
    ];
}

sendSuccess($response, "Price tags fetched successfully");
