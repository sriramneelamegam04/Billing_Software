<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';
require_once __DIR__ . '/../../../helpers/barcode.php';
require_once __DIR__ . '/../../../models/Subscription.php';

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
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON input");

/* REQUIRED */
foreach (['variant_id','name','price'] as $f) {
    if (!isset($input[$f]) || trim($input[$f]) === '') {
        sendError("Field '$f' is required", 422);
    }
}

$variant_id = (int)$input['variant_id'];
$name       = trim($input['name']);
$price      = (float)$input['price'];
$gst_rate   = array_key_exists('gst_rate', $input) ? (float)$input['gst_rate'] : null;
$quantity   = array_key_exists('quantity', $input) ? (int)$input['quantity'] : null;
$low_stock  = array_key_exists('low_stock_limit', $input)
    ? (int)$input['low_stock_limit']
    : null;

/* META INPUTS */
$purchase_price = (float)($input['purchase_price'] ?? 0);
$discount_type  = $input['discount_type'] ?? null;
$discount_value = (float)($input['discount_value'] ?? 0);
$input_barcode  = isset($input['barcode'])
    ? preg_replace('/\s+/', '', $input['barcode'])
    : null;

/* VALIDATION */
if ($price <= 0) sendError("Price must be greater than zero", 422);
if ($gst_rate !== null && ($gst_rate < 0 || $gst_rate > 100)) {
    sendError("Invalid gst_rate", 422);
}

/* -------------------------------------------------
   FETCH VARIANT + PRODUCT (ORG SAFE)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        v.id AS variant_id,
        v.product_id,
        v.gst_rate AS existing_gst,
        v.meta      AS existing_meta,
        p.org_id,
        p.outlet_id
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    WHERE v.id = ?
    LIMIT 1
");
$stmt->execute([$variant_id]);
$variant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$variant) sendError("Variant not found", 404);
if ((int)$variant['org_id'] !== (int)$authUser['org_id']) {
    sendError("Access denied", 403);
}

/* DUPLICATE NAME CHECK */
$stmt = $pdo->prepare("
    SELECT id FROM product_variants
    WHERE product_id = ?
      AND LOWER(name) = LOWER(?)
      AND id != ?
    LIMIT 1
");
$stmt->execute([
    $variant['product_id'],
    $name,
    $variant_id
]);
if ($stmt->fetch()) {
    sendError("Another variant with same name already exists", 409);
}

/* FINAL GST */
$finalGst = $gst_rate !== null
    ? $gst_rate
    : (float)$variant['existing_gst'];

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       META MERGE (🔥 BARCODE LOGIC ADDED)
    ------------------------------------------------- */
    $variantMeta = json_decode($variant['existing_meta'], true) ?: [];

    if ($purchase_price > 0) {
        $variantMeta['purchase_price'] = $purchase_price;
    }

    if ($discount_type && $discount_value > 0) {
        $variantMeta['discount'] = [
            'type'  => $discount_type,
            'value' => $discount_value
        ];
    }

    // 🔥 BARCODE PRIORITY
    if ($input_barcode) {
        $variantMeta['barcode'] = $input_barcode;
    } elseif (empty($variantMeta['barcode'])) {
        $variantMeta['barcode'] = generate_barcode(
            $authUser['org_id'],
            $variant['product_id'],
            $variant_id
        );
    }

    /* -------------------------------------------------
       UPDATE VARIANT
    ------------------------------------------------- */
    $pdo->prepare("
        UPDATE product_variants
        SET name = ?, price = ?, gst_rate = ?, meta = ?
        WHERE id = ?
    ")->execute([
        $name,
        $price,
        $finalGst,
        json_encode($variantMeta, JSON_UNESCAPED_UNICODE),
        $variant_id
    ]);

    /* -------------------------------------------------
       UPDATE INVENTORY (OPTIONAL)
    ------------------------------------------------- */
    if ($quantity !== null || $low_stock !== null) {
        $pdo->prepare("
            UPDATE inventory
            SET
                quantity = COALESCE(?, quantity),
                low_stock_limit = COALESCE(?, low_stock_limit)
            WHERE product_id = ?
              AND variant_id = ?
              AND org_id = ?
              AND outlet_id = ?
        ")->execute([
            $quantity,
            $low_stock,
            $variant['product_id'],
            $variant_id,
            $authUser['org_id'],
            $variant['outlet_id']
        ]);
    }

    $pdo->commit();

    /* -------------------------------------------------
       RESPONSE
    ------------------------------------------------- */
    sendSuccess([
        "variant_id"      => $variant_id,
        "product_id"      => (int)$variant['product_id'],
        "name"            => $name,
        "price"           => $price,
        "gst_rate"        => $finalGst,
        "quantity"        => $quantity,
        "low_stock_limit" => $low_stock,
        "barcode"         => $variantMeta['barcode'],
        "meta"            => $variantMeta
    ], "Variant updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to update variant: " . $e->getMessage(), 500);
}
