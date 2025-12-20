<?php
// apis/subscriptions/create.php

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH, GET, OPTIONS");
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
// Read request
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendError("Invalid JSON input");
}

// Required fields
if (empty($input['org_id'])) {
    sendError("org_id is required");
}

$org_id = (int)$input['org_id'];
$plan   = "annual";  // FIXED PLAN

// Verify org exists & email verified
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

// Check if org already has an active subscription
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($org_id);
if ($activeSub) {
    sendError("Organization already has an active subscription ({$activeSub['plan']})", 409);
}

// -------------------------------------------------------------------
// NEW PLAN CONFIG (ONLY ONE PLAN)
// -------------------------------------------------------------------
$plans = [
    'annual' => [
        'price_inr'   => 10000,
        'duration'    => '+1 year',
        'max_outlets' => 0
    ]
];

$config = $plans['annual'];

// Expiry calculation
$starts_at   = date("Y-m-d H:i:s");
$expires_at  = date("Y-m-d H:i:s", strtotime($config['duration']));
$max_outlets = $config['max_outlets'];

// ---- Derive features from vertical_features
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

// -------------------------------------------------------------------
// PAID PLAN → Create Razorpay Order
// -------------------------------------------------------------------
$price_inr     = (int)$config['price_inr'];
$amount_paise  = $price_inr * 100;

if (!class_exists('\Razorpay\Api\Api')) {
    sendError("Razorpay SDK not installed. Run: composer require razorpay/razorpay", 500);
}

require_once __DIR__ . '/../../config/payments.php';

try {
    $api = new \Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

    $order = $api->order->create([
        'receipt'         => 'sub_' . uniqid(),
        'amount'          => $amount_paise,
        'currency'        => 'INR',
        'payment_capture' => 1
    ]);

    $subData = [
        'org_id'           => $org_id,
        'plan'             => $plan,
        'allowed_verticals'=> json_encode([$org_vertical]),
        'max_outlets'      => $max_outlets,
        'features'         => json_encode($features),
        'starts_at'        => $starts_at,
        'expires_at'       => $expires_at,
        'status'           => 'PENDING',
        'razorpay_order_id'=> $order['id']
    ];

    $sub_id = $subscriptionModel->createPending($subData);

    sendSuccess([
        'subscription_id' => $sub_id,
        'order_id'        => $order['id'],
        'amount'          => $amount_paise
    ], "Order created. Complete payment to activate subscription");

} catch (Exception $e) {
    sendError("Payment gateway error: " . $e->getMessage(), 500);
}

?>
