<?php
// Unified JSON response helpers with headers + CORS

function _apply_json_headers() {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        // CORS (adjust origins as needed)
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }
}

// Success response
function sendSuccess($data = [], $message = "Success", $code = 200) {
    _apply_json_headers();
    http_response_code($code);
    echo json_encode([
        "success" => true,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

// Error response
function sendError($message = "Error", $code = 400, $errors = null) {
    _apply_json_headers();
    http_response_code($code);
    $resp = [
        "status" => "error",
        "message" => $message
    ];
    if ($errors !== null) {
        $resp["errors"] = $errors;
    }
    echo json_encode($resp);
    exit;
}

// Handle preflight quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    _apply_json_headers();
    http_response_code(204);
    exit;
}
