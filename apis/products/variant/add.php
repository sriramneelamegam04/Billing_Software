<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON input");

// -------------------------
// REQUIRED FIELDS
// -------------------------
$required = ['product_id', 'name', 'price'];
foreach ($required as $f) {
    if (!isset($input[$f]) || trim($input[$f]) === '') {
        sendError("Field '$f' is required", 422);
    }
}

$product_id = (int)$input['product_id'];
$name       = trim($input['name']);
$price      = (float)$input['price'];

if ($price <= 0) {
    sendError("Price must be greater than zero", 422);
}

// -------------------------
// VALIDATE PRODUCT
// -------------------------
$stmt = $pdo->prepare("
    SELECT id, org_id, outlet_id 
    FROM products 
    WHERE id=? LIMIT 1
");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    sendError("Invalid product_id", 404);
}

if ($product['org_id'] != $authUser['org_id']) {
    sendError("You cannot add variants to a product from another organization", 403);
}

try {
    $pdo->beginTransaction();

    // -------------------------
    // CREATE VARIANT
    // -------------------------
    $stmt = $pdo->prepare("
        INSERT INTO product_variants (product_id, name, price)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$product_id, $name, $price]);

    $variant_id = $pdo->lastInsertId();

    // -------------------------
    // CREATE INVENTORY ENTRY
    // -------------------------
    $stmt = $pdo->prepare("
        INSERT INTO inventory (org_id, outlet_id, product_id, variant_id, quantity)
        VALUES (?, ?, ?, ?, 0)
    ");
    $stmt->execute([
        $authUser['org_id'],
        $product['outlet_id'],
        $product_id,
        $variant_id
    ]);

    $pdo->commit();

    sendSuccess([
        "variant_id" => $variant_id
    ], "Variant created successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to create variant: " . $e->getMessage(), 500);
}
?>
