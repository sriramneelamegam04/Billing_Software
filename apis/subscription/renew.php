<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

$org_id = (int)$authUser['org_id'];

// No plan input needed — ONLY the annual plan exists
$plan = "annual";

// Fetch org details
$stmt = $pdo->prepare("SELECT * FROM orgs WHERE id=? AND is_verified=1");
$stmt->execute([$org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    sendError("Organization not found or not verified");
}

$org_vertical = $org['vertical'] ?? null;
if (!$org_vertical) {
    sendError("Organization vertical is missing");
}

// Plan configuration (only one plan exists)
$planConfig = [
    'price_inr'   => 10000,
    'duration'    => '+1 year',
    'max_outlets' => 0
];

// Expire current active subscription (if exists)
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($org_id);

if ($activeSub) {
    $stmtExpire = $pdo->prepare("UPDATE subscriptions SET status='EXPIRED' WHERE id=?");
    $stmtExpire->execute([$activeSub['id']]);
}

// Calculate dates
$starts_at   = date("Y-m-d H:i:s");
$expires_at  = date("Y-m-d H:i:s", strtotime($planConfig['duration']));
$max_outlets = $planConfig['max_outlets'];

// Fetch available features for vertical
$features = [];
try {
    $vfStmt = $pdo->prepare("
        SELECT f.key_name
        FROM vertical_features vf
        JOIN features f ON f.id = vf.feature_id
        WHERE vf.vertical = ?
    ");
    $vfStmt->execute([$org_vertical]);
    $features = $vfStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    // ignore
}

// Payment details
$price_inr    = (int)$planConfig['price_inr'];
$amount_paise = $price_inr * 100;

if (!class_exists('\Razorpay\Api\Api')) {
    sendError("Razorpay SDK not installed. Run: composer require razorpay/razorpay", 500);
}

require_once __DIR__ . '/../../config/payments.php';

// Create Razorpay order
try {
    $api = new \Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

    $order = $api->order->create([
        'receipt'         => 'renew_' . uniqid(),
        'amount'          => $amount_paise,
        'currency'        => 'INR',
        'payment_capture' => 1
    ]);

    // Create new pending subscription
    $sub_id = $subscriptionModel->createPending([
        'org_id'           => $org_id,
        'plan'             => $plan,
        'allowed_verticals'=> json_encode([$org_vertical]),
        'max_outlets'      => $max_outlets,
        'features'         => json_encode($features),
        'starts_at'        => $starts_at,
        'expires_at'       => $expires_at,
        'status'           => 'PENDING',
        'razorpay_order_id'=> $order['id']
    ]);

    sendSuccess([
        'subscription_id' => $sub_id,
        'order_id'        => $order['id'],
        'amount'          => $amount_paise,
        'status'          => 'PENDING'
    ], "Renewal order generated. Complete payment to activate subscription");

} catch (Exception $e) {
    sendError("Payment gateway error: " . $e->getMessage(), 500);
}

?>
