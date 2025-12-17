<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

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

// ----------------------------
// VALIDATE OUTLET
// ----------------------------
$stmt = $pdo->prepare("
    SELECT id FROM outlets 
    WHERE id=? AND org_id=? LIMIT 1
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet");

// ----------------------------
// VALIDATE PRODUCT
// ----------------------------
$stmt = $pdo->prepare("
    SELECT id FROM products 
    WHERE id=? AND outlet_id=? AND org_id=? LIMIT 1
");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid product for this outlet");

// ----------------------------
// VALIDATE VARIANT (if provided)
// ----------------------------
if (!empty($variant_id)) {
    $stmt = $pdo->prepare("
        SELECT v.id 
        FROM product_variants v
        JOIN products p ON p.id = v.product_id
        WHERE v.id=? AND v.product_id=? 
          AND p.org_id=? AND p.outlet_id=?
        LIMIT 1
    ");
    $stmt->execute([$variant_id, $product_id, $authUser['org_id'], $outlet_id]);
    if (!$stmt->fetch()) sendError("Invalid variant for this product");
}

// ----------------------------
// FETCH CURRENT STOCK
// ----------------------------
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

if ($current === false) sendError("Inventory record missing for this product/variant");

if ($current < $qty) {
    sendError("Insufficient stock: Available $current, Required $qty");
}

// ----------------------------
// REDUCE STOCK
// ----------------------------
$newStock = $current - $qty;

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

// ----------------------------
// LOG ENTRY
// ----------------------------
$stmt = $pdo->prepare("
    INSERT INTO inventory_logs 
    (org_id,outlet_id,product_id,variant_id,change_type,quantity_change,note)
    VALUES (?,?,?,?,?,?,?)
");

$stmt->execute([
    $authUser['org_id'],
    $outlet_id,
    $product_id,
    $variant_id,
    'manual_out',
    -$qty,
    $note
]);

sendSuccess([
    "product_id"    => $product_id,
    "variant_id"    => $variant_id,
    "reduced"       => $qty,
    "current_stock" => $newStock
], "Stock reduced successfully");

?>
