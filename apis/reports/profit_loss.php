<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';

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

/* ================= INPUT ================= */
$org_id    = (int)($_REQUEST['org_id'] ?? 0);
$outlet_id = $_REQUEST['outlet_id'] ?? null;
$date_from = $_REQUEST['date_from'] ?? null;
$date_to   = $_REQUEST['date_to'] ?? null;
$today     = isset($_GET['today']) && (int)$_GET['today'] === 1;

/* PAGINATION */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

/* ================= ROLE ================= */
if ($authUser['role'] === 'manager') {
    $org_id = $authUser['org_id'];
    if (!empty($outlet_id) && $outlet_id != $authUser['outlet_id']) {
        sendError("Forbidden", 403);
    }
    $outlet_id = $authUser['outlet_id'];
}

if ($org_id <= 0) sendError("org_id required", 422);

/* ================= WHERE ================= */
$where = "s.org_id = :org_id";
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

/* ================= SALES + COST + DISCOUNT ================= */
$stmt = $pdo->prepare("
    SELECT
        s.outlet_id,
        o.name AS outlet_name,

        COALESCE(SUM(
            CASE WHEN s.status = 1 THEN si.amount ELSE 0 END
        ),0) AS sales_amount,

        COALESCE(SUM(pd.manual_discount),0) AS discount,

        COALESCE(SUM(
            CASE WHEN s.status = 1 THEN
                si.quantity *
                COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(v.meta,'$.purchase_price')) AS DECIMAL(10,2)),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.purchase_price')) AS DECIMAL(10,2)),
                    0
                )
            ELSE 0 END
        ),0) AS cost_amount

    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    JOIN outlets o ON o.id = s.outlet_id
    JOIN products p ON p.id = si.product_id
    LEFT JOIN product_variants v ON v.id = si.variant_id
    LEFT JOIN (
        SELECT
            sale_id,
            CAST(
                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        JSON_UNQUOTE(meta),
                        '$.manual_discount'
                    )
                ) AS DECIMAL(10,2)
            ) AS manual_discount
        FROM payments
        WHERE is_active = 1
    ) pd ON pd.sale_id = s.id

    WHERE $where
    GROUP BY s.outlet_id, o.name
    ORDER BY o.name
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$rowsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= LOYALTY (SOURCE OF TRUTH) ================= */
$stmt = $pdo->prepare("
    SELECT
        s.outlet_id,
        COALESCE(SUM(lp.points_redeemed),0) AS loyalty_redeemed
    FROM loyalty_points lp
    JOIN sales s ON s.id = lp.sale_id
    WHERE $where
    AND s.status = 1
    GROUP BY s.outlet_id
");
$stmt->execute($params);

$loyaltyMap = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $loyaltyMap[$r['outlet_id']] = (float)$r['loyalty_redeemed'];
}

/* ================= COLLECTIONS + REFUNDS ================= */
$stmt = $pdo->prepare("
    SELECT
        s.outlet_id,
        SUM(CASE WHEN p.amount > 0 THEN p.amount ELSE 0 END) AS collections,
        SUM(CASE WHEN p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refunds,
        COUNT(DISTINCT CASE WHEN p.amount < 0 THEN p.sale_id END) AS refund_count
    FROM sales s
    LEFT JOIN payments p ON p.sale_id = s.id AND p.is_active = 1
    WHERE $where
    GROUP BY s.outlet_id
");
$stmt->execute($params);

$payMap = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $payMap[$r['outlet_id']] = $r;
}

/* ================= FORMAT ROWS ================= */
$rows = [];
foreach ($rowsRaw as $r) {

    $oid      = $r['outlet_id'];
    $collect  = (float)($payMap[$oid]['collections'] ?? 0);
    $refund   = (float)($payMap[$oid]['refunds'] ?? 0);
    $rCount   = (int)($payMap[$oid]['refund_count'] ?? 0);
    $loyalty  = (float)($loyaltyMap[$oid] ?? 0);

    $net_sales = max(
        0,
        $r['sales_amount'] - $r['discount'] - $loyalty
    );

    $outstanding = max(0, $net_sales - $collect);

    $rows[] = [
        'outlet_id'       => (int)$oid,
        'outlet_name'     => $r['outlet_name'],

        'sales_amount'    => (float)$r['sales_amount'],
        'discount'        => (float)$r['discount'],
        'loyalty_redeemed'=> $loyalty,

        'net_sales'       => round($net_sales, 2),

        'cost_amount'     => (float)$r['cost_amount'],
        'gross_profit'    => round($net_sales - $r['cost_amount'], 2),

        'collections'     => $collect,
        'refund_amount'   => $refund,
        'refund_count'    => $rCount,

        'outstanding'     => round($outstanding, 2)
    ];
}

/* ================= TOTALS ================= */
$totals = [
    'sales_amount'     => 0,
    'discount'         => 0,
    'loyalty_redeemed' => 0,
    'net_sales'        => 0,
    'cost_amount'      => 0,
    'gross_profit'     => 0,
    'collections'      => 0,
    'refund_amount'    => 0,
    'refund_count'     => 0,
    'outstanding'      => 0
];

foreach ($rows as $r) {
    $totals['sales_amount']     += $r['sales_amount'];
    $totals['discount']         += $r['discount'];
    $totals['loyalty_redeemed'] += $r['loyalty_redeemed'];
    $totals['cost_amount']      += $r['cost_amount'];
    $totals['collections']      += $r['collections'];
    $totals['refund_amount']    += $r['refund_amount'];
    $totals['refund_count']     += $r['refund_count'];
}

$totals['net_sales']    = round(
    $totals['sales_amount'] - $totals['discount'] - $totals['loyalty_redeemed'],
    2
);

$totals['gross_profit'] = round(
    $totals['net_sales'] - $totals['cost_amount'],
    2
);

$totals['outstanding']  = round(
    max(0, $totals['net_sales'] - $totals['collections']),
    2
);

/* ================= RESPONSE ================= */
sendSuccess("Profit & Loss Report", [
    'filter' => [
        'today'     => $today ? true : false,
        'date_from' => $date_from,
        'date_to'   => $date_to
    ],
    'pagination' => [
        'page'  => $page,
        'limit' => $limit
    ],
    'rows'   => $rows,
    'totals' => $totals
]);
