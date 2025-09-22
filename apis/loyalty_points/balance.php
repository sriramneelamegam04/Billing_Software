<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

// 🔐 JWT auth
$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);

// Input (customer_id + outlet_id required)
$input = json_decode(file_get_contents("php://input"), true);
if(!$input || empty($input['customer_id']) || empty($input['outlet_id'])) {
    sendError("customer_id and outlet_id are required");
}

$customer_id = (int)$input['customer_id'];
$outlet_id   = (int)$input['outlet_id'];

// Validate outlet belongs to this org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$outlet) sendError("Invalid outlet_id or does not belong to your organization", 403);

// Check if customer exists in the same org and outlet
$stmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE id=? AND org_id=? AND outlet_id=? LIMIT 1");
$stmt->execute([$customer_id, $authUser['org_id'], $outlet_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$customer) sendError("Customer not found in this outlet");


// Calculate balance for this org + outlet
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(points_earned),0) - COALESCE(SUM(points_redeemed),0) AS balance
    FROM loyalty_points
    WHERE customer_id=? AND org_id=? AND outlet_id=?
");
$stmt->execute([$customer_id, $authUser['org_id'], $outlet_id]);
$balance = (int)$stmt->fetchColumn();

sendSuccess([
    'customer_id' => $customer_id,
    'name'        => $customer['name'],
    'phone'       => $customer['phone'],
    'balance'     => $balance
], "Loyalty balance fetched successfully");
