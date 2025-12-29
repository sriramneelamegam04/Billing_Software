<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../models/Subscription.php';

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

$subscription = new Subscription($pdo);
if (!$subscription->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* ================= INPUT ================= */
$customer_id = (int)($_GET['customer_id'] ?? 0);
$outlet_id   = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;
$date_from   = $_GET['date_from'] ?? null;
$date_to     = $_GET['date_to'] ?? null;
$today       = isset($_GET['today']) && (int)$_GET['today'] === 1;

if (!$customer_id) sendError("customer_id is required", 422);

/* ================= VALIDATE CUSTOMER ================= */
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.phone, o.name AS outlet_name
    FROM customers c
    JOIN outlets o ON o.id = c.outlet_id
    WHERE c.id=? AND c.org_id=?
");
$stmt->execute([$customer_id, $authUser['org_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) sendError("Customer not found", 404);

/* ================= BASE WHERE ================= */
$where = "s.org_id=:org_id AND s.customer_id=:customer_id";
$params = [
    ':org_id'      => $authUser['org_id'],
    ':customer_id' => $customer_id
];

if ($outlet_id) {
    $where .= " AND s.outlet_id=:outlet_id";
    $params[':outlet_id'] = $outlet_id;
}

/* 🔥 TODAY FILTER (priority only if no date range) */
if ($today && !$date_from && !$date_to) {
    $where .= " AND DATE(s.created_at) = CURDATE()";
}

if ($date_from) {
    $where .= " AND DATE(s.created_at) >= :df";
    $params[':df'] = $date_from;
}
if ($date_to) {
    $where .= " AND DATE(s.created_at) <= :dt";
    $params[':dt'] = $date_to;
}

/* ================= TRANSACTIONS ================= */
$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.invoice_no,
        s.total_amount,
        s.status,
        s.note,
        s.created_at,
        CASE
            WHEN s.total_amount < 0 THEN 'RETURN'
            ELSE 'SALE'
        END AS type,
        p.amount AS paid_amount,
        p.payment_mode
    FROM sales s
    LEFT JOIN payments p ON p.sale_id = s.id
    WHERE $where
    ORDER BY s.created_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= AGGREGATION ================= */
$summary = [
    'sale_bills'   => 0,
    'return_bills' => 0,
    'sale_total'   => 0,
    'return_total' => 0,
    'collections'  => 0
];

foreach ($rows as $r) {
    if ($r['type'] === 'SALE') {
        $summary['sale_bills']++;
        $summary['sale_total'] += (float)$r['total_amount'];
    } else {
        $summary['return_bills']++;
        $summary['return_total'] += abs((float)$r['total_amount']);
    }
    $summary['collections'] += (float)($r['paid_amount'] ?? 0);
}

$net_amount  = $summary['sale_total'] - $summary['return_total'];
$outstanding = $net_amount - $summary['collections'];

/* ================= RESPONSE ================= */
sendSuccess([
    "filter" => [
        "today"      => $today ? true : false,
        "date_from"  => $date_from,
        "date_to"    => $date_to
    ],

    "customer" => [
        "id"     => (int)$customer['id'],
        "name"   => $customer['name'],
        "phone"  => $customer['phone'],
        "outlet" => $customer['outlet_name']
    ],

    "summary" => [
        "sale_bills"   => $summary['sale_bills'],
        "return_bills" => $summary['return_bills'],
        "sale_total"   => round($summary['sale_total'],2),
        "return_total" => round($summary['return_total'],2),
        "net_amount"   => round($net_amount,2),
        "collections"  => round($summary['collections'],2),
        "outstanding"  => round($outstanding,2)
    ],

    "transactions" => array_map(function ($r) {
        return [
            "sale_id"      => (int)$r['id'],
            "invoice_no"   => $r['invoice_no'],
            "type"         => $r['type'],   // SALE / RETURN
            "total_amount" => (float)$r['total_amount'],
            "paid_amount"  => (float)($r['paid_amount'] ?? 0),
            "payment_mode" => $r['payment_mode'],
            "note"         => $r['note'],
            "created_at"   => $r['created_at']
        ];
    }, $rows)

], "Customer history fetched successfully");
