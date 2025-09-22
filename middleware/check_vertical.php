<?php
require_once __DIR__ . '/../helpers/auth.php';

function check_vertical($requiredVertical) {
    $token = getBearerToken();
    $decoded = validateToken($token);

    if (!$decoded || $decoded->vertical !== $requiredVertical) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Access denied. Wrong vertical."]);
        exit;
    }
}
