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
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   Parse JSON
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON");

/* -------------------------------------------------
   Required Fields
------------------------------------------------- */
foreach (['outlet_id','items','customer_id'] as $f) {
    if (!isset($input[$f]) || $input[$f] === "") {
        sendError("$f is required");
    }
}

$outlet_id   = (int)$input['outlet_id'];
$customer_id = (int)$input['customer_id'];

/* -------------------------------------------------
   Validate outlet
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM outlets
    WHERE id=? AND org_id=? LIMIT 1
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$outlet) {
    sendError("Invalid outlet_id", 403);
}


/* -------------------------------------------------
   Validate items
------------------------------------------------- */
if (!is_array($input['items']) || count($input['items']) === 0) {
    sendError("Items array must not be empty");
}

/* =================================================
   BARCODE RESOLUTION
================================================= */
foreach ($input['items'] as &$item) {

    $item['barcode'] = $item['barcode'] ?? null;

    if (!empty($item['barcode']) && empty($item['product_id'])) {

        $stmt = $pdo->prepare("
            SELECT id FROM products
            WHERE org_id=? AND outlet_id=?
              AND JSON_UNQUOTE(JSON_EXTRACT(meta,'$.barcode')) = ?
            LIMIT 1
        ");
        $stmt->execute([
            $authUser['org_id'],
            $outlet_id,
            $item['barcode']
        ]);

        $pid = $stmt->fetchColumn();
        if (!$pid) sendError("Product not found for barcode {$item['barcode']}");

        $item['product_id'] = (int)$pid;
    }

    $item['variant_id'] = !empty($item['variant_id'])
        ? (int)$item['variant_id']
        : null;

    if (empty($item['product_id'])) sendError("product_id missing");
    if (empty($item['quantity']) || $item['quantity'] <= 0) {
        sendError("quantity missing or invalid");
    }
}
unset($item);

/* =================================================
   AUTO RATE FETCH
================================================= */
foreach ($input['items'] as &$item) {

    if (!isset($item['rate']) || $item['rate'] === "") {

        if ($item['variant_id']) {
            $stmt = $pdo->prepare("SELECT price FROM product_variants WHERE id=?");
            $stmt->execute([$item['variant_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT price FROM products WHERE id=?");
            $stmt->execute([$item['product_id']]);
        }

        $item['rate'] = (float)$stmt->fetchColumn();
        if ($item['rate'] <= 0) {
            sendError("Rate not found for product_id {$item['product_id']}");
        }
    }
}
unset($item);

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

$gst_type = 'CGST_SGST';

$taxable_total = 0;
$cgst_total = 0;
$sgst_total = 0;
$igst_total = 0;
$grand_total = 0;

foreach ($input['items'] as &$item) {

    /* -------------------------------
       GST RATE
    -------------------------------- */
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

    /* -------------------------------
       🔥 AUTO PRODUCT / VARIANT DISCOUNT
    -------------------------------- */
    $discount = getItemDiscount(
        $pdo,
        $item['product_id'],
        $item['variant_id']
    );

    $discount_amount = 0;

    if (!empty($discount)) {
        if ($discount['type'] === 'percentage') {
            $discount_amount = ($rate * $discount['value']) / 100;
        } elseif ($discount['type'] === 'flat') {
            $discount_amount = $discount['value'];
        }
    }

    $final_rate = max(0, round($rate - $discount_amount, 2));

    /* -------------------------------
       TAXABLE AFTER DISCOUNT
    -------------------------------- */
    $taxable = round($final_rate * $qty, 2);

    /* -------------------------------
       GST CALCULATION
    -------------------------------- */
    $gst_total_item = round(($taxable * $gst_rate) / 100, 2);

    $cgst = $sgst = $igst = 0;
    if ($gst_type === 'CGST_SGST') {
        $cgst = round($gst_total_item / 2, 2);
        $sgst = round($gst_total_item / 2, 2);
    } else {
        $igst = $gst_total_item;
    }

    $line_total = round($taxable + $cgst + $sgst + $igst, 2);

    /* -------------------------------
       ASSIGN TO ITEM
    -------------------------------- */
    $item['original_rate']  = $rate;
    $item['discount']       = $discount;
    $item['discount_amount']= round($discount_amount,2);
    $item['rate']           = $final_rate;
    $item['taxable_amount'] = $taxable;
    $item['gst_rate']       = $gst_rate;
    $item['cgst']           = $cgst;
    $item['sgst']           = $sgst;
    $item['igst']           = $igst;
    $item['amount']         = $line_total;

    /* -------------------------------
       TOTALS
    -------------------------------- */
    $taxable_total += $taxable;
    $cgst_total    += $cgst;
    $sgst_total    += $sgst;
    $igst_total    += $igst;
    $grand_total   += $line_total;
}
unset($item);


/* =================================================
   STOCK CHECK
================================================= */
foreach ($input['items'] as $item) {

    $stmt = $pdo->prepare("
        SELECT quantity FROM inventory
        WHERE org_id=? AND outlet_id=?
          AND product_id=?
          AND ((variant_id IS NULL AND ? IS NULL) OR (variant_id=?))
        LIMIT 1
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $item['product_id'],
        $item['variant_id'],
        $item['variant_id']
    ]);

    if ((float)$stmt->fetchColumn() < $item['quantity']) {
        sendError("Insufficient stock for product_id {$item['product_id']}");
    }
}

/* =================================================
   MAIN TRANSACTION
================================================= */
try {
    $pdo->beginTransaction();

    (new SubscriptionService($pdo))
        ->checkActive($authUser['org_id']);

    $vertical = $authUser['vertical'] ?? 'Generic';
    if (method_exists('HookService','callHook')) {
        $input = HookService::callHook($vertical,'beforeSaleCreate',$input);
    }

    $round_off   = round($grand_total) - $grand_total;
    $final_total = round($grand_total);

    $billingService = new BillingService($pdo);
    $result = $billingService->createSale(
        $authUser['org_id'],
        array_merge($input, [
            'customer_id'    => $customer_id,
            'status'         => 0,
            'taxable_amount' => round($taxable_total,2),
            'cgst'           => round($cgst_total,2),
            'sgst'           => round($sgst_total,2),
            'igst'           => round($igst_total,2),
            'round_off'      => round($round_off,2),
            'total_amount'   => $final_total
        ])
    );

   /* ---------- LOYALTY ---------- */
$loyalty_earned = 0;

if (strtolower($vertical) !== "restaurant") {
    $pts = $final_total / 100;
    if ($pts > 0) {
        $loyalty_earned = round($pts, 2);

        $stmt = $pdo->prepare("
            INSERT INTO loyalty_points
            (org_id,outlet_id,customer_id,sale_id,points_earned,points_redeemed)
            VALUES (?,?,?,?,?,0)
        ");
        $stmt->execute([
            $authUser['org_id'],
            $outlet_id,
            $customer_id,
            $result['sale_id'],
            $loyalty_earned
        ]);
    }
}

    $pdo->commit();
    /* =================================================
   🔥 ENHANCED RESPONSE (UPDATED)
================================================= */

// calculate total discount from items
$total_discount = 0;
foreach ($input['items'] as $it) {
    $total_discount += (float)($it['discount_amount'] ?? 0) * (float)$it['quantity'];
}

sendSuccess([
    "sale_id"     => $result['sale_id'],
    "outlet"      => $outlet,
    "customer_id" => $customer_id,

    /* -----------------------------
       ITEM DETAILS (FULL SNAPSHOT)
    ----------------------------- */
    "items" => array_map(function ($item) {
        return [
            "product_id"       => $item['product_id'],
            "variant_id"       => $item['variant_id'] ?? null,
            "quantity"         => $item['quantity'],

            "original_rate"    => $item['original_rate'],
            "discount"         => $item['discount'] ?? null,
            "discount_amount"  => $item['discount_amount'] ?? 0,

            "final_rate"       => $item['rate'],
            "taxable_amount"   => $item['taxable_amount'],

            "gst_rate"         => $item['gst_rate'],
            "cgst"             => $item['cgst'],
            "sgst"             => $item['sgst'],
            "igst"             => $item['igst'],

            "line_total"       => $item['amount']
        ];
    }, $input['items']),

    /* -----------------------------
       BILL SUMMARY
    ----------------------------- */
    "summary" => [
        "taxable_amount" => round($taxable_total, 2),

        "discount_total" => round($total_discount, 2),

        "cgst"           => round($cgst_total, 2),
        "sgst"           => round($sgst_total, 2),
        "igst"           => round($igst_total, 2),

        "round_off"      => round($round_off, 2),
        "grand_total"    => $final_total
    ],
 
     /* -----------------------------
       LOYALTY DETAILS
    ----------------------------- */
    "loyalty" => [
        "points_earned" => $loyalty_earned,
        "basis"         => "1 point per ₹100",
        "sale_id"       => $result['sale_id']
    ]
], "Sale created successfully");


} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed: ".$e->getMessage());
}
