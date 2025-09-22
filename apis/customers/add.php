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

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);

$input = json_decode(file_get_contents("php://input"), true);
if(!$input) sendError("Invalid JSON format");

// Required fields
$required = ['name','phone','outlet_id'];
foreach($required as $f){
    if(empty($input[$f])) sendError("$f is required");
}

$name = strtolower(trim($input['name']));
$phone = strtolower(trim($input['phone']));
$outlet_id = (int)$input['outlet_id'];

// Validate outlet belongs to this org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$outlet) sendError("Invalid outlet_id or does not belong to your organization");

// Duplicate check: same phone in same org + outlet
$stmt = $pdo->prepare("SELECT id FROM customers WHERE org_id=? AND outlet_id=? AND phone=?");
$stmt->execute([$authUser['org_id'], $outlet_id, $phone]);
if($stmt->fetch()) sendError("Customer with this phone already exists in this outlet");

// Insert
$stmt = $pdo->prepare("INSERT INTO customers (org_id, outlet_id, name, phone) VALUES (?,?,?,?)");
$stmt->execute([$authUser['org_id'], $outlet_id, $name, $phone]);
$customer_id = $pdo->lastInsertId();

sendSuccess(['customer_id'=>$customer_id], "Customer added successfully");
