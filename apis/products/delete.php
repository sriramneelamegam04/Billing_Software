<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendError("Method Not Allowed", 405);

/* -------------------------------------------------
   AUTH + SUBSCRIPTION
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON input");

foreach (['product_id','outlet_id'] as $f) {
    if (!isset($input[$f])) sendError("Field '$f' is required", 422);
}

$product_id = (int)$input['product_id'];
$outlet_id  = (int)$input['outlet_id'];

/* -------------------------------------------------
   VALIDATE OUTLET
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id FROM outlets
    WHERE id=? AND org_id=?
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet", 403);

/* -------------------------------------------------
   VALIDATE PRODUCT
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM products
    WHERE id=? AND outlet_id=? AND org_id=?
");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) sendError("Product not found", 404);

/* -------------------------------------------------
   BLOCK DELETE IF SALES EXIST
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT 1 FROM sale_items
    WHERE product_id=?
    LIMIT 1
");
$stmt->execute([$product_id]);
if ($stmt->fetch()) {
    sendError(
        "Cannot delete product. It is already used in sales records",
        409
    );
}

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       COLLECT INVENTORY QTY (PRODUCT + VARIANTS)
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity),0)
        FROM inventory
        WHERE product_id=? AND outlet_id=? AND org_id=?
    ");
    $stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
    $totalQty = (int)$stmt->fetchColumn();

    /* -------------------------------------------------
       DELETE INVENTORY FIRST (FK SAFE)
    ------------------------------------------------- */
    $pdo->prepare("
        DELETE FROM inventory
        WHERE product_id=? AND outlet_id=? AND org_id=?
    ")->execute([
        $product_id,
        $outlet_id,
        $authUser['org_id']
    ]);

    /* -------------------------------------------------
       INVENTORY LOG (AUDIT)
    ------------------------------------------------- */
    if ($totalQty > 0) {
        $pdo->prepare("
            INSERT INTO inventory_logs
            (org_id, outlet_id, product_id, change_type, quantity_change, reference_id)
            VALUES (?, ?, ?, 'product_deleted', ?, NULL)
        ")->execute([
            $authUser['org_id'],
            $outlet_id,
            $product_id,
            -$totalQty
        ]);
    }

    /* -------------------------------------------------
       DELETE VARIANTS
    ------------------------------------------------- */
    $pdo->prepare("
        DELETE FROM product_variants
        WHERE product_id=?
    ")->execute([$product_id]);

    /* -------------------------------------------------
       DELETE PRODUCT
    ------------------------------------------------- */
    $pdo->prepare("
        DELETE FROM products
        WHERE id=? AND outlet_id=? AND org_id=?
    ")->execute([
        $product_id,
        $outlet_id,
        $authUser['org_id']
    ]);

    $pdo->commit();

    sendSuccess([
        "product_id"   => $product_id,
        "product_name" => $product['name']
    ], "Product deleted successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to delete product: ".$e->getMessage(), 500);
}
