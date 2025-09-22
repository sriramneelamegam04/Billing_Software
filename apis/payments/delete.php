<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Payment.php';

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
if(!$input || empty($input['payment_id'])) sendError("payment_id is required");

$payment_id = (int)$input['payment_id'];

// Fetch payment to ensure it exists and belongs to this org
$stmt = $pdo->prepare("SELECT id FROM payments WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$payment_id, $authUser['org_id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$payment) sendError("Payment not found or does not belong to your organization", 404);

try {
    // Delete payment (you may also want to handle related records if needed)
    $stmt = $pdo->prepare("DELETE FROM payments WHERE id=? AND org_id=?");
    $stmt->execute([$payment_id, $authUser['org_id']]);

    sendSuccess([], "Payment deleted successfully");

} catch(Exception $e) {
    sendError("Failed to delete payment: " . $e->getMessage());
}
