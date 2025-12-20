<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
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
if (!$authUser) sendError("Unauthorized", 401);

// ✅ Get outlet ID from query parameter
if (empty($_GET['id'])) sendError("Outlet id is required");
$outlet_id = (int)$_GET['id'];

try {
    // ✅ Build query based on role
    if ($authUser['role'] === 'admin') {
        $query = "SELECT * FROM outlets WHERE id = :id AND org_id = :org_id LIMIT 1";
        $params = [
            ':id' => $outlet_id,
            ':org_id' => $authUser['org_id']
        ];
    } else {
        $query = "SELECT * FROM outlets WHERE id = :id AND org_id = :org_id AND id = :outlet_id LIMIT 1";
        $params = [
            ':id' => $outlet_id,
            ':org_id' => $authUser['org_id'],
            ':outlet_id' => $authUser['outlet_id']
        ];
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $outlet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$outlet) sendError("Outlet not found", 404);

    sendSuccess($outlet, "Outlet fetched successfully");

} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}
