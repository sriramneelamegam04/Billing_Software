<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../models/Subscription.php';
require_once __DIR__ . '/../../config/payments.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}
// Razorpay sends either JSON (webhook) or POST form params (checkout)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// If JSON is empty, fallback to form POST
if (!$data || !isset($data['razorpay_order_id'])) {
    $data = $_POST;
}

if (
    empty($data['razorpay_order_id']) ||
    empty($data['razorpay_payment_id']) ||
    empty($data['razorpay_signature'])
) {
    sendError("Missing payment params", 400);
}

$order_id = trim($data['razorpay_order_id']);
$payment_id = trim($data['razorpay_payment_id']);
$signature = trim($data['razorpay_signature']);

// ✅ Check Razorpay SDK
if (!class_exists('\Razorpay\Api\Api')) {
    sendError("Razorpay SDK not installed. Run: composer require razorpay/razorpay", 500);
}

try {
    $api = new \Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

    // ✅ Verify signature
    $attributes = [
        'razorpay_order_id' => $order_id,
        'razorpay_payment_id' => $payment_id,
        'razorpay_signature' => $signature
    ];
    $api->utility->verifyPaymentSignature($attributes);

    // ✅ Activate subscription
    $subscriptionModel = new Subscription($pdo);
    $subscription = $subscriptionModel->getByOrderId($order_id);

    if (!$subscription) {
        sendError("Subscription not found for order ID: $order_id", 404);
    }

    // ✅ Update subscription to ACTIVE
    $subscriptionModel->activateByOrderId($order_id, [
        'razorpay_payment_id' => $payment_id,
        'razorpay_signature' => $signature
    ]);

    sendSuccess([
        'subscription_id' => $subscription['id'],
        'plan' => $subscription['plan'],
        'status' => 'ACTIVE',
        'starts_at' => $subscription['starts_at'],
        'expires_at' => $subscription['expires_at']
    ], "Subscription activated successfully");

} catch (\Razorpay\Api\Errors\SignatureVerificationError $e) {
    sendError("Signature verification failed: " . $e->getMessage(), 400);
} catch (Exception $e) {
    sendError("Payment verification error: " . $e->getMessage(), 400);
}
