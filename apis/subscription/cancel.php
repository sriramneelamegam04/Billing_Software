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

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$input = json_decode(file_get_contents('php://input'), true);
if(!$input || empty($input['subscription_id'])) sendError("subscription_id is required");

$stmt = $pdo->prepare("UPDATE subscriptions SET end_date=CURDATE() WHERE id=? AND org_id=?");
$stmt->execute([$input['subscription_id'],$authUser['org_id']]);

sendSuccess([],"Subscription cancelled successfully");
