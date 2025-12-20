<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../helpers/auth.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { 
    http_response_code(200); 
    exit; 
}

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use GET"]);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

// Get org_id (from query string OR JSON body)
$org_id = null;
if ($_SERVER['REQUEST_METHOD'] === "GET" && isset($_GET['id'])) {
    $org_id = (int)$_GET['id'];
} else {
    $input = json_decode(file_get_contents("php://input"), true);
    if ($input && !empty($input['id'])) {
        $org_id = (int)$input['id'];
    }
}

if (!$org_id) sendError("Org id is required", 422);

try {
    $stmt = $pdo->prepare("SELECT * FROM orgs WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $org_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$org) sendError("Org not found", 404);

    sendSuccess($org, "Org fetched successfully");
} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}
