<?php
require_once __DIR__.'/../../helpers/response.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}


// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}


$headers = getallheaders();
if(empty($headers['Authorization'])) sendError("Authorization header missing");

$token = str_replace('Bearer ','',$headers['Authorization']);

// For stateless JWT, logout is just client discarding token
// Optionally, you can store token in a blacklist table to invalidate it

sendSuccess([], "Logged out successfully");
