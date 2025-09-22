<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

// Get token from query
$token = isset($_GET['token']) ? trim($_GET['token']) : null;
if (!$token || strlen($token) < 10) {
    sendError("Invalid or missing verification token", 400);
}

// Check if org exists with this token
$stmt = $pdo->prepare("SELECT id, name, email, is_verified FROM orgs WHERE verification_token=? LIMIT 1");
$stmt->execute([$token]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    sendError("Invalid or expired token", 400);
}

// If already verified
if ($org['is_verified']) {
    sendError("Email already verified", 409);
}

// Update verification status
$stmt = $pdo->prepare("UPDATE orgs SET is_verified=1, verification_token=NULL WHERE id=?");
$stmt->execute([$org['id']]);

// Response
sendSuccess([
    'org_id'   => $org['id'],
    'email'    => $org['email'],
    'name'     => $org['name'],
    'verified' => true
], "Email verified successfully. You can now log in and create a subscription.");
