<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';

header("Content-Type: application/json");

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// ----------------------------
// Validate variant_id
// ----------------------------
if (!isset($_GET['variant_id'])) {
    sendError("variant_id is required", 422);
}

$variant_id = (int) $_GET['variant_id'];

// ----------------------------
// Fetch variant + product details
// ----------------------------
$stmt = $pdo->prepare("
    SELECT 
        v.id AS variant_id,
        v.product_id,
        v.name AS variant_name,
        v.price AS variant_price,
        v.created_at AS variant_created_at,

        p.name AS product_name,
        p.org_id,
        p.outlet_id,
        p.category,
        p.meta AS product_meta
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    WHERE v.id = ?
    LIMIT 1
");
$stmt->execute([$variant_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) sendError("Variant not found", 404);

// Check org
if ($data['org_id'] != $authUser['org_id']) {
    sendError("Unauthorized access to variant", 403);
}

// ----------------------------
// Fetch inventory quantity for this variant
// ----------------------------
$stmt = $pdo->prepare("
    SELECT quantity 
    FROM inventory
    WHERE product_id=? AND variant_id=? AND org_id=? AND outlet_id=?
    LIMIT 1
");
$stmt->execute([
    $data['product_id'],
    $variant_id,
    $authUser['org_id'],
    $data['outlet_id']
]);

$inv = $stmt->fetch(PDO::FETCH_ASSOC);
$quantity = $inv ? (float)$inv['quantity'] : 0;

// ----------------------------
// Format response
// ----------------------------
sendSuccess([
    "variant" => [
        "id"         => $data['variant_id'],
        "name"       => $data['variant_name'],
        "price"      => $data['variant_price'],
        "created_at" => $data['variant_created_at'],
        "quantity"   => $quantity
    ],
    "product" => [
        "id"        => $data['product_id'],
        "name"      => $data['product_name'],
        "category"  => $data['category'],
        "meta"      => json_decode($data['product_meta'], true)
    ]
], "Variant details fetched successfully");

?>
