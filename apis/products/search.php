<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// Get query parameters
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$outlet_id = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;

// outlet_id is required
if (!$outlet_id) sendError("Parameter 'outlet_id' is required", 422);

// Validate outlet_id belongs to this org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) sendError("Invalid outlet_id or does not belong to your organization", 403);

// Base query: products in this org + outlet
$sql = "SELECT id, name, category, outlet_id, price, meta 
        FROM products 
        WHERE org_id=? AND outlet_id=?";
$params = [$authUser['org_id'], $outlet_id];

// Apply search query if provided
if (!empty($q)) {
    if (is_numeric($q)) {
        $sql .= " AND (id = ? OR name LIKE ? OR category LIKE ?) ";
        $params[] = $q;
        $params[] = "%$q%";
        $params[] = "%$q%";
    } else {
        $sql .= " AND (name LIKE ? OR category LIKE ?) ";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess($products, "Search results");
