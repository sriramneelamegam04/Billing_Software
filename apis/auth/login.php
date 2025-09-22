<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../config/jwt.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON body");

// Required fields validation
if (empty($input['email']) || empty($input['password'])) {
    sendError("Email and password are required", 422);
}

// Normalize and validate email
$email = strtolower(trim($input['email']));
$password = trim($input['password']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError("Invalid email format");
}

// Basic password check
if (strlen($password) < 1) {
    sendError("Password cannot be empty");
}

// Fetch user
$userModel = new User($pdo);
$user = $userModel->getByEmail($email);

if (!$user || !password_verify($password, $user['password'])) {
    sendError("Invalid credentials", 401);
}

// Ensure outlet_id is populated
if (empty($user['outlet_id'])) {
    // Try to get default outlet for the org
    $stmt = $pdo->prepare("SELECT id FROM outlets WHERE org_id=? LIMIT 1");
    $stmt->execute([$user['org_id']]);
    $outlet = $stmt->fetch(PDO::FETCH_ASSOC);
    $user['outlet_id'] = $outlet ? (int)$outlet['id'] : null;
}

// Build JWT payload
$jwtPayload = [
    'user_id'   => (int)$user['id'],
    'org_id'    => (int)$user['org_id'],
    'role'      => $user['role'],
    'outlet_id' => $user['outlet_id'] // now included
];

// Optional: include org vertical
$stmt = $pdo->prepare("SELECT vertical FROM orgs WHERE id=?");
$stmt->execute([$user['org_id']]);
$jwtPayload['vertical'] = $stmt->fetchColumn();

$token = create_jwt($jwtPayload);

unset($user['password']); // Never leak hash

sendSuccess([
    'token' => $token,
    'user'  => $user // now includes outlet_id
], "Login successful");
