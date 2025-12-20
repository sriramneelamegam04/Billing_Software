<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Payment.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__ . '/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed. Use POST", 405);
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
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON");

if (empty($input['payment_id'])) {
    sendError("payment_id is required");
}

$payment_id   = (int)$input['payment_id'];
$payment_mode = isset($input['payment_mode']) ? trim($input['payment_mode']) : null;
$meta_input   = $input['meta'] ?? [];

/* -------------------------------------------------
   FETCH PAYMENT + SALE (SOURCE OF TRUTH)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT 
        p.id AS payment_id,
        p.payment_mode,
        p.amount,
        s.id AS sale_id,
        s.org_id,
        s.outlet_id,
        s.customer_id,
        s.total_amount,
        s.status,
        s.cgst,
        s.sgst,
        s.igst,
        o.vertical
    FROM payments p
    JOIN sales s ON s.id = p.sale_id
    JOIN outlets o ON o.id = s.outlet_id
    WHERE p.id=? AND p.org_id=?
    LIMIT 1
");
$stmt->execute([$payment_id, $authUser['org_id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    sendError("Payment not found or unauthorized", 404);
}

/* -------------------------------------------------
   SALE MUST BE PAID
------------------------------------------------- */
if ((int)$payment['status'] !== 1) {
    sendError("Cannot update payment until sale is PAID", 409);
}

/* -------------------------------------------------
   BASE AMOUNTS (FROM SALE)
------------------------------------------------- */
$original_amount = (float)$payment['total_amount'];
$final_amount    = $original_amount;

$vertical      = strtolower($payment['vertical'] ?? 'generic');
$redeem_points = 0;
$redeem_value  = 0;

/* -------------------------------------------------
   LOYALTY REDEEM (SAME AS CREATE)
------------------------------------------------- */
if ($vertical !== 'restaurant' && isset($meta_input['redeem_points'])) {

    $redeem_points = (float)$meta_input['redeem_points'];

    if ($redeem_points < 0) {
        sendError("redeem_points cannot be negative");
    }

    if ($redeem_points > 0) {

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(points_earned - points_redeemed),0)
            FROM loyalty_points
            WHERE org_id=? AND customer_id=?
        ");
        $stmt->execute([
            $payment['org_id'],
            $payment['customer_id']
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
   UPDATE PAYMENT
------------------------------------------------- */
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE payments
        SET 
            amount = ?,
            payment_mode = COALESCE(?, payment_mode),
            meta = ?
        WHERE id=? AND org_id=?
    ");

    $stmt->execute([
        round($final_amount,2),
        $payment_mode,
        json_encode([
            'original_amount' => round($original_amount,2),
            'redeem_points'   => $redeem_points,
            'redeem_value'    => round($redeem_value,2),
            'user_meta'       => $meta_input,
            'gst_summary' => [
                'cgst' => (float)$payment['cgst'],
                'sgst' => (float)$payment['sgst'],
                'igst' => (float)$payment['igst']
            ]
        ], JSON_UNESCAPED_UNICODE),
        $payment_id,
        $authUser['org_id']
    ]);

    /* -------------------------
       LOYALTY REDEEM ENTRY
    ------------------------- */
    if ($redeem_points > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO loyalty_points
            (org_id,outlet_id,customer_id,sale_id,points_earned,points_redeemed)
            VALUES (?,?,?,?,0,?)
        ");
        $stmt->execute([
            $payment['org_id'],
            $payment['outlet_id'],
            $payment['customer_id'],
            $payment['sale_id'],
            $redeem_points
        ]);
    }

    $pdo->commit();

    sendSuccess([
        'payment_id'      => $payment_id,
        'sale_id'         => $payment['sale_id'],
        'payment_mode'    => $payment_mode ?? $payment['payment_mode'],
        'original_amount' => round($original_amount,2),
        'redeemed_points' => $redeem_points,
        'redeem_value'    => round($redeem_value,2),
        'final_amount'    => round($final_amount,2),
        'status'          => 'PAID'
    ], "Payment updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to update payment: ".$e->getMessage());
}
