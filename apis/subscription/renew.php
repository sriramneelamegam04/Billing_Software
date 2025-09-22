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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['plan'])) {
    sendError("plan is required");
}

$plan = strtolower(trim($input['plan']));
$org_id = (int)$authUser['org_id'];

// ✅ Fetch org details
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

// ✅ Plan configuration
$plans = [
    'free' => [
        'price_inr' => 0,
        'duration' => '+7 days',
        'max_outlets' => 1
    ],
    'basic' => [
        'price_inr' => 499,
        'duration' => '+6 months',
        'max_outlets' => 5
    ],
    'premium' => [
        'price_inr' => 1999,
        'duration' => null, // lifetime
        'max_outlets' => null
    ]
];

if (!isset($plans[$plan])) {
    sendError("Invalid plan. Allowed: free, basic, premium");
}

// ✅ Prevent multiple free plan renewals
$subscriptionModel = new Subscription($pdo);
if ($plan === 'free') {
    $freeCheck = $subscriptionModel->getFreePlanHistory($org_id);
    if ($freeCheck) {
        sendError("Free plan already used. Cannot renew", 409);
    }
}

// ✅ Expire old subscription if active
$activeSub = $subscriptionModel->getActive($org_id);
if ($activeSub) {
    $subscriptionModel->expire($activeSub['id']);
}

// ✅ Calculate start & expiry dates
$config = $plans[$plan];
$starts_at = date("Y-m-d H:i:s");
$expires_at = $config['duration'] ? date("Y-m-d H:i:s", strtotime($config['duration'])) : null;
$max_outlets = $config['max_outlets'];

// ✅ Fetch features for vertical
$features = [];
try {
    $vfStmt = $pdo->prepare("
        SELECT f.key_name
        FROM vertical_features vf
        JOIN features f ON f.id = vf.feature_id
        WHERE vf.vertical = ?
    ");
    $vfStmt->execute([$org_vertical]);
    $rows = $vfStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($rows) $features = $rows;
} catch (Exception $e) {
    // Ignore errors
}

// ✅ If FREE plan → activate immediately
if ($config['price_inr'] <= 0) {
    $sub_id = $subscriptionModel->createImmediate([
        'org_id' => $org_id,
        'plan' => $plan,
        'allowed_verticals' => json_encode([$org_vertical]),
        'max_outlets' => $max_outlets,
        'features' => json_encode($features),
        'starts_at' => $starts_at,
        'expires_at' => $expires_at,
        'status' => 'ACTIVE'
    ]);

    sendSuccess([
        'subscription_id' => $sub_id,
        'plan' => $plan,
        'status' => 'ACTIVE',
        'starts_at' => $starts_at,
        'expires_at' => $expires_at,
        'features' => $features
    ], "Subscription renewed successfully");
}

// ✅ If PAID plan → Razorpay order
$price_inr = (int)$config['price_inr'];
$amount_paise = $price_inr * 100;

if (!class_exists('\Razorpay\Api\Api')) {
    sendError("Razorpay SDK not installed. Run: composer require razorpay/razorpay", 500);
}

require_once __DIR__ . '/../../config/payments.php';

try {
    $api = new \Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

    $order = $api->order->create([
        'receipt' => 'renew_' . uniqid(),
        'amount' => $amount_paise,
        'currency' => 'INR',
        'payment_capture' => 1
    ]);

    $sub_id = $subscriptionModel->createPending([
        'org_id' => $org_id,
        'plan' => $plan,
        'allowed_verticals' => json_encode([$org_vertical]),
        'max_outlets' => $max_outlets,
        'features' => json_encode($features),
        'starts_at' => $starts_at,
        'expires_at' => $expires_at,
        'status' => 'PENDING',
        'razorpay_order_id' => $order['id']
    ]);

    sendSuccess([
        'subscription_id' => $sub_id,
        'order_id' => $order['id'],
        'amount' => $amount_paise,
        'status' => 'PENDING'
    ], "Renewal order created. Complete payment to activate subscription");

} catch (Exception $e) {
    sendError("Payment gateway error: " . $e->getMessage(), 500);
}
