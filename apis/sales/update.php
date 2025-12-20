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
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed. Use POST", 405);
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON");

if (empty($input['sale_id'])) sendError("sale_id is required");

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

if (!$sale) sendError("Sale not found", 404);

/* -------------------------------------------------
   OUTLET
------------------------------------------------- */
$outlet_id = $sale['outlet_id'];

$stmt = $pdo->prepare("
    SELECT id, name
    FROM outlets
    WHERE id=? AND org_id=?
    LIMIT 1
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$outlet) sendError("Invalid outlet", 403);

/* -------------------------------------------------
   ITEMS (OPTIONAL UPDATE)
------------------------------------------------- */
$items = $input['items'] ?? [];

if (!empty($items)) {

    foreach ($items as &$item) {

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
            $item['product_id'] = (int)$stmt->fetchColumn();
            if (!$item['product_id']) sendError("Invalid barcode");
        }

        if (empty($item['product_id']) || empty($item['quantity'])) {
            sendError("product_id and quantity required");
        }

        $item['variant_id'] = $item['variant_id'] ?? null;

        if (!isset($item['rate']) || $item['rate'] === "") {
            if ($item['variant_id']) {
                $stmt = $pdo->prepare("SELECT price FROM product_variants WHERE id=?");
                $stmt->execute([$item['variant_id']]);
            } else {
                $stmt = $pdo->prepare("SELECT price FROM products WHERE id=?");
                $stmt->execute([$item['product_id']]);
            }
            $item['rate'] = (float)$stmt->fetchColumn();
        }

        if ($item['rate'] <= 0) sendError("Invalid rate");
    }
    unset($item);
}

/* -------------------------------------------------
   GST CALCULATION (SAME AS CREATE)
------------------------------------------------- */
$gst_type = 'CGST_SGST';

$taxable_total = 0;
$cgst_total = 0;
$sgst_total = 0;
$igst_total = 0;
$grand_total = 0;

foreach ($items as &$item) {

    if ($item['variant_id']) {
        $stmt = $pdo->prepare("SELECT gst_rate FROM product_variants WHERE id=?");
        $stmt->execute([$item['variant_id']]);
    } else {
        $stmt = $pdo->prepare("SELECT gst_rate FROM products WHERE id=?");
        $stmt->execute([$item['product_id']]);
    }
    $gst_rate = (float)$stmt->fetchColumn();

    $rate = (float)$item['rate'];
    $qty  = (float)$item['quantity'];

    $taxable = round($rate * $qty, 2);
    $gst_amt = round(($taxable * $gst_rate) / 100, 2);

    $cgst = $sgst = $igst = 0;
    if ($gst_type === 'CGST_SGST') {
        $cgst = round($gst_amt / 2, 2);
        $sgst = round($gst_amt / 2, 2);
    } else {
        $igst = $gst_amt;
    }

    $line_total = round($taxable + $cgst + $sgst + $igst, 2);

    $item['taxable_amount'] = $taxable;
    $item['gst_rate'] = $gst_rate;
    $item['cgst'] = $cgst;
    $item['sgst'] = $sgst;
    $item['igst'] = $igst;
    $item['amount'] = $line_total;

    $taxable_total += $taxable;
    $cgst_total += $cgst;
    $sgst_total += $sgst;
    $igst_total += $igst;
    $grand_total += $line_total;
}
unset($item);

$round_off = round($grand_total) - $grand_total;
$final_total = round($grand_total);

/* -------------------------------------------------
   UPDATE PAYLOAD
------------------------------------------------- */
$updateData = [
    'items'          => $items,
    'taxable_amount' => round($taxable_total,2),
    'cgst'           => round($cgst_total,2),
    'sgst'           => round($sgst_total,2),
    'igst'           => round($igst_total,2),
    'round_off'      => round($round_off,2),
    'total_amount'   => $final_total
];

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

    sendSuccess([
        "sale_id" => $sale_id,
        "outlet"  => $outlet,
        "items"   => $items,
        "summary" => [
            "taxable_amount" => round($taxable_total,2),
            "cgst" => round($cgst_total,2),
            "sgst" => round($sgst_total,2),
            "igst" => round($igst_total,2),
            "round_off" => round($round_off,2),
            "grand_total" => $final_total
        ]
    ], "Sale updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Update failed: ".$e->getMessage());
}
