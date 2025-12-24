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
   FETCH PRODUCTS + INVENTORY (SKU + BARCODE SEARCH)
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
            OR JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.sku')) LIKE ?
            OR EXISTS (
                SELECT 1 FROM product_variants v
                WHERE v.product_id = p.id
                  AND JSON_UNQUOTE(JSON_EXTRACT(v.meta,'$.sku')) LIKE ?
            )
        )
    ";
    array_push(
        $params,
        "%$q%", "%$q%", "%$q%",
        "%$q%", "%$q%", "%$q%"
    );
}

$sql .= " ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    sendSuccess([], "Search results");
}

/* -------------------------------------------------
   FETCH VARIANTS + INVENTORY
------------------------------------------------- */
$productIds = array_column($rows, 'id');
$placeholders = implode(',', array_fill(0, count($productIds), '?'));

$vStmt = $pdo->prepare("
    SELECT
        v.id,
        v.product_id,
        v.name,
        v.price,
        v.gst_rate,
        v.meta,
        COALESCE(i.quantity,0) AS quantity
    FROM product_variants v
    LEFT JOIN inventory i
        ON i.variant_id = v.id
       AND i.product_id = v.product_id
       AND i.org_id = ?
       AND i.outlet_id = ?
    WHERE v.product_id IN ($placeholders)
");

$vStmt->execute(array_merge(
    [$authUser['org_id'], $outlet_id],
    $productIds
));

$variantRows = $vStmt->fetchAll(PDO::FETCH_ASSOC);


function applyDiscount(float $price, array $discount = null): array
{
    $discount_amount = 0;
    $final_price = $price;

    if ($discount && isset($discount['type'], $discount['value'])) {
        if ($discount['type'] === 'percentage') {
            $discount_amount = round(($price * $discount['value']) / 100, 2);
        } elseif ($discount['type'] === 'flat') {
            $discount_amount = round($discount['value'], 2);
        }

        $final_price = max(0, round($price - $discount_amount, 2));
    }

    return [
        'original_price'   => $price,
        'discount_amount'  => $discount_amount,
        'discounted_price' => $final_price
    ];
}

/* -------------------------------------------------
   GROUP VARIANTS BY PRODUCT (SAFE META)
------------------------------------------------- */
$variantsByProduct = [];

foreach ($variantRows as $v) {

    $vMeta = json_decode($v['meta'], true);
    if (!is_array($vMeta)) $vMeta = [];

    $vd = applyDiscount(
    (float)$v['price'],
    $vMeta['discount'] ?? null
);


    $variantsByProduct[$v['product_id']][] = [
        "variant_id"      => (int)$v['id'],
        "name"            => $v['name'],
        /* PRICES */
    "price"            => $vd['original_price'],
    "discount_amount"  => $vd['discount_amount'],
    "discounted_price" => $vd['discounted_price'],
        "gst_rate"        => (float)$v['gst_rate'],
        "quantity"        => (int)$v['quantity'],

        /* FULL META */
        "meta"            => $vMeta,

        /* CONVENIENCE */
        "barcode"         => $vMeta['barcode'] ?? null,
        "sku"             => $vMeta['sku'] ?? null,
        "purchase_price"  => $vMeta['purchase_price'] ?? null,
        "discount"        => $vMeta['discount'] ?? null
    ];
}

/* -------------------------------------------------
   FINAL RESPONSE
------------------------------------------------- */



$products = [];

foreach ($rows as $r) {

    $meta = json_decode($r['meta'], true);
    if (!is_array($meta)) $meta = [];

    $discountData = applyDiscount(
    (float)$r['price'],
    $meta['discount'] ?? null
);

    $products[] = [
        "product_id"        => (int)$r['id'],
        "name"              => $r['name'],
        /* PRICES */
    "price"            => $discountData['original_price'],
    "discount_amount"  => $discountData['discount_amount'],
    "discounted_price" => $discountData['discounted_price'],
        "gst_rate"          => (float)$r['gst_rate'],
        "outlet_id"         => (int)$r['outlet_id'],
        "quantity"          => (int)$r['product_quantity'],

        /* FULL META */
        "meta"              => $meta,

        /* CONVENIENCE */
        "barcode"           => $meta['barcode'] ?? null,
        "sku"               => $meta['sku'] ?? null,
        "purchase_price"    => $meta['purchase_price'] ?? null,
        "discount"          => $meta['discount'] ?? null,

        "category_name"     => $r['category_name'],
        "sub_category_name" => $r['sub_category_name'],

        "variants"          => $variantsByProduct[$r['id']] ?? []
    ];
}

sendSuccess($products, "Search results");
