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
    sendError("Method Not Allowed", 405);
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
   INPUT
------------------------------------------------- */
$outlet_id   = (int)($_GET['outlet_id'] ?? 0);
$q           = trim($_GET['q'] ?? '');
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;

$start_date  = $_GET['start_date'] ?? null;
$end_date    = $_GET['end_date'] ?? null;
$today       = isset($_GET['today']) && (int)$_GET['today'] === 1;

$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$sort_order = (isset($_GET['sort_order']) && strtolower($_GET['sort_order']) === 'asc')
    ? 'ASC'
    : 'DESC';

if (!$outlet_id) sendError("outlet_id is required", 422);

/* -------------------------------------------------
   VALIDATE OUTLET
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id FROM outlets
    WHERE id=? AND org_id=?
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet_id", 403);

/* -------------------------------------------------
   BASE QUERY
------------------------------------------------- */
$sql = "
SELECT
    s.id              AS sale_id,
    s.invoice_no,
    s.customer_id,
    c.name            AS customer_name,
    c.phone           AS customer_phone,

    s.taxable_amount,
    s.cgst,
    s.sgst,
    s.igst,
    s.round_off,
    s.total_amount,
    s.status          AS sale_status,
    s.created_at,

    p.payment_mode

FROM sales s
LEFT JOIN customers c ON c.id = s.customer_id
LEFT JOIN payments p ON p.sale_id = s.id

WHERE s.org_id = ? AND s.outlet_id = ?
";

$params = [$authUser['org_id'], $outlet_id];

/* -------------------------------------------------
   FILTERS
------------------------------------------------- */
if ($q !== '') {
    if (is_numeric($q)) {
        $sql .= " AND (s.id = ? OR s.invoice_no LIKE ?)";
        $params[] = (int)$q;
        $params[] = "%$q%";
    } else {
        $sql .= " AND s.invoice_no LIKE ?";
        $params[] = "%$q%";
    }
}

if ($customer_id) {
    $sql .= " AND s.customer_id = ?";
    $params[] = $customer_id;
}

/* 🔥 TODAY FILTER (only if start/end not provided) */
if ($today && !$start_date && !$end_date) {
    $sql .= " AND DATE(s.created_at) = CURDATE()";
}

if ($start_date) {
    $sql .= " AND DATE(s.created_at) >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $sql .= " AND DATE(s.created_at) <= ?";
    $params[] = $end_date;
}

/* -------------------------------------------------
   ORDER + PAGINATION
------------------------------------------------- */
$sql .= " ORDER BY s.id $sort_order LIMIT $limit OFFSET $offset";

/* -------------------------------------------------
   FETCH SALES
------------------------------------------------- */
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   TOTAL COUNT
------------------------------------------------- */
$countSql = "
SELECT COUNT(*)
FROM sales s
WHERE s.org_id=? AND s.outlet_id=?
";
$countParams = [$authUser['org_id'], $outlet_id];

if ($q !== '') {
    if (is_numeric($q)) {
        $countSql .= " AND (s.id = ? OR s.invoice_no LIKE ?)";
        $countParams[] = (int)$q;
        $countParams[] = "%$q%";
    } else {
        $countSql .= " AND s.invoice_no LIKE ?";
        $countParams[] = "%$q%";
    }
}

if ($customer_id) {
    $countSql .= " AND s.customer_id = ?";
    $countParams[] = $customer_id;
}

if ($today && !$start_date && !$end_date) {
    $countSql .= " AND DATE(s.created_at) = CURDATE()";
}

if ($start_date) {
    $countSql .= " AND DATE(s.created_at) >= ?";
    $countParams[] = $start_date;
}

if ($end_date) {
    $countSql .= " AND DATE(s.created_at) <= ?";
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

        /* 🔥 SALE vs RETURN IDENTIFICATION */
        $saleType = "SALE";
        $paymentStatus = "UNPAID";

        if ((float)$r['total_amount'] < 0 || (int)$r['sale_status'] === 2) {
            $saleType = "RETURN";
            $paymentStatus = "RETURN";
        } else {
            $paymentStatus = ((int)$r['sale_status'] === 1) ? "PAID" : "UNPAID";
        }

        return [
            "sale_id"    => (int)$r['sale_id'],
            "invoice_no" => $r['invoice_no'],

            "sale_type"  => $saleType,   // 🔥 NEW FIELD

            "customer" => [
                "id"    => (int)$r['customer_id'],
                "name"  => $r['customer_name'] ?: "Walk-in Customer",
                "phone" => $r['customer_phone'] ?: "-"
            ],

            "taxable_amount" => (float)$r['taxable_amount'],
            "cgst"           => (float)$r['cgst'],
            "sgst"           => (float)$r['sgst'],
            "igst"           => (float)$r['igst'],
            "round_off"      => (float)$r['round_off'],
            "total_amount"   => (float)$r['total_amount'],

            "payment_status" => $paymentStatus,
            "payment_mode"   => $r['payment_mode'],

            "created_at"     => $r['created_at']
        ];
    }, $rows),

    "pagination" => [
        "total"  => $total,
        "limit"  => $limit,
        "offset" => $offset
    ]
], count($rows) . " record(s) found");
