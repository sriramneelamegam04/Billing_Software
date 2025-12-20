<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendError("Method Not Allowed", 405);

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// Subscription check
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

$input = json_decode(file_get_contents("php://input"), true);
if (empty($input['category_id']) || empty(trim($input['name']))) {
    sendError("category_id and name required", 422);
}

$category_id = (int)$input['category_id'];
$name = trim($input['name']);

/* -------------------------------------------------
   VALIDATE CATEGORY OWNERSHIP
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id FROM categories
    WHERE id=? AND org_id=? AND status=1
");
$stmt->execute([$category_id, $authUser['org_id']]);
if (!$stmt->fetch()) {
    sendError("Invalid category or access denied", 403);
}

/* -------------------------------------------------
   CHECK EXISTING SUB-CATEGORY (ANY STATUS)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, status
    FROM sub_categories
    WHERE category_id=? AND name=?
    LIMIT 1
");
$stmt->execute([$category_id, $name]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {

    // Case 1: Already active
    if ((int)$existing['status'] === 1) {
        sendError("Sub-category already exists", 409);
    }

    // Case 2: Soft-deleted → restore
    $stmt = $pdo->prepare("
        UPDATE sub_categories
        SET status=1
        WHERE id=?
    ");
    $stmt->execute([$existing['id']]);

    sendSuccess([
        "sub_category_id" => $existing['id'],
        "category_id" => $category_id,
        "name" => $name,
        "restored" => true
    ], "Sub-category restored successfully");
}

/* -------------------------------------------------
   INSERT NEW
------------------------------------------------- */
$stmt = $pdo->prepare("
    INSERT INTO sub_categories (category_id, name, status)
    VALUES (?, ?, 1)
");
$stmt->execute([$category_id, $name]);

sendSuccess([
    "sub_category_id" => $pdo->lastInsertId(),
    "category_id" => $category_id,
    "name" => $name,
    "restored" => false
], "Sub-category created successfully");
