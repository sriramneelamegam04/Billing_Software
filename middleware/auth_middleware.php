<?php
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../config/jwt.php';

/**
 * Require valid Bearer token. Returns payload array.
 */
function require_auth() : array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    if (!$auth || stripos($auth, 'Bearer ') !== 0) {
        sendError('Unauthorized: Token missing', 401);
    }
    $token = trim(substr($auth, 7));
    $payload = decode_jwt($token);
    if (!$payload) {
        sendError('Unauthorized: Invalid token', 401);
    }
    return $payload;
}
