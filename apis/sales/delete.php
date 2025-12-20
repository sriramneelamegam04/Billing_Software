<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/HookService.php';
require_once __DIR__.'/../../services/BillingService.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__.'/../../models/Subscription.php';

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
if (!$input || empty($input['sale_id'])) {
    sendError("sale_id is required", 422);
}

$sale_id = (int)$input['sale_id'];

/* -------------------------------------------------
   VALIDATE SALE (ORG SAFE)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, outlet_id, total_amount, customer_id, created_at
    FROM sales
    WHERE id = ? AND org_id = ?
    LIMIT 1
");
$stmt->execute([$sale_id, $authUser['org_id']]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    sendError("Sale not found or unauthorized", 404);
}

$outlet_id = (int)$sale['outlet_id'];

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       FETCH SALE ITEMS (BEFORE DELETE)
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        SELECT
            product_id,
            variant_id,
            quantity,
            rate,
            taxable_amount,
            cgst,
            sgst,
            igst,
            amount
        FROM sale_items
        WHERE sale_id = ?
    ");
    $stmt->execute([$sale_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* -------------------------------------------------
       PRE DELETE HOOK
    ------------------------------------------------- */
    $vertical = $authUser['vertical'] ?? 'Generic';
    if (method_exists('HookService', 'callHook')) {
        HookService::callHook($vertical, 'beforeSaleDelete', [
            "sale_id" => $sale_id,
            "org_id"  => $authUser['org_id'],
            "items"   => $items
        ]);
    }

    /* -------------------------------------------------
       MAIN DELETE (SERVICE HANDLES INVENTORY + LOYALTY)
    ------------------------------------------------- */
    $billingService = new BillingService($pdo);
    $billingService->deleteSale($authUser['org_id'], $sale_id);

    $pdo->commit();

    /* -------------------------------------------------
       POST DELETE HOOK
    ------------------------------------------------- */
    if (method_exists('HookService', 'callHook')) {
        HookService::callHook($vertical, 'afterSaleDelete', [
            "sale_id" => $sale_id,
            "org_id"  => $authUser['org_id']
        ]);
    }

    /* -------------------------------------------------
       CLEAN RESPONSE
    ------------------------------------------------- */
    sendSuccess([
        "sale_id"     => $sale_id,
        "outlet_id"   => $outlet_id,
        "customer_id" => (int)$sale['customer_id'],
        "total_amount"=> (float)$sale['total_amount'],
        "items"       => $items
    ], "Sale deleted successfully");

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sendError("Failed to delete sale: " . $e->getMessage(), 500);
}
