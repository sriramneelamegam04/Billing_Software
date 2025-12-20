<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use GET"]);
    exit;
}

// ✅ Auth check
$authUser = getCurrentUser();
if (!$authUser) {
    sendError("Unauthorized", 401);
}

// Pagination params
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$offset = ($page - 1) * $limit;

// Base query
$query  = "SELECT id, name, address, vertical FROM outlets WHERE org_id = ?";
$params = [$authUser['org_id']];

// ✅ If staff, restrict to their outlet only
if ($authUser['role'] === 'staff') {
    $query .= " AND id = ?";
    $params[] = $authUser['outlet_id'];
} else {
    // Apply filters only for admin
    if (!empty($_GET['id'])) {
        $query .= " AND id = ?";
        $params[] = (int)$_GET['id'];
    }
    if (!empty($_GET['name'])) {
        $query .= " AND name LIKE ?";
        $params[] = "%" . trim($_GET['name']) . "%";
    }
    if (!empty($_GET['vertical'])) {
        $query .= " AND vertical = ?";
        $params[] = strtolower(trim($_GET['vertical']));
    }
}

// Add pagination (safe interpolation since already int)
$query .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$outlets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total
$countQuery  = "SELECT COUNT(*) FROM outlets WHERE org_id = ?";
$countParams = [$authUser['org_id']];

if ($authUser['role'] === 'staff') {
    $countQuery .= " AND id = ?";
    $countParams[] = $authUser['outlet_id'];
} else {
    if (!empty($_GET['id'])) {
        $countQuery .= " AND id = ?";
        $countParams[] = (int)$_GET['id'];
    }
    if (!empty($_GET['name'])) {
        $countQuery .= " AND name LIKE ?";
        $countParams[] = "%" . trim($_GET['name']) . "%";
    }
    if (!empty($_GET['vertical'])) {
        $countQuery .= " AND vertical = ?";
        $countParams[] = strtolower(trim($_GET['vertical']));
    }
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($countParams);
$total = (int)$countStmt->fetchColumn();

// Response
sendSuccess([
    'page'    => $page,
    'limit'   => $limit,
    'total'   => $total,
    'outlets' => $outlets
], "Outlets fetched successfully");
