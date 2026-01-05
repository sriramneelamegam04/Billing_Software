<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../helpers/barcode.php';
require_once __DIR__.'/../../models/Product.php';
require_once __DIR__.'/../../models/ProductVariant.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendError("Method Not Allowed", 405);

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
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON input");

foreach (['product_id','outlet_id'] as $f) {
    if (!isset($input[$f])) sendError("Field '$f' is required", 422);
}

$product_id = (int)$input['product_id'];
$outlet_id  = (int)$input['outlet_id'];

$name            = $input['name']            ?? null;
$price           = isset($input['price']) ? (float)$input['price'] : null;
$category_id     = $input['category_id']     ?? null;
$sub_category_id = $input['sub_category_id'] ?? null;
$gst_rate        = isset($input['gst_rate']) ? (float)$input['gst_rate'] : null;

/* -------------------------------------------------
   BASIC VALIDATION
------------------------------------------------- */
if ($price !== null && $price <= 0) sendError("Invalid price", 422);
if ($gst_rate !== null && ($gst_rate < 0 || $gst_rate > 100)) {
    sendError("Invalid gst_rate", 422);
}

/* -------------------------------------------------
   VALIDATE PRODUCT
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT * FROM products
    WHERE id=? AND outlet_id=? AND org_id=?
");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) sendError("Product not found", 404);

$productMeta = json_decode($existing['meta'], true) ?: [];

/* -----------------------------
   AUTO META FIELDS (PRODUCT)
----------------------------- */
$productMetaFields = [
    'brand',
    'size',
    'dealer',
    'sku',
    'description',
    'purchase_price'
];

foreach ($productMetaFields as $field) {
    if (isset($input[$field]) && $input[$field] !== '') {
        $productMeta[$field] = $input[$field];
    }
}

/* -----------------------------
   DISCOUNT
----------------------------- */
if (!empty($input['discount_type']) && !empty($input['discount_value'])) {
    $productMeta['discount'] = [
        'type'  => $input['discount_type'],
        'value' => (float)$input['discount_value']
    ];
}

/* -----------------------------
   RAW META MERGE (OPTIONAL)
----------------------------- */
if (!empty($input['meta']) && is_array($input['meta'])) {
    $productMeta = array_merge($productMeta, $input['meta']);
}

/* PRODUCT BARCODE */
if (!empty($input['barcode'])) {
    $productMeta['barcode'] = preg_replace('/\s+/', '', $input['barcode']);
}

/* -------------------------------------------------
   DUPLICATE NAME CHECK
------------------------------------------------- */
if (!empty($name)) {
    $stmt = $pdo->prepare("
        SELECT id FROM products
        WHERE org_id=? AND outlet_id=? AND LOWER(name)=LOWER(?) AND id!=?
        LIMIT 1
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $name,
        $product_id
    ]);

    if ($stmt->fetchColumn()) {
        sendError("Product already exists with the same name in this outlet", 409);
    }
}

/* -------------------------------------------------
   FINAL PRODUCT VALUES
------------------------------------------------- */
$final = [
    'name'            => $name            ?? $existing['name'],
    'price'           => $price           ?? $existing['price'],
    'gst_rate'        => $gst_rate        ?? $existing['gst_rate'],
    'category_id'     => $category_id     ?? $existing['category_id'],
    'sub_category_id' => $sub_category_id ?? $existing['sub_category_id'],
    'meta'            => json_encode($productMeta, JSON_UNESCAPED_UNICODE)
];

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       UPDATE PRODUCT
    ------------------------------------------------- */
    $pdo->prepare("
        UPDATE products SET
            name=?, price=?, gst_rate=?, category_id=?, sub_category_id=?, meta=?
        WHERE id=? AND outlet_id=? AND org_id=?
    ")->execute([
        $final['name'],
        $final['price'],
        $final['gst_rate'],
        $final['category_id'],
        $final['sub_category_id'],
        $final['meta'],
        $product_id,
        $outlet_id,
        $authUser['org_id']
    ]);

    /* -------------------------------------------------
   VARIANTS (SAFE REPLACE MODE) – FINAL
------------------------------------------------- */
$variantResponses = [];

if (!empty($input['variants']) && is_array($input['variants'])) {

    // 1️⃣ delete inventory_logs
    $pdo->prepare("
        DELETE il FROM inventory_logs il
        JOIN product_variants pv ON pv.id = il.variant_id
        WHERE pv.product_id = ?
    ")->execute([$product_id]);

    // 2️⃣ delete inventory (variant rows)
    $pdo->prepare("
        DELETE FROM inventory
        WHERE product_id=? AND variant_id IS NOT NULL
    ")->execute([$product_id]);

    // 3️⃣ delete variants
    $pdo->prepare("
        DELETE FROM product_variants
        WHERE product_id=?
    ")->execute([$product_id]);

    $variantModel = new ProductVariant($pdo);

    foreach ($input['variants'] as $v) {

        if (empty($v['name']) || empty($v['price'])) continue;

        $variant_id = $variantModel->create([
            'product_id' => $product_id,
            'name'       => trim($v['name']),
            'price'      => (float)$v['price'],
            'gst_rate'   => (float)($v['gst_rate'] ?? 0),
            'meta'       => '{}'
        ]);

        // barcode
        $barcode = !empty($v['barcode'])
            ? preg_replace('/\s+/', '', $v['barcode'])
            : generate_barcode($authUser['org_id'], $product_id, $variant_id);

        // update meta
        $pdo->prepare("
            UPDATE product_variants SET meta=?
            WHERE id=?
        ")->execute([
            json_encode(['barcode' => $barcode], JSON_UNESCAPED_UNICODE),
            $variant_id
        ]);

        // ✅ INSERT INVENTORY (ONLY ONCE)
        $pdo->prepare("
            INSERT INTO inventory
            (org_id,outlet_id,product_id,variant_id,quantity,low_stock_limit)
            VALUES (?,?,?,?,?,?)
        ")->execute([
            $authUser['org_id'],
            $outlet_id,
            $product_id,
            $variant_id,
            (int)($v['quantity'] ?? 0),
            isset($v['low_stock_limit']) ? (int)$v['low_stock_limit'] : null
        ]);

        $variantResponses[] = [
            'variant_id' => $variant_id,
            'name'       => trim($v['name']),
            'barcode'    => $barcode
        ];
    }
}

    /* -------------------------------------------------
   UPDATE PRODUCT INVENTORY (LOW STOCK)
------------------------------------------------- */
if (array_key_exists('low_stock_limit', $input) || array_key_exists('quantity', $input)) {

    // check inventory row exists
    $stmt = $pdo->prepare("
        SELECT id FROM inventory
        WHERE org_id=? AND outlet_id=? AND product_id=? AND variant_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $product_id
    ]);
    $invId = $stmt->fetchColumn();

    if ($invId) {
        $pdo->prepare("
            UPDATE inventory
            SET
                quantity = COALESCE(?, quantity),
                low_stock_limit = COALESCE(?, low_stock_limit)
            WHERE id=?
        ")->execute([
            $input['quantity'] ?? null,
            isset($input['low_stock_limit']) ? (int)$input['low_stock_limit'] : null,
            $invId
        ]);
    }
}


    $pdo->commit();

    sendSuccess([
        "product_id" => $product_id,
        "barcode"    => $productMeta['barcode'] ?? null,
        "variants"   => $variantResponses
    ], "Product updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to update product: ".$e->getMessage(), 500);
}
