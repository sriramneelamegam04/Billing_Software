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

foreach (['name','price','outlet_id','category_id','sub_category_id'] as $f) {
    if (!isset($input[$f])) sendError("Field '$f' is required", 422);
}

$name            = trim($input['name']);
$price           = (float)$input['price'];
$outlet_id       = (int)$input['outlet_id'];
$category_id     = (int)$input['category_id'];
$sub_category_id = (int)$input['sub_category_id'];
$gst_rate        = (float)($input['gst_rate'] ?? 0);
$quantity        = (int)($input['quantity'] ?? 0);

if ($price <= 0) sendError("Invalid price", 422);
if ($gst_rate < 0 || $gst_rate > 100) sendError("Invalid gst_rate", 422);

/* -------------------------------------------------
   VALIDATE OUTLET
------------------------------------------------- */
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet", 403);

/* -------------------------------------------------
   VALIDATE CATEGORY
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, name FROM categories
    WHERE id=? AND org_id=? AND status=1
");
$stmt->execute([$category_id, $authUser['org_id']]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category) sendError("Invalid category_id", 422);

/* -------------------------------------------------
   VALIDATE SUB CATEGORY
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, name FROM sub_categories
    WHERE id=? AND category_id=? AND status=1
");
$stmt->execute([$sub_category_id, $category_id]);
$subCategory = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subCategory) sendError("Invalid sub_category_id", 422);

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       META AS ARRAY (IMPORTANT FIX)
    ------------------------------------------------- */
    $meta = [];
    if (isset($input['meta']) && is_array($input['meta'])) {
        $meta = $input['meta'];
    }

    /* -------------------------------------------------
       CREATE PRODUCT (WITHOUT BARCODE FIRST)
    ------------------------------------------------- */
    $productModel = new Product($pdo);
    $product_id = $productModel->create([
        'name'            => $name,
        'org_id'          => $authUser['org_id'],
        'outlet_id'       => $outlet_id,
        'price'           => $price,
        'gst_rate'        => $gst_rate,
        'category_id'     => $category_id,
        'sub_category_id' => $sub_category_id,
        'meta'            => $meta   // ✅ ARRAY ONLY
    ]);

    /* -------------------------------------------------
       BARCODE GENERATE + STORE IN META
    ------------------------------------------------- */
    $barcode = !empty($input['barcode'])
        ? preg_replace('/\s+/', '', $input['barcode'])
        : generate_barcode($authUser['org_id'], $product_id);

    $meta['barcode'] = $barcode;

    $stmt = $pdo->prepare("UPDATE products SET meta=? WHERE id=?");
    $stmt->execute([
        json_encode($meta, JSON_UNESCAPED_UNICODE),
        $product_id
    ]);

    /* -------------------------------------------------
       INVENTORY (PRODUCT LEVEL)
    ------------------------------------------------- */
    $pdo->prepare("
        INSERT INTO inventory (org_id,outlet_id,product_id,variant_id,quantity)
        VALUES (?,?,?,?,?)
    ")->execute([
        $authUser['org_id'],
        $outlet_id,
        $product_id,
        null,
        $quantity
    ]);

    /* -------------------------------------------------
       VARIANTS + INVENTORY
    ------------------------------------------------- */
    $variantModel = new ProductVariant($pdo);
    if (!empty($input['variants']) && is_array($input['variants'])) {
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
        "product_id"   => $product_id,
        "barcode"      => $barcode,
        "category"     => $category['name'],
        "sub_category" => $subCategory['name']
    ], "Product created successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to create product: ".$e->getMessage(), 500);
}
