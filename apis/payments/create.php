<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/validation.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Payment.php';
require_once __DIR__.'/../../models/Sale.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);

// ✅ Decode JSON safely
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if(json_last_error() !== JSON_ERROR_NONE) {
    sendError("Invalid JSON format: " . json_last_error_msg());
}

// ✅ Required fields
$required = ['sale_id','amount','payment_mode','outlet_id','customer_id'];
foreach($required as $field){
    if(!isset($input[$field]) || $input[$field]==='') {
        sendError("$field is required");
    }
}

$sale_id   = (int)$input['sale_id'];
$outlet_id = (int)$input['outlet_id'];

// ✅ Validate sale exists in this org + outlet
$stmt = $pdo->prepare("SELECT id,total_amount,discount,cgst,sgst,igst,customer_id,status 
                       FROM sales WHERE id=? AND org_id=? AND outlet_id=? LIMIT 1");
$stmt->execute([$sale_id, $authUser['org_id'], $outlet_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$sale) sendError("Sale not found or does not belong to this outlet/org", 404);

// ✅ Check if already paid
if((int)$sale['status'] === 1){
    sendError("Payment already completed for this sale",409);
}

try {
    $pdo->beginTransaction();

    $originalAmount = (float)$input['amount'];
    $redeemPoints   = isset($input['redeem_points']) ? (float)$input['redeem_points'] : 0;
    $redeemValue    = 0;
    $finalAmount    = $originalAmount;

    $vertical = strtolower($authUser['vertical'] ?? 'generic');

    // 🔹 Loyalty redemption (skip if restaurant)
    if ($vertical !== 'restaurant' && $redeemPoints > 0) {
        // Check customer balance
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(points_earned - points_redeemed),0) as balance
            FROM loyalty_points
            WHERE customer_id = ? AND org_id = ?
        ");
        $stmt->execute([$input['customer_id'], $authUser['org_id']]);
        $balance = (float)$stmt->fetchColumn();

        if ($redeemPoints > $balance) {
            throw new Exception("Insufficient loyalty points. Available: $balance");
        }

        // Reduce final amount
        $redeemValue = $redeemPoints; // 1 point = ₹1
        $finalAmount = max(0, $originalAmount - $redeemValue);

        // Insert redemption record
        $stmt2 = $pdo->prepare("
            INSERT INTO loyalty_points (org_id,outlet_id,customer_id,sale_id,points_earned,points_redeemed)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt2->execute([
            $authUser['org_id'],
            $outlet_id,
            $input['customer_id'],
            $sale_id,
            0,
            $redeemPoints
        ]);
    }

    // 🔹 Insert payment
    $paymentModel = new Payment($pdo);
    $payment_id = $paymentModel->create([
        'sale_id'      => $sale_id,
        'org_id'       => $authUser['org_id'],
        'outlet_id'    => $outlet_id,
        'amount'       => $finalAmount,
        'payment_mode' => $input['payment_mode'],
        'meta'         => json_encode([
            'original_amount' => $originalAmount,
            'redeem_points'   => $redeemPoints,
            'redeem_value'    => $redeemValue,
            'gst' => [
                'cgst' => $sale['cgst'],
                'sgst' => $sale['sgst'],
                'igst' => $sale['igst']
            ]
        ])
    ]);

    // 🔹 Update sale status → paid (1)
    $upd = $pdo->prepare("UPDATE sales SET status = 1, customer_id = ? WHERE id = ?");
    $upd->execute([$input['customer_id'], $sale_id]);

    $pdo->commit();

    sendSuccess([
        'payment_id'      => $payment_id,
        'sale_id'         => $sale_id,
        'original_amount' => $originalAmount,
        'redeemed_points' => ($vertical !== 'restaurant') ? $redeemPoints : 0,
        'redeem_value'    => ($vertical !== 'restaurant') ? $redeemValue : 0,
        'final_amount'    => $finalAmount,
        'gst'             => [
            'cgst' => $sale['cgst'],
            'sgst' => $sale['sgst'],
            'igst' => $sale['igst']
        ]
    ], "Payment created successfully and sale marked as paid");

} catch(Exception $e) {
    $pdo->rollBack();
    sendError("Error: ".$e->getMessage());
}
