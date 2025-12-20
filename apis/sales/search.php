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

/* -------------------------------------------------
   AUTH + SUBSCRIPTION
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT (QUERY PARAMS)
------------------------------------------------- */
$outlet_id   = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : 0;
$q           = isset($_GET['q']) ? trim($_GET['q']) : '';
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$start_date  = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
$end_date    = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;
$limit       = isset($_GET['limit']) ? min(100,(int)$_GET['limit']) : 20;
$offset      = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$sort_order  = (isset($_GET['sort_order']) && strtolower($_GET['sort_order']) === 'asc')
    ? 'ASC'
    : 'DESC';

if (!$outlet_id) sendError("Parameter 'outlet_id' is required", 422);

/* -------------------------------------------------
   VALIDATE OUTLET (ORG SAFE)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id FROM outlets
    WHERE id=? AND org_id=?
    LIMIT 1
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet_id", 403);

/* -------------------------------------------------
   BASE QUERY
------------------------------------------------- */
$sql = "
    SELECT
        id           AS sale_id,
        invoice_no,
        customer_id,
        taxable_amount,
        cgst,
        sgst,
        igst,
        round_off,
        total_amount,
        created_at
    FROM sales
    WHERE org_id=? AND outlet_id=?
";
$params = [$authUser['org_id'], $outlet_id];

/* -------------------------------------------------
   FILTERS
------------------------------------------------- */
if ($q !== '') {
    if (is_numeric($q)) {
        $sql .= " AND (id = ? OR invoice_no LIKE ?)";
        $params[] = $q;
        $params[] = "%$q%";
    } else {
        $sql .= " AND invoice_no LIKE ?";
        $params[] = "%$q%";
    }
}

if ($customer_id) {
    $sql .= " AND customer_id = ?";
    $params[] = $customer_id;
}

if ($start_date) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $end_date;
}

/* -------------------------------------------------
   ORDER + PAGINATION
------------------------------------------------- */
$sql .= " ORDER BY id $sort_order LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

/* -------------------------------------------------
   FETCH SALES
------------------------------------------------- */
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   TOTAL COUNT (FOR PAGINATION)
------------------------------------------------- */
$countSql = "SELECT COUNT(*) FROM sales WHERE org_id=? AND outlet_id=?";
$countParams = [$authUser['org_id'], $outlet_id];

if ($q !== '') {
    if (is_numeric($q)) {
        $countSql .= " AND (id = ? OR invoice_no LIKE ?)";
        $countParams[] = $q;
        $countParams[] = "%$q%";
    } else {
        $countSql .= " AND invoice_no LIKE ?";
        $countParams[] = "%$q%";
    }
}
if ($customer_id) {
    $countSql .= " AND customer_id = ?";
    $countParams[] = $customer_id;
}
if ($start_date) {
    $countSql .= " AND DATE(created_at) >= ?";
    $countParams[] = $start_date;
}
if ($end_date) {
    $countSql .= " AND DATE(created_at) <= ?";
    $countParams[] = $end_date;
}

$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$total = (int)$stmt->fetchColumn();

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess([
    "records" => array_map(function ($r) {
        return [
            "sale_id"         => (int)$r['sale_id'],
            "invoice_no"      => $r['invoice_no'],
            "customer_id"     => (int)$r['customer_id'],
            "taxable_amount"  => (float)$r['taxable_amount'],
            "cgst"            => (float)$r['cgst'],
            "sgst"            => (float)$r['sgst'],
            "igst"            => (float)$r['igst'],
            "round_off"       => (float)$r['round_off'],
            "total_amount"    => (float)$r['total_amount'],
            "created_at"      => $r['created_at']
        ];
    }, $records),
    "pagination" => [
        "total"  => $total,
        "limit"  => $limit,
        "offset" => $offset
    ]
], count($records)." record(s) found");
