<?php
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../bootstrap/db.php';

/**
 * Get current authenticated user from JWT
 */
function getCurrentUser() {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) return null;

    $authHeader = $headers['Authorization'];
    if (strpos($authHeader, 'Bearer ') !== 0) return null;

    $token = trim(str_replace('Bearer ', '', $authHeader));
    $decoded = decode_jwt($token);
    if (!$decoded || !isset($decoded['user_id'])) return null;

    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$decoded['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}
