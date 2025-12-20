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

$name            = array_key_exists('name', $input) ? trim($input['name']) : null;
$price           = array_key_exists('price', $input) ? (float)$input['price'] : null;
$category_id     = array_key_exists('category_id', $input) ? (int)$input['category_id'] : null;
$sub_category_id = array_key_exists('sub_category_id', $input) ? (int)$input['sub_category_id'] : null;
$gst_rate        = array_key_exists('gst_rate', $input) ? (float)$input['gst_rate'] : null;

/* -------------------------------------------------
   BASIC VALIDATION
------------------------------------------------- */
if ($price !== null && $price <= 0) sendError("Invalid price", 422);
if ($gst_rate !== null && ($gst_rate < 0 || $gst_rate > 100)) {
    sendError("Invalid gst_rate", 422);
}

/* -------------------------------------------------
   VALIDATE OUTLET
------------------------------------------------- */
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet", 403);

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

/* -------------------------------------------------
   VALIDATE CATEGORY (OPTIONAL)
------------------------------------------------- */
if ($category_id !== null) {
    $stmt = $pdo->prepare("
        SELECT id,name FROM categories
        WHERE id=? AND org_id=? AND status=1
    ");
    $stmt->execute([$category_id, $authUser['org_id']]);
    if (!$stmt->fetch()) sendError("Invalid category_id", 422);
}

/* -------------------------------------------------
   VALIDATE SUB CATEGORY (OPTIONAL)
------------------------------------------------- */
if ($sub_category_id !== null) {
    $stmt = $pdo->prepare("
        SELECT id FROM sub_categories
        WHERE id=? AND category_id=? AND status=1
    ");
    $stmt->execute([
        $sub_category_id,
        $category_id ?? $existing['category_id']
    ]);
    if (!$stmt->fetch()) sendError("Invalid sub_category_id", 422);
}

/* -------------------------------------------------
   META (ARRAY ONLY)
------------------------------------------------- */
$meta = json_decode($existing['meta'], true) ?: [];
if (isset($input['meta']) && is_array($input['meta'])) {
    $meta = array_merge($meta, $input['meta']);
}

/* -------------------------------------------------
   BARCODE (OPTIONAL UPDATE)
------------------------------------------------- */
if (!empty($input['barcode'])) {
    $meta['barcode'] = preg_replace('/\s+/', '', $input['barcode']);
}

/* -------------------------------------------------
   FINAL VALUES
------------------------------------------------- */
$final = [
    'name'            => $name            ?? $existing['name'],
    'price'           => $price           ?? $existing['price'],
    'gst_rate'        => $gst_rate        ?? $existing['gst_rate'],
    'category_id'     => $category_id     ?? $existing['category_id'],
    'sub_category_id' => $sub_category_id ?? $existing['sub_category_id'],
    'meta'            => json_encode($meta, JSON_UNESCAPED_UNICODE)
];

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       UPDATE PRODUCT
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        UPDATE products SET
            name=?,
            price=?,
            gst_rate=?,
            category_id=?,
            sub_category_id=?,
            meta=?
        WHERE id=? AND outlet_id=? AND org_id=?
    ");
    $stmt->execute([
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
       VARIANTS (REPLACE MODE)
    ------------------------------------------------- */
    if (isset($input['variants']) && is_array($input['variants'])) {

        $pdo->prepare("
            DELETE FROM product_variants WHERE product_id=?
        ")->execute([$product_id]);

        $pdo->prepare("
            DELETE FROM inventory WHERE product_id=? AND variant_id IS NOT NULL
        ")->execute([$product_id]);

        $variantModel = new ProductVariant($pdo);

        foreach ($input['variants'] as $v) {
            if (empty($v['name']) || empty($v['price'])) continue;

            $variant_id = $variantModel->create([
                'product_id' => $product_id,
                'name'       => trim($v['name']),
                'price'      => (float)$v['price'],
                'gst_rate'   => (float)($v['gst_rate'] ?? 0)
            ]);

            $pdo->prepare("
                INSERT INTO inventory (org_id,outlet_id,product_id,variant_id,quantity)
                VALUES (?,?,?,?,?)
            ")->execute([
                $authUser['org_id'],
                $outlet_id,
                $product_id,
                $variant_id,
                (int)($v['quantity'] ?? 0)
            ]);
        }
    }

    $pdo->commit();

    sendSuccess([
        "product_id" => $product_id,
        "barcode"    => $meta['barcode'] ?? null
    ], "Product updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to update product: ".$e->getMessage(), 500);
}
