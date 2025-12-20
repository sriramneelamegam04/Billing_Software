<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__ . '/../../models/Subscription.php';
require_once __DIR__.'/../../services/HookService.php';


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
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use DELETE"]);
    exit;
}


$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);

$input = json_decode(file_get_contents("php://input"), true);
if(!$input) sendError("Invalid JSON format");

// Required fields
$required = ['id', 'outlet_id'];
foreach($required as $f){
    if(empty($input[$f])) sendError("$f is required");
}

$id = (int)$input['id'];
$outlet_id = (int)$input['outlet_id'];

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

// Check customer exists and belongs to this org & outlet
$stmt = $pdo->prepare("SELECT id FROM customers WHERE id=? AND org_id=? AND outlet_id=?");
$stmt->execute([$id, $authUser['org_id'], $outlet_id]);
if(!$stmt->fetch()) sendError("Customer not found in this outlet or organization");

// Delete
$stmt = $pdo->prepare("DELETE FROM customers WHERE id=? AND org_id=? AND outlet_id=?");
$stmt->execute([$id, $authUser['org_id'], $outlet_id]);

sendSuccess([], "Customer deleted successfully");
