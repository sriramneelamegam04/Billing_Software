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
   Validate outlet belongs to org
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id FROM outlets 
    WHERE id=? AND org_id=? LIMIT 1
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet_id",403);

/* -------------------------------------------------
   Validate items array
------------------------------------------------- */
if (!is_array($input['items']) || count($input['items']) === 0) {
    sendError("Items array must not be empty");
}

/* =================================================
   BARCODE RESOLUTION (UNCHANGED)
================================================= */
foreach ($input['items'] as &$item) {

    $item['barcode'] = isset($item['barcode']) ? trim($item['barcode']) : null;

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
        if (!$pid) sendError("Product not found for barcode: {$item['barcode']}",404);

        $item['product_id'] = (int)$pid;
    }

    $item['variant_id'] = isset($item['variant_id']) && $item['variant_id'] !== ""
        ? (int)$item['variant_id']
        : null;

    if (empty($item['product_id'])) sendError("product_id missing");
    if (empty($item['quantity']) || $item['quantity'] <= 0) {
        sendError("quantity missing or invalid");
    }
}
unset($item);

/* =================================================
   AUTO RATE + AMOUNT (UNCHANGED)
================================================= */
foreach ($input['items'] as &$item) {

    if ($item['variant_id']) {
        $stmt = $pdo->prepare("
            SELECT v.price
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id=? AND p.org_id=? AND p.outlet_id=?
        ");
        $stmt->execute([
            $item['variant_id'],
            $authUser['org_id'],
            $outlet_id
        ]);
        $rate = $stmt->fetchColumn();
        if ($rate === false) sendError("Invalid variant_id {$item['variant_id']}");
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
        if ($rate === false) sendError("Invalid product_id {$item['product_id']}");
    }

    $item['rate']   = (float)$rate;
    $item['amount'] = $rate * $item['quantity'];
}
unset($item);

$input['discount'] = $input['discount'] ?? 0;

/* =================================================
   STOCK CHECK (UNCHANGED)
================================================= */
foreach ($input['items'] as $item) {

    $stmt = $pdo->prepare("
        SELECT quantity FROM inventory
        WHERE org_id=? AND outlet_id=? 
          AND product_id=? 
          AND ((variant_id IS NULL AND ? IS NULL) OR (variant_id = ?))
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
   GST FETCH FROM ORGS (NEW)
================================================= */
$stmt = $pdo->prepare("
    SELECT gst_type, gst_rate FROM orgs WHERE id=? LIMIT 1
");
$stmt->execute([$authUser['org_id']]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

$gst_type = $org['gst_type'] ?? null;
$gst_rate = (float)($org['gst_rate'] ?? 0);

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

    /* ---------- TOTALS ---------- */
    $sub_total = 0;
    foreach ($input['items'] as $i) {
        $sub_total += $i['amount'];
    }

    $discount = (float)$input['discount'];
    $taxable  = max(0, $sub_total - $discount);

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

    $gst_total  = $cgst + $sgst + $igst;
    $gross      = $taxable + $gst_total;
    $round_off  = round($gross) - $gross;
    $final_total = round($gross);

    $input['total_amount'] = $final_total;

    $billingService = new BillingService($pdo);
    $result = $billingService->createSale(
    $authUser['org_id'],
    array_merge($input, [
        'customer_id' => $customer_id, // 🔥 DO NOT REMOVE
        'status'      => 0,
        'cgst'        => round($cgst,2),
        'sgst'        => round($sgst,2),
        'igst'        => round($igst,2)
    ])
);


    /* ---------- LOYALTY (UNCHANGED) ---------- */
    $result['loyalty_points_earned'] = 0;
    if (strtolower($vertical) !== "restaurant") {
        $pts = $final_total / 100;
        if ($pts > 0) {
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
                $pts
            ]);
            $result['loyalty_points_earned'] = (float)$pts;
        }
    }

    $pdo->commit();

    /* =================================================
       RESPONSE (ENRICHED)
    ================================================= */
    $result['items'] = $input['items'];
    $result['sub_total'] = round($sub_total,2);
    $result['discount']  = round($discount,2);
    $result['taxable_amount'] = round($taxable,2);
    $result['gst'] = [
        'type' => $gst_type,
        'rate' => $gst_rate,
        'cgst' => round($cgst,2),
        'sgst' => round($sgst,2),
        'igst' => round($igst,2)
    ];
    $result['round_off'] = round($round_off,2);
    $result['total_amount'] = $final_total;


    // 🔥 OPTION 1 FIX: remove duplicate top-level GST values
    unset($result['cgst'], $result['sgst'], $result['igst']);


    sendSuccess($result, "Sale created successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed: ".$e->getMessage());
}
