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
if (!$authUser) {
    sendError("Unauthorized", 401);
}

// Decode JSON safely
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError("Invalid JSON format: " . json_last_error_msg(), 400);
}

// Validate outlet_id
if (empty($input['outlet_id']) || !is_numeric($input['outlet_id'])) {
    sendError("Valid outlet_id is required", 422);
}

$outlet_id = (int)$input['outlet_id'];

// Check if outlet exists
$stmt = $pdo->prepare("SELECT name FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$outlet) {
    sendError("Outlet not found", 404);
}

// Delete outlet
$deleteStmt = $pdo->prepare("DELETE FROM outlets WHERE id=? AND org_id=?");
$deleteStmt->execute([$outlet_id, $authUser['org_id']]);

sendSuccess([
    'outlet_id' => $outlet_id,
    'deleted_name' => $outlet['name']
], "Outlet deleted successfully");
