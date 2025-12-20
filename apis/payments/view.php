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

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") { http_response_code(200); exit; }

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use GET"]);
    exit;
}

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);


/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

$input = json_decode(file_get_contents("php://input"), true);
if(!$input) sendError("Invalid JSON format");

if (empty($input['id'])) sendError("Payment id is required");

$payment_id = (int)$input['id'];

try {
    if ($authUser['role'] === 'admin') {
        $query = "
            SELECT p.*
            FROM payments p
            INNER JOIN outlets o ON p.outlet_id = o.id
            WHERE p.id = :id AND o.org_id = :org_id
            LIMIT 1";
        $params = [':id' => $payment_id, ':org_id' => $authUser['org_id']];
    } else {
        $query = "
            SELECT p.*
            FROM payments p
            INNER JOIN outlets o ON p.outlet_id = o.id
            WHERE p.id = :id AND o.org_id = :org_id AND o.id = :outlet_id
            LIMIT 1";
        $params = [
            ':id' => $payment_id,
            ':org_id' => $authUser['org_id'],
            ':outlet_id' => $authUser['outlet_id']
        ];
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$payment) sendError("Payment not found", 404);

    sendSuccess($payment, "Payment fetched successfully");
} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}
