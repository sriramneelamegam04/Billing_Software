<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../helpers/auth.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

$params = [];
$where = [];

// --- Build filters ---
if (!empty($_GET['id'])) {
    $where[] = "id = ?";
    $params[] = intval($_GET['id']);
}
if (!empty($_GET['name'])) {
    $where[] = "LOWER(name) LIKE ?";
    $params[] = "%".strtolower(trim($_GET['name']))."%";
}
if (!empty($_GET['email'])) {
    $where[] = "LOWER(email) LIKE ?";
    $params[] = "%".strtolower(trim($_GET['email']))."%";
}
if (!empty($_GET['phone'])) {
    $where[] = "phone LIKE ?";
    $params[] = "%".preg_replace('/\s+/', '', $_GET['phone'])."%";
}
if (!empty($_GET['vertical'])) {
    $where[] = "LOWER(vertical) = ?";
    $params[] = strtolower(trim($_GET['vertical']));
}

// --- Build SQL ---
$sql = "SELECT id, name, email, phone, vertical, gstin, gst_type, gst_rate, is_verified, created_at FROM orgs";
if ($where) {
    $sql .= " WHERE ".implode(" AND ", $where);
}
$sql .= " ORDER BY id DESC";

// --- Execute ---
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess($results, count($results)." record(s) found");
