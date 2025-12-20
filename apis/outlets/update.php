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
if ($_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}

// ✅ Auth check
$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

// ✅ Only admin can update outlets
if ($authUser['role'] !== 'admin') {
    sendError("Only admin can update outlets", 403);
}

// Decode and validate JSON
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

// Fetch existing outlet
$stmt = $pdo->prepare("SELECT * FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) {
    sendError("Outlet not found", 404);
}

// Fetch linked user (staff for this outlet)
$userStmt = $pdo->prepare("SELECT * FROM users WHERE outlet_id=? AND org_id=? LIMIT 1");
$userStmt->execute([$outlet_id, $authUser['org_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// Prepare new values (keep old if not provided)
$name     = isset($input['name']) ? trim($input['name']) : $outlet['name'];
$address  = isset($input['address']) ? trim($input['address']) : $outlet['address'];
$email    = isset($input['email']) ? strtolower(trim($input['email'])) : ($user['email'] ?? null);
$password = isset($input['password']) ? trim($input['password']) : null;

// Validate name and address
if (strlen($name) < 3) {
    sendError("Outlet name must be at least 3 characters", 422);
}
if (strlen($address) < 5) {
    sendError("Outlet address must be at least 5 characters", 422);
}

// ✅ If email changed, check duplicate
if ($email && $email !== $user['email']) {
    $dupEmail = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
    $dupEmail->execute([$email, $user['id']]);
    if ($dupEmail->fetch()) {
        sendError("Email already exists for another user", 409);
    }
}

// ✅ Update outlet
$updateStmt = $pdo->prepare("UPDATE outlets SET name=?, address=? WHERE id=? AND org_id=?");
$updateStmt->execute([$name, $address, $outlet_id, $authUser['org_id']]);

// ✅ Update outlet user (if exists)
if ($user) {
    $updateUserQuery = "UPDATE users SET name=?, email=? WHERE id=?";
    $params = [$name . " Admin", $email, $user['id']];

    // If password provided → hash and update
    if (!empty($password)) {
        if (strlen($password) < 8) {
            sendError("Password must be at least 8 characters", 422);
        }
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $updateUserQuery = "UPDATE users SET name=?, email=?, password=? WHERE id=?";
        $params = [$name . " Admin", $email, $hashed, $user['id']];
    }

    $updStmt = $pdo->prepare($updateUserQuery);
    $updStmt->execute($params);
}

sendSuccess([
    'outlet_id' => $outlet_id,
    'name'      => $name,
    'address'   => $address,
    'user_email'=> $email
], "Outlet updated successfully");
