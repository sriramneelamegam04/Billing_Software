<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/validation.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Payment.php';
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
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   SUBSCRIPTION
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON");

foreach (['sale_id','payment_mode'] as $f) {
    if (!isset($input[$f]) || $input[$f] === '') {
        sendError("$f is required");
    }
}

$sale_id      = (int)$input['sale_id'];
$payment_mode = trim($input['payment_mode']);
$meta_input   = $input['meta'] ?? [];

/* -------------------------------------------------
   FETCH SALE
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT 
        s.id,
        s.org_id,
        s.outlet_id,
        s.customer_id,
        s.total_amount,
        s.cgst,
        s.sgst,
        s.igst,
        s.status,
        o.vertical
    FROM sales s
    JOIN outlets o ON o.id = s.outlet_id
    WHERE s.id=? AND s.org_id=?
    LIMIT 1
");
$stmt->execute([$sale_id, $authUser['org_id']]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    sendError("Sale not found", 404);
}

if ((int)$sale['status'] === 1) {
    sendError("Payment already completed", 409);
}

/* -------------------------------------------------
   BASE AMOUNT
------------------------------------------------- */
$original_amount = (float)$sale['total_amount'];
$final_amount    = $original_amount;

$vertical = strtolower($sale['vertical'] ?? 'generic');

/* =================================================
   MANUAL DISCOUNT
================================================= */
$manual_discount = 0;

if (isset($meta_input['manual_discount'])) {
    $manual_discount = (float)$meta_input['manual_discount'];

    if ($manual_discount < 0) {
        sendError("manual_discount cannot be negative");
    }

    if ($manual_discount > $original_amount) {
        sendError("manual_discount cannot exceed bill amount");
    }

    $final_amount -= $manual_discount;
}

/* =================================================
   LOYALTY REDEMPTION
================================================= */
$redeem_points = 0;
$redeem_value  = 0;

if ($vertical !== 'restaurant' && isset($meta_input['redeem_points'])) {

    $redeem_points = (float)$meta_input['redeem_points'];

    if ($redeem_points > 0) {

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(points_earned - points_redeemed),0)
            FROM loyalty_points
            WHERE org_id=? AND customer_id=?
        ");
        $stmt->execute([
            $sale['org_id'],
            $sale['customer_id']
        ]);
        $balance = (float)$stmt->fetchColumn();

        if ($redeem_points > $balance) {
            sendError("Insufficient loyalty points. Available: $balance");
        }

        // ₹1 per point
        $redeem_value = $redeem_points;
        $final_amount -= $redeem_value;
    }
}

/* SAFETY */
$final_amount = max(0, round($final_amount, 2));

/* =================================================
   TRANSACTION
================================================= */
try {
    $pdo->beginTransaction();

    /* -------------------------
       PAYMENT ENTRY
    ------------------------- */
    $paymentModel = new Payment($pdo);
    $payment_id = $paymentModel->create([
        'sale_id'      => $sale_id,
        'org_id'       => $sale['org_id'],
        'outlet_id'    => $sale['outlet_id'],
        'amount'       => $final_amount,
        'payment_mode' => $payment_mode,
        'meta'         => json_encode([
            'original_amount' => round($original_amount,2),
            'manual_discount' => round($manual_discount,2),
            'redeem_points'   => $redeem_points,
            'redeem_value'    => round($redeem_value,2),
            'gst_summary' => [
                'cgst' => (float)$sale['cgst'],
                'sgst' => (float)$sale['sgst'],
                'igst' => (float)$sale['igst']
            ],
            'user_meta' => $meta_input
        ], JSON_UNESCAPED_UNICODE)
    ]);

    /* -------------------------
       LOYALTY LOG
    ------------------------- */
  /* -------------------------
   LOYALTY REDEEM LOG (FIXED)
------------------------- */
if ($redeem_points > 0) {

    // 🔥 One row ONLY for redemption
    $stmt = $pdo->prepare("
        INSERT INTO loyalty_points
        (
            org_id,
            outlet_id,
            customer_id,
            sale_id,
            points_earned,
            points_redeemed,
            created_at
        )
        VALUES
        (
            :org_id,
            :outlet_id,
            :customer_id,
            :sale_id,
            0,
            :points_redeemed,
            NOW()
        )
    ");

    $stmt->execute([
        ':org_id'           => $sale['org_id'],
        ':outlet_id'        => $sale['outlet_id'],
        ':customer_id'      => $sale['customer_id'],
        ':sale_id'          => $sale_id,
        ':points_redeemed'  => $redeem_points
    ]);
}


    /* -------------------------
       SALE PAID
    ------------------------- */
    $pdo->prepare("
        UPDATE sales SET status=1 WHERE id=? AND org_id=?
    ")->execute([$sale_id, $sale['org_id']]);

    $pdo->commit();

    /* -------------------------
       RESPONSE
    ------------------------- */
    sendSuccess([
        'payment_id'      => $payment_id,
        'sale_id'         => $sale_id,
        'payment_mode'    => $payment_mode,
        'original_amount' => round($original_amount,2),
        'manual_discount' => round($manual_discount,2),
        'redeemed_points' => $redeem_points,
        'redeem_value'    => round($redeem_value,2),
        'final_amount'    => round($final_amount,2),
        'status'          => 'PAID'
    ], "Payment created successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed: ".$e->getMessage());
}
