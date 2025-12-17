<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Payment.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON");

if (empty($input['payment_id'])) {
    sendError("payment_id is required");
}

$payment_id   = (int)$input['payment_id'];
$payment_mode = isset($input['payment_mode']) ? trim($input['payment_mode']) : null;
$meta_input   = $input['meta'] ?? [];

/* -------------------------------------------------
   FETCH PAYMENT
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT p.*, s.id AS sale_id, s.org_id, s.outlet_id,
           s.customer_id, s.total_amount, s.status,
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
   BLOCK UPDATE IF SALE NOT PAID YET
------------------------------------------------- */
if ((int)$payment['status'] !== 1) {
    sendError("Cannot update payment until sale is PAID", 409);
}

/* -------------------------------------------------
   BASE AMOUNTS (FROM SALE)
------------------------------------------------- */
$original_amount = (float)$payment['total_amount'];
$final_amount    = $original_amount;

$vertical       = strtolower($payment['vertical'] ?? 'generic');
$redeem_points  = 0;
$redeem_value   = 0;

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

        $redeem_value = $redeem_points; // ₹1 per point
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
        $final_amount,
        $payment_mode,
        json_encode([
            'original_amount' => $original_amount,
            'redeem_points'   => $redeem_points,
            'redeem_value'    => $redeem_value,
            'user_meta'       => $meta_input,
            'gst' => [
                'cgst' => $payment['cgst'] ?? 0,
                'sgst' => $payment['sgst'] ?? 0,
                'igst' => $payment['igst'] ?? 0
            ]
        ], JSON_UNESCAPED_UNICODE),
        $payment_id,
        $authUser['org_id']
    ]);

    /* -------------------------
       UPDATE LOYALTY REDEEM (DELTA SAFE)
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
    sendError("Failed to update payment: " . $e->getMessage());
}
