<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

try {
    $subService = new SubscriptionService($pdo);
    $sub = $subService->getActiveSubscription($authUser['org_id']);

    if (!$sub) {
        sendSuccess([
            'active' => false,
            'message' => 'No active subscription'
        ], "No active subscription");
    }

    // Calculate days remaining if expires_at exists
    $daysRemaining = null;
    if (!empty($sub['expires_at'])) {
        $now = new DateTime();
        $expiry = new DateTime($sub['expires_at']);
        if ($expiry > $now) {
            $daysRemaining = $now->diff($expiry)->days;
        } else {
            $daysRemaining = 0; // expired
        }
    }

    sendSuccess([
        'active' => true,
        'plan' => $sub['plan'],
        'status' => $sub['status'],
        'starts_at' => $sub['starts_at'],
        'expires_at' => $sub['expires_at'],
        'max_outlets' => $sub['max_outlets'],
        'features' => json_decode($sub['features'], true),
        'days_remaining' => $daysRemaining
    ], "Active subscription found");

} catch (Exception $e) {
    sendError("Error: " . $e->getMessage());
}
