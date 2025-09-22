<?php
require_once __DIR__ . '/../vendor/autoload.php'; // firebase/php-jwt

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Consider moving to env in production
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', 'change_this_in_env_super_secret_key');
}

/**
 * Create JWT token
 * @param array $payload custom claims
 * @param int $exp expiration seconds (default 24h)
 * @return string
 */
function create_jwt(array $payload, int $exp = 86400) : string {
    $now = time();
    $base = [
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $exp,
        'iss' => 'billing-backend'
    ];
    $claims = array_merge($base, $payload);
    return JWT::encode($claims, JWT_SECRET, 'HS256');
}

/**
 * Decode/verify JWT; returns payload array or null
 */
function decode_jwt(string $token) : ?array {
    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        return (array)$decoded;
    } catch (Exception $e) {
        return null;
    }
}
