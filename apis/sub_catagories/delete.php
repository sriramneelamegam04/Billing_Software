<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') sendError("Method Not Allowed", 405);

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// Subscription
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

$input = json_decode(file_get_contents("php://input"), true);
if (empty($input['id'])) sendError("Sub-category id required", 422);

$sub_id = (int)$input['id'];

// Fetch before delete
$stmt = $pdo->prepare("
    SELECT sc.id, sc.name, sc.category_id
    FROM sub_categories sc
    JOIN categories c ON sc.category_id = c.id
    WHERE sc.id=? AND c.org_id=? AND sc.status=1
");
$stmt->execute([$sub_id, $authUser['org_id']]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sub) sendError("Sub-category not found or access denied", 404);

// Soft delete
$stmt = $pdo->prepare("UPDATE sub_categories SET status=0 WHERE id=?");
$stmt->execute([$sub_id]);

sendSuccess([
    "deleted_sub_category" => $sub,
    "deleted_at" => date("Y-m-d H:i:s")
], "Sub-category deleted successfully");
