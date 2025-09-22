<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Sale.php';

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

// Decode JSON input
$input = json_decode(file_get_contents('php://input'), true);
if(!$input || empty($input['sale_id'])) sendError("sale_id is required");

$sale_id = (int)$input['sale_id'];

// Fetch sale to ensure it exists and belongs to this org
$stmt = $pdo->prepare("SELECT id FROM sales WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$sale_id, $authUser['org_id']]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$sale) sendError("Sale not found or does not belong to your organization", 404);

try {
    // Delete sale (you may also want to delete related sale items if needed)
    $stmt = $pdo->prepare("DELETE FROM sales WHERE id=? AND org_id=?");
    $stmt->execute([$sale_id, $authUser['org_id']]);

    sendSuccess([], "Sale deleted successfully");

} catch(Exception $e) {
    sendError("Failed to delete sale: " . $e->getMessage());
}
