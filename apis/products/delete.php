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

// Decode input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['product_id']) || empty($input['outlet_id'])) {
    sendError("product_id and outlet_id are required", 422);
}

$product_id = (int)$input['product_id'];
$outlet_id  = (int)$input['outlet_id'];

// ✅ Check if outlet belongs to the org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) {
    sendError("Invalid outlet_id or it does not belong to your organization", 403);
}

// ✅ Check if product exists under this outlet and org
$stmt = $pdo->prepare("SELECT id FROM products WHERE id=? AND outlet_id=? AND org_id=?");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) {
    sendError("Product not found under the given outlet", 404);
}

// ✅ Check if product is linked to sales
$salesCheck = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id=?");
$salesCheck->execute([$product_id]);
if ($salesCheck->fetchColumn() > 0) {
    sendError("Cannot delete this product because it is linked to existing sales", 409);
}

try {
    $pdo->beginTransaction();

    // Delete product variants first
    $stmt = $pdo->prepare("DELETE FROM product_variants WHERE product_id=?");
    $stmt->execute([$product_id]);

    // Delete product
    $stmt = $pdo->prepare("DELETE FROM products WHERE id=? AND outlet_id=? AND org_id=?");
    $stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);

    $pdo->commit();
    sendSuccess([], "Product deleted successfully");
} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to delete product: " . $e->getMessage(), 500);
}
