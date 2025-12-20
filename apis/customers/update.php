<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__ . '/../../models/Subscription.php';

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


$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$input = json_decode(file_get_contents("php://input"), true);
if(!$input) sendError("Invalid JSON format");

// Required fields
$required = ['id','name','phone','outlet_id'];
foreach($required as $f){
    if(empty($input[$f])) sendError("$f is required");
}

$id = (int)$input['id'];
$name = strtolower(trim($input['name']));
$phone = strtolower(trim($input['phone']));
$outlet_id = (int)$input['outlet_id'];

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

// Check customer exists
$stmt = $pdo->prepare("SELECT id FROM customers WHERE id=? AND org_id=?");
$stmt->execute([$id,$authUser['org_id']]);
if(!$stmt->fetch()) sendError("Customer not found");

// Validate outlet belongs to this org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if(!$stmt->fetch()) sendError("Invalid outlet_id or does not belong to your organization");

// Duplicate check (same phone + outlet, ignore current id)
$stmt = $pdo->prepare("SELECT id FROM customers WHERE org_id=? AND outlet_id=? AND phone=? AND id<>?");
$stmt->execute([$authUser['org_id'], $outlet_id, $phone, $id]);
if($stmt->fetch()) sendError("Another customer with this phone already exists in this outlet");

// Update
$stmt = $pdo->prepare("UPDATE customers SET name=?, phone=?, outlet_id=? WHERE id=? AND org_id=?");
$stmt->execute([$name,$phone,$outlet_id,$id,$authUser['org_id']]);

sendSuccess(['customer_id'=>$id],"Customer updated successfully");
