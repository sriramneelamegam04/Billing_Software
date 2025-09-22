<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Outlet.php';
require_once __DIR__.'/../../models/User.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ✅ Auth check
$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

// ✅ Only admin can create outlets
if ($authUser['role'] !== 'admin') {
    sendError("Only admin can create outlets", 403);
}

// Decode and validate JSON
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError("Invalid JSON format: " . json_last_error_msg(), 400);
}

// Required fields (❌ vertical removed)
$required = ['name', 'address', 'email', 'password'];
foreach ($required as $field) {
    if (!isset($input[$field]) || trim($input[$field]) === '') {
        sendError("$field is required", 422);
    }
}

// Normalize
$name     = trim($input['name']);
$address  = trim($input['address']);
$email    = strtolower(trim($input['email']));
$password = trim($input['password']);

// Validate name length
if (strlen($name) < 3) {
    sendError("Outlet name must be at least 3 characters", 422);
}

// Validate address length
if (strlen($address) < 5) {
    sendError("Outlet address must be at least 5 characters", 422);
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError("Invalid email format");
}

// Validate password strength (basic)
if (strlen($password) < 8) {
    sendError("Password must be at least 8 characters", 422);
}

// ✅ Vertical comes from org table (NOT from user input)
$stmt = $pdo->prepare("SELECT vertical FROM orgs WHERE id=?");
$stmt->execute([$authUser['org_id']]);
$orgVertical = $stmt->fetchColumn();
if (!$orgVertical) {
    sendError("Organization vertical not found", 400);
}
$vertical = strtolower($orgVertical);

// ✅ Check active subscription
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);
if (!$activeSub) {
    sendError("Active subscription required to create outlet", 403);
}

// ✅ Enforce max_outlets limit
$currentCountStmt = $pdo->prepare("SELECT COUNT(*) FROM outlets WHERE org_id = ?");
$currentCountStmt->execute([$authUser['org_id']]);
$currentCount = (int)$currentCountStmt->fetchColumn();

$max_outlets = $activeSub['max_outlets']; // NULL/0 => unlimited
if (!empty($max_outlets) && $currentCount >= (int)$max_outlets) {
    sendError("Outlet limit reached for current subscription plan", 403);
}

// ✅ Duplicate outlet name check
$dupStmt = $pdo->prepare("SELECT id FROM outlets WHERE org_id=? AND name=? LIMIT 1");
$dupStmt->execute([$authUser['org_id'], $name]);
if ($dupStmt->fetch()) {
    sendError("Outlet with this name already exists in your organization", 409);
}

// ✅ Duplicate email check (for outlet user)
$dupEmail = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$dupEmail->execute([$email]);
if ($dupEmail->fetch()) {
    sendError("Email already exists for another user", 409);
}

// ✅ Create outlet
$outletModel = new Outlet($pdo);
$outlet_id = $outletModel->create([
    'name'     => $name,
    'address'  => $address,
    'vertical' => $vertical,
    'org_id'   => $authUser['org_id']
]);

// ✅ Create outlet login user (role=staff)
$userModel = new User($pdo);
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);
$user_id = $userModel->create([
    'name'      => $name . " Admin",
    'email'     => $email,
    'password'  => $hashedPassword,
    'role'      => 'staff',
    'org_id'    => $authUser['org_id'],
    'outlet_id' => $outlet_id
]);

sendSuccess(
    [
        'outlet_id'   => $outlet_id,
        'outlet_name' => $name,
        'user_id'     => $user_id,
        'user_email'  => $email,
        'vertical'    => $vertical
    ],
    "Outlet and login user created successfully"
);
