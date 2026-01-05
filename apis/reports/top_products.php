<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Method Not Allowed. Use GET", 405);
}

/* AUTH */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* INPUT */
$org_id    = (int)($_REQUEST['org_id'] ?? 0);
$outlet_id = $_REQUEST['outlet_id'] ?? null;
$date_from = $_REQUEST['date_from'] ?? null;
$date_to   = $_REQUEST['date_to'] ?? null;
$today     = isset($_REQUEST['today']) && (int)$_REQUEST['today'] === 1;
$limit     = max(1, (int)($_REQUEST['limit'] ?? 5));

/* ROLE */
if ($authUser['role'] === 'manager') {
    $org_id = $authUser['org_id'];
    $outlet_id = $authUser['outlet_id'];
}

if ($org_id <= 0) sendError("org_id required", 422);

/* WHERE */
$where  = "s.org_id = :org_id";
$params = [':org_id' => $org_id];

if ($outlet_id) {
    $where .= " AND s.outlet_id = :outlet_id";
    $params[':outlet_id'] = $outlet_id;
}

if ($today && !$date_from && !$date_to) {
    $where .= " AND DATE(s.created_at) = CURDATE()";
}
if ($date_from) {
    $where .= " AND s.created_at >= :df";
    $params[':df'] = $date_from . " 00:00:00";
}
if ($date_to) {
    $where .= " AND s.created_at <= :dt";
    $params[':dt'] = $date_to . " 23:59:59";
}

/* QUERY */
$stmt = $pdo->prepare("
    SELECT
        si.product_id,
        p.name AS product_name,
        SUM(si.quantity) AS qty_sold,
        SUM(si.amount)   AS total_amount
    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    JOIN products p ON p.id = si.product_id
    WHERE $where
    GROUP BY si.product_id, p.name
    ORDER BY qty_sold DESC, total_amount DESC
    LIMIT $limit
");

$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess("Top products", [
    'rows' => array_map(fn($r) => [
        'product_id'   => (int)$r['product_id'],
        'product_name' => $r['product_name'],
        'qty_sold'     => (float)$r['qty_sold'],
        'total_amount' => (float)$r['total_amount'],
    ], $rows)
]);
