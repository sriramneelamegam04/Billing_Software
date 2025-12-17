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
if (!$input) sendError("Invalid JSON");

if (!isset($input['variant_id'])) {
    sendError("variant_id is required", 422);
}

$variant_id = (int)$input['variant_id'];

// ----------------------------
// Fetch variant + product + meta + inventory BEFORE deleting
// ----------------------------
$stmt = $pdo->prepare("
    SELECT 
        v.id AS variant_id,
        v.product_id,
        v.name AS variant_name,
        v.price AS variant_price,
        v.created_at AS variant_created_at,
        p.name AS product_name,
        p.category,
        p.meta AS product_meta,
        p.org_id,
        p.outlet_id
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    WHERE v.id=? LIMIT 1
");
$stmt->execute([$variant_id]);
$variant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$variant) sendError("Variant not found", 404);

if ($variant['org_id'] != $authUser['org_id']) {
    sendError("Unauthorized", 403);
}

// Decode product meta
$variant['product_meta'] = $variant['product_meta']
    ? json_decode($variant['product_meta'], true)
    : null;

// ----------------------------
// Fetch inventory quantity BEFORE deleting
// ----------------------------
$stmt = $pdo->prepare("
    SELECT quantity
    FROM inventory
    WHERE variant_id=? AND product_id=? LIMIT 1
");
$stmt->execute([$variant_id, $variant['product_id']]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

$quantity = $inv ? (float)$inv['quantity'] : 0;

// Block delete if stock exists
if ($quantity > 0) {
    sendError("Cannot delete variant. Stock exists in inventory.", 409);
}

// Add quantity to returned info
$variant['quantity'] = $quantity;

try {
    $pdo->beginTransaction();

    // Delete inventory row
    $stmt = $pdo->prepare("DELETE FROM inventory WHERE variant_id=?");
    $stmt->execute([$variant_id]);

    // Delete variant row
    $stmt = $pdo->prepare("DELETE FROM product_variants WHERE id=?");
    $stmt->execute([$variant_id]);

    $pdo->commit();

    // Return deleted variant details
    sendSuccess($variant, "Variant deleted successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to delete variant: " . $e->getMessage(), 500);
}
?>
