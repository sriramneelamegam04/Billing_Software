<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
if (empty($input['id'])) {
    sendError("Category id required", 422);
}

$category_id = (int)$input['id'];

try {
    $pdo->beginTransaction();

    /* -------------------------------------------------
       FETCH CATEGORY BEFORE DELETE
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        SELECT id, name, org_id, created_at
        FROM categories
        WHERE id = ? AND org_id = ? AND status = 1
    ");
    $stmt->execute([$category_id, $authUser['org_id']]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$category) {
        $pdo->rollBack();
        sendError("Category not found or access denied", 404);
    }

    /* -------------------------------------------------
       SOFT DELETE CATEGORY
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        UPDATE categories
        SET status = 0
        WHERE id = ? AND org_id = ?
    ");
    $stmt->execute([$category_id, $authUser['org_id']]);

    /* -------------------------------------------------
       SOFT DELETE SUB-CATEGORIES
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        UPDATE sub_categories
        SET status = 0
        WHERE category_id = ?
    ");
    $stmt->execute([$category_id]);

    $pdo->commit();

    sendSuccess([
        "deleted_category" => $category,
        "deleted_at" => date("Y-m-d H:i:s")
    ], "Category and its sub-categories deleted successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to delete category: ".$e->getMessage(), 500);
}
