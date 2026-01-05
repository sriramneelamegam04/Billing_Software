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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}


$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['product_id'], $input['outlet_id'], $input['quantity'])) {
    sendError("product_id, outlet_id, quantity required");
}

$product_id = (int)$input['product_id'];
$outlet_id  = (int)$input['outlet_id'];
$variant_id = isset($input['variant_id']) ? (int)$input['variant_id'] : null;
$qty        = (float)$input['quantity'];
$note       = $input['note'] ?? '';

if ($qty <= 0) sendError("Quantity must be > 0");

// Validate outlet belongs to org
$stmt = $pdo->prepare("
    SELECT id FROM outlets WHERE id=? AND org_id=?
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet for this org");


/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

// Validate product belongs to org + outlet
$stmt = $pdo->prepare("
    SELECT id FROM products WHERE id=? AND outlet_id=? AND org_id=?
");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Product not found in this outlet");

// Validate variant if provided
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
        $variant_id, $product_id,
        $authUser['org_id'], $outlet_id
    ]);

    if (!$stmt->fetch()) {
        sendError("Variant does not belong to this product/outlet/org");
    }
}

// -------------------------------
// Update or Insert inventory row
// -------------------------------
$stmt = $pdo->prepare("
    SELECT quantity FROM inventory
    WHERE org_id=? AND outlet_id=? 
      AND product_id=? 
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

$exists = $stmt->fetchColumn();

if ($exists === false) {
    // Insert new inventory row
    $stmt = $pdo->prepare("
        INSERT INTO inventory (org_id, outlet_id, product_id, variant_id, quantity)
        VALUES (?,?,?,?,?)
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $product_id,
        $variant_id,
        $qty
    ]);

    $current = $qty;

} else {
    // Update existing inventory row
    $stmt = $pdo->prepare("
        UPDATE inventory 
        SET quantity = quantity + ?
        WHERE org_id=? AND outlet_id=? 
          AND product_id=? 
          AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL))
    ");
    $stmt->execute([
        $qty,
        $authUser['org_id'],
        $outlet_id,
        $product_id,
        $variant_id,
        $variant_id
    ]);

    // Fetch updated stock
    $stmt = $pdo->prepare("
        SELECT quantity FROM inventory
        WHERE org_id=? AND outlet_id=? AND product_id=? 
          AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL))
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $product_id,
        $variant_id,
        $variant_id
    ]);
    $current = $stmt->fetchColumn();
}

// -------------------------------
// Log the stock addition
// -------------------------------
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
    'stock_in',
    $qty,
    $note
]);

sendSuccess([
    "product_id"   => $product_id,
    "variant_id"   => $variant_id,
    "added"        => $qty,
    "current_stock"=> (float)$current
], "Stock updated successfully");

?>
