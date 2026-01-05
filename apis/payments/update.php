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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed. Use POST", 405);
}

/* ================= AUTH ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* ================= SUBSCRIPTION ================= */
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* ================= INPUT ================= */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON");

if (empty($input['payment_id'])) {
    sendError("payment_id is required");
}

$payment_id   = (int)$input['payment_id'];
$payment_mode = $input['payment_mode'] ?? null;
$meta_input   = $input['meta'] ?? [];

/* ================= FETCH PAYMENT + SALE ================= */
$stmt = $pdo->prepare("
    SELECT 
        p.id AS payment_id,
        p.sale_id,
        p.payment_mode,
        p.amount,
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
    JOIN sales s   ON s.id = p.sale_id
    JOIN outlets o ON o.id = s.outlet_id
    WHERE p.id=? AND p.org_id=?
    LIMIT 1
");
$stmt->execute([$payment_id, $authUser['org_id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    sendError("Payment not found", 404);
}

/* ================= SALE MUST BE PAID ================= */
if ((int)$payment['status'] !== 1) {
    sendError("Only PAID sales can update payment", 409);
}

/* ================= BASE AMOUNT ================= */
$original_amount = (float)$payment['total_amount'];
$final_amount    = $original_amount;

$vertical = strtolower($payment['vertical'] ?? 'generic');

/* ================= MANUAL DISCOUNT ================= */
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

/* ================= LOYALTY REDEEM ================= */
$redeem_points = 0;
$redeem_value  = 0;

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

        $redeem_value = $redeem_points;
        $final_amount -= $redeem_value;
    }
}

$final_amount = max(0, round($final_amount, 2));

/* ================= TRANSACTION ================= */
try {
    $pdo->beginTransaction();

    /* ---------- UPDATE PAYMENT ---------- */
    $stmt = $pdo->prepare("
        UPDATE payments
        SET 
            amount = ?,
            payment_mode = COALESCE(?, payment_mode),
            meta = ?
        WHERE id=? AND org_id=?
    ");

    $stmt->execute([
        $final_amount,
        $payment_mode,
        json_encode([
            'original_amount' => round($original_amount,2),
            'manual_discount' => round($manual_discount,2),
            'redeem_points'   => $redeem_points,
            'redeem_value'    => round($redeem_value,2),
            'gst_summary' => [
                'cgst' => (float)$payment['cgst'],
                'sgst' => (float)$payment['sgst'],
                'igst' => (float)$payment['igst']
            ],
            'user_meta' => $meta_input
        ], JSON_UNESCAPED_UNICODE),
        $payment_id,
        $authUser['org_id']
    ]);

    /* ---------- UPSERT LOYALTY REDEEM ---------- */
    if ($redeem_points > 0) {

        $stmt = $pdo->prepare("
            SELECT id FROM loyalty_points
            WHERE sale_id=? AND org_id=? AND points_redeemed > 0
            LIMIT 1
        ");
        $stmt->execute([$payment['sale_id'], $payment['org_id']]);
        $lp_id = $stmt->fetchColumn();

        if ($lp_id) {
            $stmt = $pdo->prepare("
                UPDATE loyalty_points
                SET points_redeemed=?
                WHERE id=?
            ");
            $stmt->execute([$redeem_points, $lp_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO loyalty_points
                (org_id,outlet_id,customer_id,sale_id,points_earned,points_redeemed,created_at)
                VALUES (?,?,?,?,0,?,NOW())
            ");
            $stmt->execute([
                $payment['org_id'],
                $payment['outlet_id'],
                $payment['customer_id'],
                $payment['sale_id'],
                $redeem_points
            ]);
        }
    }

    $pdo->commit();

    sendSuccess([
        'payment_id'      => $payment_id,
        'sale_id'         => $payment['sale_id'],
        'payment_mode'    => $payment_mode ?? $payment['payment_mode'],
        'original_amount' => round($original_amount,2),
        'manual_discount' => round($manual_discount,2),
        'redeemed_points' => $redeem_points,
        'redeem_value'    => round($redeem_value,2),
        'final_amount'    => round($final_amount,2),
        'status'          => 'PAID'
    ], "Payment updated successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to update payment: ".$e->getMessage());
}
