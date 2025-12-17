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

// Validate outlet
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) {
    sendError("Invalid outlet_id or outlet does not belong to your organization", 403);
}

// Validate product exists
$stmt = $pdo->prepare("SELECT id FROM products WHERE id=? AND outlet_id=? AND org_id=?");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) {
    sendError("Product not found under this outlet", 404);
}

// Prevent deletion if linked to sales
$salesCheck = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id=?");
$salesCheck->execute([$product_id]);
if ($salesCheck->fetchColumn() > 0) {
    sendError("Cannot delete this product because it is linked to existing sales", 409);
}

try {
    $pdo->beginTransaction();

    // Check current stock before delete
    $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id=? AND outlet_id=? AND org_id=?");
    $stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
    $stock = (float)$stmt->fetchColumn();

    // Delete inventory row
    $invDel = $pdo->prepare("DELETE FROM inventory WHERE product_id=? AND outlet_id=? AND org_id=?");
    $invDel->execute([$product_id, $outlet_id, $authUser['org_id']]);

    // Insert log for product deletion (optional audit)
    $log = $pdo->prepare("
        INSERT INTO inventory_logs (org_id, outlet_id, product_id, change_type, quantity_change, reference_id)
        VALUES (?, ?, ?, 'manual_adjustment', ?, NULL)
    ");
    $log->execute([
        $authUser['org_id'],
        $outlet_id,
        $product_id,
        -$stock   // remove remaining stock from record
    ]);

    // Delete all variants
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
?>
