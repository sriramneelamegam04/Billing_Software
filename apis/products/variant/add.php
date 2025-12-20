<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';
require_once __DIR__ . '/../../../models/Subscription.php';
require_once __DIR__ . '/../../../models/ProductVariant.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed. Use POST", 405);
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON input");

/* -------------------------------------------------
   REQUIRED FIELDS
------------------------------------------------- */
foreach (['product_id','name','price'] as $f) {
    if (!isset($input[$f]) || trim($input[$f]) === '') {
        sendError("Field '$f' is required", 422);
    }
}

$product_id = (int)$input['product_id'];
$name       = trim($input['name']);
$price      = (float)$input['price'];
$gst_rate   = (float)($input['gst_rate'] ?? 0);
$quantity   = (int)($input['quantity'] ?? 0);

if ($price <= 0) sendError("Price must be greater than zero", 422);
if ($gst_rate < 0 || $gst_rate > 100) sendError("Invalid gst_rate", 422);

/* -------------------------------------------------
   VALIDATE PRODUCT (ORG SAFE)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, org_id, outlet_id
    FROM products
    WHERE id = ?
");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    sendError("Invalid product_id", 404);
}
if ((int)$product['org_id'] !== (int)$authUser['org_id']) {
    sendError("Access denied for this product", 403);
}

/* -------------------------------------------------
   DUPLICATE VARIANT CHECK (PER PRODUCT)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id FROM product_variants
    WHERE product_id = ? AND name = ?
    LIMIT 1
");
$stmt->execute([$product_id, $name]);
if ($stmt->fetch()) {
    sendError("Variant already exists for this product", 409);
}

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       CREATE VARIANT (MODEL)
    ------------------------------------------------- */
    $variantModel = new ProductVariant($pdo);
    $variant_id = $variantModel->create([
        'product_id' => $product_id,
        'name'       => $name,
        'price'      => $price,
        'gst_rate'   => $gst_rate
    ]);

    /* -------------------------------------------------
       INVENTORY INIT (VARIANT LEVEL)
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        INSERT INTO inventory (org_id, outlet_id, product_id, variant_id, quantity)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $authUser['org_id'],
        $product['outlet_id'],
        $product_id,
        $variant_id,
        $quantity
    ]);

    $pdo->commit();

    sendSuccess([
        "variant_id" => $variant_id,
        "product_id" => $product_id,
        "variant_name" => $name,
        "price" => $price,
        "gst_rate" => $gst_rate,
        "quantity" => $quantity
    ], "Variant created successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to create variant: " . $e->getMessage(), 500);
}
