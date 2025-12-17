<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['product_id']) || empty($input['outlet_id'])) {
    sendError("product_id and outlet_id are required", 422);
}

$product_id = (int)$input['product_id'];
$outlet_id  = (int)$input['outlet_id'];

$name       = isset($input['name']) ? trim($input['name']) : '';
$category   = isset($input['category']) ? trim($input['category']) : '';
$price      = isset($input['price']) ? (float)$input['price'] : null;

$meta       = isset($input['meta']) && is_array($input['meta']) ? $input['meta'] : null;

// Validate outlet
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) {
    sendError("Invalid outlet_id or it does not belong to your organization", 403);
}

// Validate product
$stmt = $pdo->prepare("SELECT * FROM products WHERE id=? AND outlet_id=? AND org_id=?");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    sendError("Product not found", 404);
}

// Validate fields
if ($name === '') sendError("Product name cannot be empty", 422);
if ($price !== null && $price < 0) sendError("Price must be a positive number", 422);

try {

    // Prepare updated meta
    if ($meta !== null) {
        $finalMeta = json_encode($meta, JSON_UNESCAPED_UNICODE);
    } else {
        $finalMeta = $existing['meta']; // keep existing meta
    }

    $stmt = $pdo->prepare("
        UPDATE products 
        SET name=?, category=?, price=?, meta=? 
        WHERE id=? AND outlet_id=? AND org_id=?
    ");
    $stmt->execute([
        $name,
        $category,
        $price,
        $finalMeta,
        $product_id,
        $outlet_id,
        $authUser['org_id']
    ]);

    // ================================
    //  AUDIT LOG (inventory_logs)
    //  This is optional but professional
    // ================================
    $changeInfo = "Product Updated: ";
    if ($existing['name'] != $name) $changeInfo .= "name change, ";
    if ($existing['category'] != $category) $changeInfo .= "category change, ";
    if ($existing['price'] != $price) $changeInfo .= "price change, ";

    // Trim last comma
    $changeInfo = rtrim($changeInfo, ', ');

    if ($changeInfo !== "") {
        $log = $pdo->prepare("
            INSERT INTO inventory_logs 
            (org_id, outlet_id, product_id, change_type, quantity_change, reference_id) 
            VALUES (?, ?, ?, 'manual_adjustment', 0, NULL)
        ");
        $log->execute([
            $authUser['org_id'],
            $outlet_id,
            $product_id
        ]);
    }

    sendSuccess([], "Product updated successfully");

} catch (Exception $e) {
    sendError("Failed to update product: " . $e->getMessage(), 500);
}
?>
