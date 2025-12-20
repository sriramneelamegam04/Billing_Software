<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    sendError("Method Not Allowed", 405);
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (empty($input['id']) || empty(trim($input['name']))) {
    sendError("id and name required", 422);
}

$category_id = (int)$input['id'];
$name = trim($input['name']);

/* -------------------------------------------------
   UPDATE
------------------------------------------------- */
$stmt = $pdo->prepare("
    UPDATE categories
    SET name = ?
    WHERE id = ? AND org_id = ? AND status = 1
");
$stmt->execute([
    $name,
    $category_id,
    $authUser['org_id']
]);

if ($stmt->rowCount() === 0) {
    sendError("Category not found or access denied", 404);
}

/* -------------------------------------------------
   FETCH UPDATED ROW
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, name, org_id, created_at
    FROM categories
    WHERE id = ? AND org_id = ?
");
$stmt->execute([$category_id, $authUser['org_id']]);

$category = $stmt->fetch(PDO::FETCH_ASSOC);

sendSuccess($category, "Category updated successfully");
