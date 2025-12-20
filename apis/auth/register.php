<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/validation.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../models/Subscription.php';
require_once __DIR__.'/../../services/SubscriptionService.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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


$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON body");

// Required fields
$required = ['name','email','password'];
$missing = [];
foreach ($required as $field) {
    if (!isset($input[$field]) || trim($input[$field]) === '') {
        $missing[] = "$field is required";
    }
}
if (!empty($missing)) {
    sendError("Validation failed", 422, $missing);
}

// Normalize
$name      = strtolower(trim($input['name']));
$email     = strtolower(trim($input['email']));
$password  = trim($input['password']);
$role      = 'admin';

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError("Invalid email format");
}

// Password rules
$errors = [];
if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters long";
if (!preg_match('/[A-Z]/', $password)) $errors[] = "Password must contain at least one uppercase letter";
if (!preg_match('/[a-z]/', $password)) $errors[] = "Password must contain at least one lowercase letter";
if (!preg_match('/[0-9]/', $password)) $errors[] = "Password must contain at least one number";
if (!preg_match('/[\W_]/', $password)) $errors[] = "Password must contain at least one special character";
if (!empty($errors)) {
    sendError("Password does not meet security requirements", 422, $errors);
}

// Get org_id (optional)
$org_id = isset($input['org_id']) ? (int)$input['org_id'] : null;

// If org_id not provided, fetch from orgs table using email
if (!$org_id) {
    $stmt = $pdo->prepare("SELECT id FROM orgs WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$org) {
        sendError("Organization not found for given email. Please create org first.");
    }
    $org_id = $org['id'];
}

try {
    $userModel = new User($pdo);

    // Duplicate user check
    if ($userModel->getByEmail($email)) {
        sendError("Email already exists", 409);
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // ------------------------------
    // Assign first outlet for admin
    // ------------------------------
    $stmt = $pdo->prepare("SELECT id FROM outlets WHERE org_id=? LIMIT 1");
    $stmt->execute([$org_id]);
    $outlet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($outlet) {
        $outlet_id = (int)$outlet['id'];
    } else {
        // Create a default outlet for the org
        $stmt = $pdo->prepare("INSERT INTO outlets (org_id, name, created_at) VALUES (?, 'Default Outlet', NOW())");
        $stmt->execute([$org_id]);
        $outlet_id = (int)$pdo->lastInsertId();
    }

    // Create user
    $user_id = $userModel->create([
        'name'      => $name,
        'email'     => $email,
        'password'  => $hashedPassword,
        'role'      => $role,
        'org_id'    => $org_id,
        'outlet_id' => $outlet_id
    ]);

    // Generate JWT
    $token = create_jwt([
        'user_id'   => $user_id,
        'org_id'    => $org_id,
        'role'      => $role,
        'outlet_id' => $outlet_id
    ]);

    // Send response
    sendSuccess([
        'user_id'   => $user_id,
        'org_id'    => $org_id,
        'outlet_id' => $outlet_id,
        'token'     => $token
    ], "Admin registered successfully", 201);

} catch (Exception $e) {
    sendError("Registration failed: " . $e->getMessage(), 500);
}
