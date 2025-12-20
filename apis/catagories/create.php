<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed. Use POST", 405);
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
$activeSub = $subscriptionModel->getActive($authUser['org_id']);
if (!$activeSub) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input || empty(trim($input['name'] ?? ''))) {
    sendError("Category name required", 422);
}

$name = trim($input['name']);

try {

    /* -------------------------------------------------
       CHECK EXISTING CATEGORY (ANY STATUS)
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM categories
        WHERE org_id=? AND name=?
        LIMIT 1
    ");
    $stmt->execute([$authUser['org_id'], $name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {

        // Case 1: Already active
        if ((int)$existing['status'] === 1) {
            sendError("Category already exists for this organization", 409);
        }

        // Case 2: Soft-deleted → restore
        $stmt = $pdo->prepare("
            UPDATE categories
            SET status = 1
            WHERE id = ?
        ");
        $stmt->execute([$existing['id']]);

        sendSuccess([
            "category_id" => $existing['id'],
            "name" => $name,
            "restored" => true
        ], "Category restored successfully");
    }

    /* -------------------------------------------------
       INSERT NEW CATEGORY
    ------------------------------------------------- */
    $stmt = $pdo->prepare("
        INSERT INTO categories (org_id, name, status)
        VALUES (?, ?, 1)
    ");
    $stmt->execute([$authUser['org_id'], $name]);

    sendSuccess([
        "category_id" => $pdo->lastInsertId(),
        "name" => $name,
        "restored" => false
    ], "Category created successfully");

} catch (Exception $e) {
    sendError("Failed to create category: ".$e->getMessage(), 500);
}
