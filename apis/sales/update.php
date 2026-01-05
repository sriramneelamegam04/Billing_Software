<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/validation.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/BillingService.php';
require_once __DIR__.'/../../services/SubscriptionService.php';

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

$outlet_id   = (int)$sale['outlet_id'];
$customer_id = (int)$sale['customer_id'];

/* -------------------------------------------------
   FETCH OUTLET
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM outlets
    WHERE id=? AND org_id=?
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) sendError("Invalid outlet", 403);

/* -------------------------------------------------
   ITEMS
------------------------------------------------- */
$items = $input['items'] ?? [];
if (empty($items)) sendError("items required");

/* =================================================
   BARCODE + RATE RESOLUTION
================================================= */
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

/* =================================================
   DISCOUNT HELPER
================================================= */
function getItemDiscount(PDO $pdo, int $product_id, ?int $variant_id): array
{
    if ($variant_id) {
        $stmt = $pdo->prepare("SELECT meta FROM product_variants WHERE id=?");
        $stmt->execute([$variant_id]);
    } else {
        $stmt = $pdo->prepare("SELECT meta FROM products WHERE id=?");
        $stmt->execute([$product_id]);
    }
    $meta = json_decode($stmt->fetchColumn(), true) ?: [];
    return $meta['discount'] ?? [];
}

/* =================================================
   GST + DISCOUNT CALCULATION (SAME AS CREATE)
================================================= */
$gst_type = 'CGST_SGST';

$taxable_total = $cgst_total = $sgst_total = $igst_total = $grand_total = 0;

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

    $discount = getItemDiscount($pdo, $item['product_id'], $item['variant_id']);
    $discount_amount = 0;

    if (!empty($discount)) {
        if ($discount['type'] === 'percentage') {
            $discount_amount = ($rate * $discount['value']) / 100;
        } elseif ($discount['type'] === 'flat') {
            $discount_amount = $discount['value'];
        }
    }

    $final_rate = max(0, round($rate - $discount_amount, 2));
    $taxable    = round($final_rate * $qty, 2);

    $gst_amt = round(($taxable * $gst_rate) / 100, 2);
    $cgst = $sgst = $igst = 0;

    if ($gst_type === 'CGST_SGST') {
        $cgst = round($gst_amt / 2, 2);
        $sgst = round($gst_amt / 2, 2);
    } else {
        $igst = $gst_amt;
    }

    $line_total = round($taxable + $cgst + $sgst + $igst, 2);

    $item['original_rate']   = $rate;
    $item['discount']        = $discount;
    $item['discount_amount'] = round($discount_amount, 2);
    $item['rate']            = $final_rate;
    $item['taxable_amount']  = $taxable;
    $item['gst_rate']        = $gst_rate;
    $item['cgst']            = $cgst;
    $item['sgst']            = $sgst;
    $item['igst']            = $igst;
    $item['amount']          = $line_total;

    $taxable_total += $taxable;
    $cgst_total    += $cgst;
    $sgst_total    += $sgst;
    $igst_total    += $igst;
    $grand_total   += $line_total;
}
unset($item);

$round_off   = round($grand_total) - $grand_total;
$final_total = round($grand_total);

/* =================================================
   UPDATE SALE
================================================= */
try {
    $pdo->beginTransaction();
    
    $pdo->prepare("
    UPDATE sales 
    SET status = 0 
    WHERE id = ? AND org_id = ?
")->execute([$sale_id, $authUser['org_id']]);
// Invalidate previous payments for this sale
$stmt = $pdo->prepare("
    DELETE FROM payments
    WHERE sale_id=? AND org_id=?
");
$stmt->execute([$sale_id, $authUser['org_id']]);


    (new SubscriptionService($pdo))
        ->checkActive($authUser['org_id']);

    $billing = new BillingService($pdo);
    $billing->updateSale($authUser['org_id'], $sale_id, $sale, [
        'items'          => $items,
        'taxable_amount' => round($taxable_total,2),
        'cgst'           => round($cgst_total,2),
        'sgst'           => round($sgst_total,2),
        'igst'           => round($igst_total,2),
        'round_off'      => round($round_off,2),
        'total_amount'   => $final_total

        
    ]);

    /* ---------- FETCH EXISTING LOYALTY ---------- */
   /* ---------- UPDATE / CREATE LOYALTY ---------- */
$loyalty_earned = round($final_total / 100, 2);

$stmt = $pdo->prepare("
    SELECT id FROM loyalty_points
    WHERE sale_id=? AND org_id=?
    LIMIT 1
");
$stmt->execute([$sale_id, $authUser['org_id']]);
$lp_id = $stmt->fetchColumn();

if ($lp_id) {
    // update existing loyalty
    $stmt = $pdo->prepare("
        UPDATE loyalty_points
        SET points_earned=?
        WHERE id=?
    ");
    $stmt->execute([$loyalty_earned, $lp_id]);
} else {
    // create loyalty if missing
    $stmt = $pdo->prepare("
        INSERT INTO loyalty_points
        (org_id,outlet_id,customer_id,sale_id,points_earned,points_redeemed)
        VALUES (?,?,?,?,?,0)
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $customer_id,
        $sale_id,
        $loyalty_earned
    ]);
}

$pdo->commit();


    /* =================================================
       RESPONSE (SAME FORMAT AS CREATE)
    ================================================= */
    $total_discount = 0;
    foreach ($items as $it) {
        $total_discount += (float)($it['discount_amount'] ?? 0) * (float)$it['quantity'];
    }

    sendSuccess([
        "sale_id"     => $sale_id,
        "outlet"      => $outlet,
        "customer_id" => $customer_id,
        "items"       => $items,
        "summary" => [
            "taxable_amount" => round($taxable_total,2),
            "discount_total" => round($total_discount,2),
            "cgst"           => round($cgst_total,2),
            "sgst"           => round($sgst_total,2),
            "igst"           => round($igst_total,2),
            "round_off"      => round($round_off,2),
            "grand_total"    => $final_total
        ],
        "loyalty" => [
            "points_earned" => $loyalty_earned,
            "basis"         => "1 point per ₹100",
            "sale_id"       => $sale_id
        ]
    ], "Sale updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Update failed: ".$e->getMessage());
}
