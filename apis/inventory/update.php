<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__ . '/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use PATCH"]);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized",401);

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}


// =======================
// INPUT
// =======================
$input = json_decode(file_get_contents("php://input"), true);

$required = ['product_id','outlet_id','quantity','mode'];
foreach ($required as $f) {
    if (!isset($input[$f]) || $input[$f] === '') {
        sendError("$f is required");
    }
}

$product_id = (int)$input['product_id'];
$outlet_id  = (int)$input['outlet_id'];
$variant_id = isset($input['variant_id']) ? (int)$input['variant_id'] : null;
$qty        = (float)$input['quantity'];
$mode       = strtolower(trim($input['mode']));
$note       = $input['note'] ?? null;

if ($qty <= 0) sendError("Quantity must be greater than zero");


// =======================
// VALIDATE OUTLET
// =======================
$stmt = $pdo->prepare("
    SELECT id FROM outlets 
    WHERE id=? AND org_id=? LIMIT 1
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet");


// =======================
// VALIDATE PRODUCT
// =======================
$stmt = $pdo->prepare("
    SELECT id FROM products 
    WHERE id=? AND outlet_id=? AND org_id=? LIMIT 1
");
$stmt->execute([$product_id,$outlet_id,$authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid product for this outlet");


// =======================
// VALIDATE VARIANT
// =======================
if (!empty($variant_id)) {
    $stmt = $pdo->prepare("
        SELECT v.id 
        FROM product_variants v
        JOIN products p ON p.id = v.product_id
        WHERE v.id=? AND v.product_id=? 
          AND p.org_id=? AND p.outlet_id=?
        LIMIT 1
    ");
    $stmt->execute([
        $variant_id,
        $product_id,
        $authUser['org_id'],
        $outlet_id
    ]);

    if (!$stmt->fetch()) sendError("Invalid variant for this product");
}


// =======================
// FETCH CURRENT STOCK OR CREATE ROW
// =======================
$stmt = $pdo->prepare("
    SELECT quantity FROM inventory
    WHERE org_id=? AND outlet_id=? AND product_id=? 
      AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL))
    LIMIT 1
");

$stmt->execute([
    $authUser['org_id'],
    $outlet_id,
    $product_id,
    $variant_id,
    $variant_id
]);

$current = $stmt->fetchColumn();

if ($current === false) {
    // CREATE inventory row if not found
    $stmt = $pdo->prepare("
        INSERT INTO inventory (org_id, outlet_id, product_id, variant_id, quantity)
        VALUES (?,?,?,?,0)
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $product_id,
        $variant_id
    ]);
    $current = 0;
}


// =======================
// APPLY OPERATION
// =======================
if ($mode === 'add') {

    $newStock = $current + $qty;
    $change = $qty;
    $type = "manual_in";

} elseif ($mode === 'reduce') {

    if ($current < $qty) {
        sendError("Insufficient stock. Available: $current");
    }

    $newStock = $current - $qty;
    $change = -$qty;
    $type = "manual_out";

} elseif ($mode === 'set') {

    $change = $qty - $current;
    $newStock = $qty;
    $type = "manual_set";

} else {
    sendError("Invalid mode. Allowed: add, reduce, set");
}


// =======================
// UPDATE STOCK
// =======================
$stmt = $pdo->prepare("
    UPDATE inventory 
    SET quantity=? 
    WHERE org_id=? AND outlet_id=? AND product_id=?
      AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL))
");
$stmt->execute([
    $newStock,
    $authUser['org_id'],
    $outlet_id,
    $product_id,
    $variant_id,
    $variant_id
]);


// =======================
// LOG ENTRY
// =======================
$stmt = $pdo->prepare("
    INSERT INTO inventory_logs 
    (org_id, outlet_id, product_id, variant_id, change_type, quantity_change, note)
    VALUES (?,?,?,?,?,?,?)
");

$stmt->execute([
    $authUser['org_id'],
    $outlet_id,
    $product_id,
    $variant_id,
    $type,
    $change,
    $note
]);


sendSuccess([
    "product_id"  => $product_id,
    "variant_id"  => $variant_id,
    "old_stock"   => (float)$current,
    "new_stock"   => (float)$newStock,
    "operation"   => $mode
], "Stock updated successfully");

?>
