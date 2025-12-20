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

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

if ($authUser['role'] !== 'admin') {
    sendError("Only admin can create outlets", 403);
}

/* -------------------------------------------------
   INPUT VALIDATION
------------------------------------------------- */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendError("Invalid JSON payload", 400);
}

$required = ['name', 'address', 'email', 'password'];
foreach ($required as $field) {
    if (empty(trim($input[$field] ?? ''))) {
        sendError("$field is required", 422);
    }
}

$name     = trim($input['name']);
$address  = trim($input['address']);
$email    = strtolower(trim($input['email']));
$password = trim($input['password']);

if (strlen($name) < 3) {
    sendError("Outlet name must be at least 3 characters", 422);
}

if (strlen($address) < 5) {
    sendError("Outlet address must be at least 5 characters", 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError("Invalid email format", 422);
}

if (strlen($password) < 8) {
    sendError("Password must be at least 8 characters", 422);
}

/* -------------------------------------------------
   ORG VERTICAL (FROM ORGS TABLE)
------------------------------------------------- */
$stmt = $pdo->prepare("SELECT vertical FROM orgs WHERE id=?");
$stmt->execute([$authUser['org_id']]);
$vertical = strtolower($stmt->fetchColumn());

if (!$vertical) {
    sendError("Organization vertical not found", 400);
}

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

$max_outlets = $activeSub['max_outlets']; // NULL = unlimited, 0 = not allowed

/* -------------------------------------------------
   ENFORCE OUTLET LIMIT (FIXED LOGIC)
------------------------------------------------- */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM outlets WHERE org_id=?");
$countStmt->execute([$authUser['org_id']]);
$currentCount = (int)$countStmt->fetchColumn();

/*
RULES:
- max_outlets = NULL → unlimited
- max_outlets = 0    → NO outlet allowed
- max_outlets > 0    → enforce limit
*/
if ($max_outlets !== null) {

    if ((int)$max_outlets === 0) {
        sendError(
            "Your current subscription does not allow creating outlets",
            403
        );
    }

    if ($currentCount >= (int)$max_outlets) {
        sendError(
            "Outlet limit reached for your subscription plan",
            403
        );
    }
}

/* -------------------------------------------------
   DUPLICATE CHECKS
------------------------------------------------- */
$dupOutlet = $pdo->prepare(
    "SELECT id FROM outlets WHERE org_id=? AND name=? LIMIT 1"
);
$dupOutlet->execute([$authUser['org_id'], $name]);
if ($dupOutlet->fetch()) {
    sendError("Outlet with this name already exists", 409);
}

$dupEmail = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$dupEmail->execute([$email]);
if ($dupEmail->fetch()) {
    sendError("Email already exists", 409);
}

/* -------------------------------------------------
   CREATE OUTLET
------------------------------------------------- */
$outletModel = new Outlet($pdo);
$outlet_id = $outletModel->create([
    'name'     => $name,
    'address'  => $address,
    'vertical' => $vertical,
    'org_id'   => $authUser['org_id']
]);

/* -------------------------------------------------
   CREATE STAFF USER
------------------------------------------------- */
$userModel = new User($pdo);
$user_id = $userModel->create([
    'name'      => $name . " Admin",
    'email'     => $email,
    'password'  => password_hash($password, PASSWORD_BCRYPT),
    'role'      => 'staff',
    'org_id'    => $authUser['org_id'],
    'outlet_id' => $outlet_id
]);

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess(
    [
        'outlet_id'   => $outlet_id,
        'outlet_name' => $name,
        'user_id'     => $user_id,
        'email'       => $email,
        'vertical'    => $vertical
    ],
    "Outlet created successfully"
);
