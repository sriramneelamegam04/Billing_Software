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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['id'])) {
    sendError("Field 'id' is required");
}

$id = intval($input['id']);

// Check org exists
$stmt = $pdo->prepare("SELECT id FROM orgs WHERE id=? LIMIT 1");
$stmt->execute([$id]);
if (!$stmt->fetch()) sendError("Organization not found", 404);

// Delete org
$stmt = $pdo->prepare("DELETE FROM orgs WHERE id=?");
$stmt->execute([$id]);

sendSuccess([], "Organization deleted successfully");
