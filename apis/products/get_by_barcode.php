<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// Decode JSON input
$input = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError("Invalid JSON format: " . json_last_error_msg());
}

$barcode = isset($input['barcode']) ? trim($input['barcode']) : '';
$outlet_id = isset($input['outlet_id']) ? (int)$input['outlet_id'] : 0;

if ($barcode === '' || $outlet_id <= 0) {
    sendError("barcode and outlet_id are required", 422);
}

// Validate outlet belongs to org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) {
    sendError("Invalid outlet_id or does not belong to your organization", 403);
}

// Search product by barcode
$sql = "SELECT * FROM products 
        WHERE org_id=? AND outlet_id=? 
        AND JSON_UNQUOTE(JSON_EXTRACT(meta,'$.barcode')) = ? 
        LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([$authUser['org_id'], $outlet_id, $barcode]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    sendError("Product not found for barcode $barcode", 404);
}

sendSuccess($product, "Product fetched successfully by barcode");
