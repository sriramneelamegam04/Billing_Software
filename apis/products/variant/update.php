<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';

header("Content-Type: application/json");

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// ----------------------------
// Validate JSON input
// ----------------------------
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON input");

// Required fields
$required = ['variant_id', 'name', 'price'];
foreach ($required as $f) {
    if (!isset($input[$f]) || trim($input[$f]) === "") {
        sendError("Field '$f' is required", 422);
    }
}

$variant_id = (int)$input['variant_id'];
$name       = trim($input['name']);
$price      = (float)$input['price'];

if ($price <= 0) {
    sendError("Price must be greater than zero", 422);
}

// ----------------------------
// Validate variant + org
// ----------------------------
$stmt = $pdo->prepare("
    SELECT 
        v.id AS variant_id,
        v.product_id,
        p.org_id,
        p.outlet_id
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    WHERE v.id=? LIMIT 1
");
$stmt->execute([$variant_id]);
$variant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$variant) sendError("Variant not found", 404);
if ($variant['org_id'] != $authUser['org_id']) sendError("Unauthorized", 403);

// ----------------------------
// Perform update
// ----------------------------
$stmt = $pdo->prepare("
    UPDATE product_variants
    SET name=?, price=?
    WHERE id=?
");
$stmt->execute([$name, $price, $variant_id]);

// ----------------------------
// Fetch updated variant details
// ----------------------------
$stmt = $pdo->prepare("
    SELECT 
        v.id AS variant_id,
        v.product_id,
        v.name,
        v.price,
        v.created_at,
        p.name AS product_name,
        p.category,
        p.meta AS product_meta
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    WHERE v.id=? LIMIT 1
");
$stmt->execute([$variant_id]);
$updated = $stmt->fetch(PDO::FETCH_ASSOC);

// Decode product meta JSON
$updated['product_meta'] = $updated['product_meta']
    ? json_decode($updated['product_meta'], true)
    : null;

// ----------------------------
// Fetch inventory quantity
// ----------------------------
$stmt = $pdo->prepare("
    SELECT quantity
    FROM inventory
    WHERE product_id=? AND variant_id=? AND org_id=? AND outlet_id=?
    LIMIT 1
");
$stmt->execute([
    $updated['product_id'],
    $variant_id,
    $authUser['org_id'],
    $variant['outlet_id']
]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

// Add quantity field
$updated['quantity'] = $inv ? (float)$inv['quantity'] : 0;

// ----------------------------
// SUCCESS RESPONSE
// ----------------------------
sendSuccess($updated, "Variant updated successfully");

?>
