<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__ . '/../../models/Subscription.php';

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

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);


/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

// 🔹 Query params
$q         = isset($_GET['q'])        ? trim($_GET['q'])        : '';
$outlet_id = isset($_GET['outlet_id'])? (int)$_GET['outlet_id'] : null;
$page      = isset($_GET['page'])     ? max((int)$_GET['page'],1) : 1;
$limit     = isset($_GET['limit'])    ? max((int)$_GET['limit'],1): 10;
$offset    = ($page - 1) * $limit;

if (!$outlet_id) sendError("Parameter 'outlet_id' is required",422);

// 🔹 Validate outlet belongs to this org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if(!$stmt->fetch(PDO::FETCH_ASSOC)){
    sendError("Invalid outlet_id or does not belong to your organization",403);
}

// 🔹 Base query with customer join
$sql = "
    SELECT p.*, s.customer_id, c.name AS customer_name, c.phone AS customer_phone
    FROM payments p
    JOIN sales s      ON p.sale_id = s.id
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE p.org_id = ? AND p.outlet_id = ?
";
$params = [$authUser['org_id'], $outlet_id];

// 🔹 Search filter
if (!empty($q)) {
    if (is_numeric($q)) {
        $sql .= " AND (p.id = ? OR p.sale_id = ? OR c.phone LIKE ?)";
        $params[] = $q;
        $params[] = $q;
        $params[] = "%$q%";
    } else {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
}

// 🔹 Count total for pagination
$countSql = "SELECT COUNT(*) FROM ($sql) AS total_tbl";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

// 🔹 Apply pagination
$sql .= " ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess([
    'page'       => $page,
    'limit'      => $limit,
    'total_rows' => $totalRows,
    'total_pages'=> ceil($totalRows / $limit),
    'data'       => $payments
], "Payments fetched successfully");
