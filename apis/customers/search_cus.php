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

// Get search query and outlet_id
$q = $_GET['q'] ?? '';
$q = strtolower(trim($q));

$outlet_id = $_GET['outlet_id'] ?? null;
if(!$outlet_id) sendError("outlet_id is required");
$outlet_id = (int)$outlet_id;

if($q){
    $stmt = $pdo->prepare("
        SELECT id, name, phone, created_at
        FROM customers
        WHERE org_id=? AND outlet_id=? AND (name LIKE ? OR phone LIKE ?)
        ORDER BY id DESC
    ");
    $like = "%$q%";
    $stmt->execute([$authUser['org_id'], $outlet_id, $like, $like]);
} else {
    $stmt = $pdo->prepare("
        SELECT id, name, phone, created_at
        FROM customers
        WHERE org_id=? AND outlet_id=?
        ORDER BY id DESC
    ");
    $stmt->execute([$authUser['org_id'], $outlet_id]);
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
sendSuccess($rows, "Customer list fetched");
