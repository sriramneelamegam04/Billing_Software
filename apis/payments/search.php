<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Method Not Allowed. Use GET", 405);
}

/* ================= AUTH ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* ================= SUBSCRIPTION ================= */
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* ================= INPUT ================= */
$q         = trim($_GET['q'] ?? '');
$outlet_id = (int)($_GET['outlet_id'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = min(100, max(1, (int)($_GET['limit'] ?? 10)));
$offset    = ($page - 1) * $limit;

if (!$outlet_id) sendError("outlet_id is required", 422);

/* ================= VALIDATE OUTLET ================= */
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet_id", 403);

/* ================= BASE QUERY ================= */
$sql = "
SELECT
    p.id,
    p.sale_id,
    p.org_id,
    p.outlet_id,
    p.amount,
    p.payment_mode,
    p.meta,
    p.created_at,
    p.is_active,

    s.customer_id,
    c.name  AS customer_name,
    c.phone AS customer_phone

FROM payments p
JOIN sales s ON s.id = p.sale_id
LEFT JOIN customers c ON c.id = s.customer_id
WHERE p.org_id = ? AND p.outlet_id = ?
";

$params = [$authUser['org_id'], $outlet_id];

/* ================= SEARCH ================= */
if ($q !== '') {
    if (is_numeric($q)) {
        $sql .= " AND (p.id = ? OR p.sale_id = ? OR c.phone LIKE ?)";
        $params[] = (int)$q;
        $params[] = (int)$q;
        $params[] = "%$q%";
    } else {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
}

/* ================= COUNT ================= */
$countSql = "SELECT COUNT(*) FROM ($sql) t";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

/* ================= PAGINATION ================= */
$sql .= " ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= META NORMALIZER ================= */
function normalizeMeta($meta)
{
    if ($meta === null) return null;

    // First decode
    $decoded = json_decode($meta, true);

    // If still string → decode again (double encoded)
    if (is_string($decoded)) {
        $decoded = json_decode($decoded, true);
    }

    return is_array($decoded) ? $decoded : null;
}

/* ================= FORMAT RESPONSE ================= */
$data = array_map(function ($r) {

    $meta = normalizeMeta($r['meta']);

    return [
        'id'           => (int)$r['id'],
        'sale_id'      => (int)$r['sale_id'],
        'org_id'       => (int)$r['org_id'],
        'outlet_id'    => (int)$r['outlet_id'],
        'amount'       => (float)$r['amount'],
        'payment_mode' => $r['payment_mode'],
        'is_active'    => (int)$r['is_active'],
        'created_at'   => $r['created_at'],

        'customer' => [
            'id'    => (int)$r['customer_id'],
            'name'  => $r['customer_name'] ?? 'Walk-in Customer',
            'phone' => $r['customer_phone'] ?? '-'
        ],

        /* 🔥 CLEAN META OBJECT */
        'meta' => $meta
    ];

}, $rows);

/* ================= RESPONSE ================= */
sendSuccess([
    'page'        => $page,
    'limit'       => $limit,
    'total_rows'  => $totalRows,
    'total_pages' => ceil($totalRows / $limit),
    'data'        => $data
], "Payments fetched successfully");
