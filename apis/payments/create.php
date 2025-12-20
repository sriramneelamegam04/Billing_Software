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

/* -------------------------------------------------
   METHOD VALIDATION
------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   PARSE JSON
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON");

/* -------------------------------------------------
   REQUIRED FIELDS
------------------------------------------------- */
foreach (['sale_id','payment_mode'] as $f) {
    if (!isset($input[$f]) || $input[$f] === '') {
        sendError("$f is required");
    }
}

$sale_id      = (int)$input['sale_id'];
$payment_mode = trim($input['payment_mode']);
$meta_input   = $input['meta'] ?? [];

/* -------------------------------------------------
   FETCH SALE (SINGLE SOURCE OF TRUTH)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT 
        s.id,
        s.org_id,
        s.outlet_id,
        s.customer_id,
        s.total_amount,
        s.discount,
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
    sendError("Sale not found or does not belong to your organization", 404);
}

/* -------------------------------------------------
   ALREADY PAID CHECK
------------------------------------------------- */
if ((int)$sale['status'] === 1) {
    sendError("Payment already completed for this sale", 409);
}

/* -------------------------------------------------
   BASE AMOUNTS (FROM SALE)
------------------------------------------------- */
$original_amount = (float)$sale['total_amount'];
$final_amount    = $original_amount;

$vertical       = strtolower($sale['vertical'] ?? 'generic');
$redeem_points  = 0;
$redeem_value   = 0;

/* -------------------------------------------------
   LOYALTY REDEMPTION (OPTIONAL)
------------------------------------------------- */
if ($vertical !== 'restaurant' && isset($meta_input['redeem_points'])) {

    $redeem_points = (float)$meta_input['redeem_points'];

    if ($redeem_points > 0) {

        // Fetch available balance
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
        $final_amount = max(0, $original_amount - $redeem_value);
    }
}

/* -------------------------------------------------
   MAIN TRANSACTION
------------------------------------------------- */
try {
    $pdo->beginTransaction();

    /* -------------------------
       INSERT PAYMENT
    ------------------------- */
    $paymentModel = new Payment($pdo);
    $payment_id = $paymentModel->create([
        'sale_id'      => $sale_id,
        'org_id'       => $sale['org_id'],
        'outlet_id'    => $sale['outlet_id'],
        'amount'       => round($final_amount,2),
        'payment_mode' => $payment_mode,
        'meta'         => json_encode([
            'original_amount' => round($original_amount,2),
            'redeem_points'   => $redeem_points,
            'redeem_value'    => round($redeem_value,2),
            'user_meta'       => $meta_input,
            'gst_summary' => [
                'cgst' => (float)$sale['cgst'],
                'sgst' => (float)$sale['sgst'],
                'igst' => (float)$sale['igst']
            ]
        ], JSON_UNESCAPED_UNICODE)
    ]);

    /* -------------------------
       INSERT LOYALTY REDEEM ENTRY
    ------------------------- */
    if ($redeem_points > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO loyalty_points
            (org_id,outlet_id,customer_id,sale_id,points_earned,points_redeemed)
            VALUES (?,?,?,?,0,?)
        ");
        $stmt->execute([
            $sale['org_id'],
            $sale['outlet_id'],
            $sale['customer_id'],
            $sale_id,
            $redeem_points
        ]);
    }

    /* -------------------------
       UPDATE SALE → PAID
    ------------------------- */
    $stmt = $pdo->prepare("
        UPDATE sales SET status=1 WHERE id=? AND org_id=?
    ");
    $stmt->execute([$sale_id, $sale['org_id']]);

    $pdo->commit();

    /* -------------------------
       RESPONSE
    ------------------------- */
    sendSuccess([
        'payment_id'      => $payment_id,
        'sale_id'         => $sale_id,
        'payment_mode'    => $payment_mode,
        'original_amount' => round($original_amount,2),
        'redeemed_points' => $redeem_points,
        'redeem_value'    => round($redeem_value,2),
        'final_amount'    => round($final_amount,2),
        'status'          => 'PAID'
    ], "Payment created successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed: ".$e->getMessage());
}
