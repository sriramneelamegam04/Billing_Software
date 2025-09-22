<?php
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../bootstrap/db.php';

function verify_subscription() {
    $token = getBearerToken();
    $decoded = validateToken($token);

    if (!$decoded) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    $orgId = $decoded->org_id ?? null;
    if (!$orgId) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Organization not found in token"]);
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT valid_until FROM subscriptions WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $subscription = $stmt->fetch();

    if (!$subscription || strtotime($subscription['valid_until']) < time()) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Subscription expired"]);
        exit;
    }
}
