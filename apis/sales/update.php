<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/validation.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/BillingService.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__.'/../../services/HookService.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   PARSE INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError("Invalid JSON");
}

if (empty($input['sale_id'])) {
    sendError("sale_id is required");
}

$sale_id = (int)$input['sale_id'];

/* -------------------------------------------------
   FETCH SALE
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT * FROM sales 
    WHERE id=? AND org_id=? 
    LIMIT 1
");
$stmt->execute([$sale_id, $authUser['org_id']]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) sendError("Sale not found or unauthorized", 404);

/* -------------------------------------------------
   ALLOWED FIELDS
------------------------------------------------- */
$allowed = ['discount', 'note', 'customer_id', 'outlet_id', 'items'];
$updateData = [];

foreach ($allowed as $f) {
    if (array_key_exists($f, $input)) {
        $updateData[$f] = $input[$f];
    }
}

/* -------------------------------------------------
   OUTLET VALIDATION
------------------------------------------------- */
$outlet_id = $sale['outlet_id'];

if (isset($updateData['outlet_id'])) {
    $oid = (int)$updateData['outlet_id'];

    $stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
    $stmt->execute([$oid, $authUser['org_id']]);
    if (!$stmt->fetch()) sendError("Invalid outlet_id",403);

    $outlet_id = $oid;
}

/* -------------------------------------------------
   ITEM VALIDATION + RATE + AMOUNT
------------------------------------------------- */
if (isset($updateData['items'])) {

    if (!is_array($updateData['items']) || count($updateData['items']) === 0) {
        sendError("items must be non-empty array");
    }

    foreach ($updateData['items'] as &$item) {

        if (!empty($item['barcode']) && empty($item['product_id'])) {
            $stmt = $pdo->prepare("
                SELECT id FROM products
                WHERE org_id=? AND outlet_id=?
                  AND JSON_UNQUOTE(JSON_EXTRACT(meta,'$.barcode'))=?
            ");
            $stmt->execute([
                $authUser['org_id'],
                $outlet_id,
                trim($item['barcode'])
            ]);
            $pid = $stmt->fetchColumn();
            if (!$pid) sendError("Product not found for barcode");
            $item['product_id'] = (int)$pid;
        }

        if (empty($item['product_id']) || empty($item['quantity'])) {
            sendError("product_id & quantity required");
        }

        $item['variant_id'] = $item['variant_id'] ?? null;

        if ($item['variant_id']) {
            $stmt = $pdo->prepare("
                SELECT v.price
                FROM product_variants v
                JOIN products p ON p.id=v.product_id
                WHERE v.id=? AND p.org_id=? AND p.outlet_id=?
            ");
            $stmt->execute([
                $item['variant_id'],
                $authUser['org_id'],
                $outlet_id
            ]);
            $rate = $stmt->fetchColumn();
            if ($rate === false) sendError("Invalid variant_id");
        } else {
            $stmt = $pdo->prepare("
                SELECT price FROM products
                WHERE id=? AND org_id=? AND outlet_id=?
            ");
            $stmt->execute([
                $item['product_id'],
                $authUser['org_id'],
                $outlet_id
            ]);
            $rate = $stmt->fetchColumn();
            if ($rate === false) sendError("Invalid product_id");
        }

        $item['rate']   = (float)$rate;
        $item['amount'] = $item['rate'] * $item['quantity'];
    }
    unset($item);
}

/* -------------------------------------------------
   GST FROM ORG (SOURCE OF TRUTH)s
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT gst_type, gst_rate 
    FROM orgs 
    WHERE id=? LIMIT 1
");
$stmt->execute([$authUser['org_id']]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

$gst_type = $org['gst_type'];
$gst_rate = (float)$org['gst_rate'];

/* -------------------------------------------------
   RECALCULATE TOTALS (SAME AS CREATE)
------------------------------------------------- */
$items = $updateData['items'] ?? [];

if (!empty($items)) {
    $sub_total = 0;
    foreach ($items as $i) {
        $sub_total += $i['amount'];
    }
} else {
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM sale_items WHERE sale_id=?");
    $stmt->execute([$sale_id]);
    $sub_total = (float)$stmt->fetchColumn();
}

$discount = isset($updateData['discount'])
    ? (float)$updateData['discount']
    : (float)$sale['discount'];

$taxable = max(0, $sub_total - $discount);

$cgst = $sgst = $igst = 0;

if ($gst_rate > 0) {
    if ($gst_type === 'CGST_SGST') {
        $half = $gst_rate / 2;
        $cgst = ($taxable * $half) / 100;
        $sgst = ($taxable * $half) / 100;
    } elseif ($gst_type === 'IGST') {
        $igst = ($taxable * $gst_rate) / 100;
    }
}

$gross     = $taxable + $cgst + $sgst + $igst;
$round_off = round($gross) - $gross;
$final     = round($gross);

/* -------------------------------------------------
   FINAL UPDATE PAYLOAD
------------------------------------------------- */
$updateData = array_merge($updateData, [
    'sub_total'   => round($sub_total,2),
    'discount'    => round($discount,2),
    'cgst'        => round($cgst,2),
    'sgst'        => round($sgst,2),
    'igst'        => round($igst,2),
    'round_off'   => round($round_off,2),
    'total_amount'=> $final,
    'gst_type'    => $gst_type,
    'gst_rate'    => $gst_rate
]);

/* -------------------------------------------------
   SAVE
------------------------------------------------- */
try {
    $pdo->beginTransaction();

    (new SubscriptionService($pdo))
        ->checkActive($authUser['org_id']);

    $billing = new BillingService($pdo);
    $billing->updateSale($authUser['org_id'], $sale_id, $sale, $updateData);

    $pdo->commit();
    sendSuccess(['sale_id'=>$sale_id], "Sale updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Update failed: ".$e->getMessage());
}
