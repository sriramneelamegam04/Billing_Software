<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use GET"]);
    exit;
}

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);

$input = json_decode(file_get_contents("php://input"), true);
if(!$input) sendError("Invalid JSON format");
if(empty($input['id'])) sendError("Subscription id is required");

$subscription_id = (int)$input['id'];

try {
    $query = "
        SELECT *
        FROM subscriptions
        WHERE id = :id
          AND org_id = :org_id
        LIMIT 1
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':id' => $subscription_id,
        ':org_id' => $authUser['org_id']
    ]);

    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$subscription){
        sendError("Subscription not found", 404);
    }

    sendSuccess($subscription, "Subscription fetched successfully");

} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}
