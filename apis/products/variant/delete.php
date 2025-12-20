<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';
require_once __DIR__ . '/../../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed. Use POST", 405);
}

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
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON input");

if (empty($input['variant_id'])) {
    sendError("variant_id is required", 422);
}

$variant_id = (int)$input['variant_id'];

/* -------------------------------------------------
   FETCH VARIANT + PRODUCT (ORG SAFE)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        v.id   AS variant_id,
        v.product_id,
        v.name AS variant_name,
        v.price,
        v.gst_rate,
        p.name AS product_name,
        p.org_id,
        p.outlet_id
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    WHERE v.id = ?
    LIMIT 1
");
$stmt->execute([$variant_id]);
$variant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$variant) sendError("Variant not found", 404);
if ((int)$variant['org_id'] !== (int)$authUser['org_id']) {
    sendError("Access denied", 403);
}

/* -------------------------------------------------
   FETCH INVENTORY QTY (BLOCK IF STOCK EXISTS)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT quantity
    FROM inventory
    WHERE product_id = ?
      AND variant_id = ?
      AND org_id = ?
      AND outlet_id = ?
    LIMIT 1
");
$stmt->execute([
    $variant['product_id'],
    $variant_id,
    $authUser['org_id'],
    $variant['outlet_id']
]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

$quantity = $inv ? (int)$inv['quantity'] : 0;

if ($quantity > 0) {
    sendError(
        "Cannot delete variant. Stock exists in inventory.",
        409
    );
}

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       DELETE INVENTORY ROW (FK SAFE)
    ------------------------------------------------- */
    $pdo->prepare("
        DELETE FROM inventory
        WHERE product_id = ?
          AND variant_id = ?
          AND org_id = ?
          AND outlet_id = ?
    ")->execute([
        $variant['product_id'],
        $variant_id,
        $authUser['org_id'],
        $variant['outlet_id']
    ]);

    /* -------------------------------------------------
       DELETE VARIANT
    ------------------------------------------------- */
    $pdo->prepare("
        DELETE FROM product_variants
        WHERE id = ?
    ")->execute([$variant_id]);

    $pdo->commit();

    sendSuccess([
        "variant_id"   => (int)$variant['variant_id'],
        "variant_name" => $variant['variant_name'],
        "product_id"   => (int)$variant['product_id'],
        "product_name" => $variant['product_name'],
        "quantity"     => $quantity
    ], "Variant deleted successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to delete variant: " . $e->getMessage(), 500);
}
