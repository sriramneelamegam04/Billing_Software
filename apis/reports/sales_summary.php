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
$period    = $_REQUEST['period'] ?? null;

/* ================= ROLE ================= */
if ($authUser['role'] === 'manager') {
    $org_id    = $authUser['org_id'];
    $outlet_id = $authUser['outlet_id'];
}

if ($org_id <= 0) sendError("org_id required", 422);

/* ================= PERIOD ================= */
if ($period && !$date_from && !$date_to) {
    switch ($period) {
        case 'today':
            $date_from = date('Y-m-d');
            $date_to   = date('Y-m-d');
            break;
        case 'last_3_days':
            $date_from = date('Y-m-d', strtotime('-2 days'));
            $date_to   = date('Y-m-d');
            break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('first day of last month'));
            $date_to   = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'last_3_months':
            $date_from = date('Y-m-01', strtotime('-2 months'));
            $date_to   = date('Y-m-t');
            break;
        case 'last_year':
            $date_from = date('Y-01-01', strtotime('-1 year'));
            $date_to   = date('Y-12-31', strtotime('-1 year'));
            break;
        default:
            sendError("Invalid period", 422);
    }
}

try {

    /* ================= WHERE ================= */
    $where = "s.org_id = :org_id";
    $params = [':org_id' => $org_id];

    if ($outlet_id) {
        $where .= " AND s.outlet_id = :outlet_id";
        $params[':outlet_id'] = $outlet_id;
    }

    if ($date_from) {
        $where .= " AND s.created_at >= :df";
        $params[':df'] = $date_from . " 00:00:00";
    }

    if ($date_to) {
        $where .= " AND s.created_at <= :dt";
        $params[':dt'] = $date_to . " 23:59:59";
    }

    /* ================= DATE WISE SUMMARY ================= */
    $stmt = $pdo->prepare("
    SELECT
        DATE(s.created_at) AS sdate,

        COUNT(DISTINCT CASE WHEN s.total_amount > 0 THEN s.id END) AS sale_bills,
        COUNT(DISTINCT CASE WHEN s.total_amount < 0 AND s.status = 2 THEN s.id END) AS return_bills,

        COALESCE(SUM(
            CASE WHEN s.total_amount > 0 THEN si.quantity ELSE 0 END
        ),0) AS items_qty,

        COALESCE(SUM(
            CASE WHEN s.total_amount > 0 THEN si.amount ELSE 0 END
        ),0) AS sales_amount,

        /* MANUAL DISCOUNT (SALE LEVEL) */
        COALESCE(SUM(pd.manual_discount),0) AS discount,

        /* CASH COLLECTION */
        COALESCE(SUM(
            CASE WHEN p.amount > 0 THEN p.amount ELSE 0 END
        ),0) AS collections,

        /* REFUNDS */
        COALESCE(SUM(
            CASE WHEN p.amount < 0 THEN ABS(p.amount) ELSE 0 END
        ),0) AS refunds

    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN payments p
        ON p.sale_id = s.id
       AND p.is_active = 1

    /* 🔥 MANUAL DISCOUNT SUBQUERY */
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
    GROUP BY DATE(s.created_at)
    ORDER BY sdate ASC
");

    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================= LOYALTY (SOURCE OF TRUTH) ================= */
    $stmt = $pdo->prepare("
        SELECT
            DATE(s.created_at) AS sdate,
            COALESCE(SUM(lp.points_redeemed),0) AS loyalty_redeemed
        FROM loyalty_points lp
        JOIN sales s ON s.id = lp.sale_id
        WHERE $where
        AND s.status = 1
        GROUP BY DATE(s.created_at)
    ");
    $stmt->execute($params);
    $loyaltyRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    /* ================= MERGE & TOTALS ================= */
    $totals = [
        'sale_bills'      => 0,
        'return_bills'    => 0,
        'items_qty'       => 0,
        'sales_amount'    => 0,
        'discount'        => 0,
        'loyalty_redeemed'=> 0,
        'collections'     => 0,
        'refunds'         => 0,
        'net_sales'       => 0,
        'outstanding'     => 0
    ];

    foreach ($rows as &$r) {

        $redeem = (float)($loyaltyRows[$r['sdate']] ?? 0);
        $net = max(
    0,
    $r['sales_amount']
    - (float)$r['discount']
    - $redeem
);

        $out = max(0, $net - $r['collections']);

        $r['loyalty_redeemed'] = $redeem;
        $r['net_sales']        = round($net, 2);
        $r['outstanding']      = round($out, 2);

        $totals['sale_bills']       += (int)$r['sale_bills'];
        $totals['return_bills']     += (int)$r['return_bills'];
        $totals['items_qty']        += (float)$r['items_qty'];
        $totals['sales_amount']     += (float)$r['sales_amount'];
        $totals['discount'] = ($totals['discount'] ?? 0) + (float)$r['discount'];
        $totals['loyalty_redeemed'] += $redeem;
        $totals['collections']      += (float)$r['collections'];
        $totals['refunds']          += (float)$r['refunds'];
    }

   $totals['net_sales'] = round(
    $totals['sales_amount']
    - $totals['discount']
    - $totals['loyalty_redeemed'],
    2
);

    $totals['outstanding'] = round(
        max(0, $totals['net_sales'] - $totals['collections']),
        2
    );

    sendSuccess([
        'rows'   => $rows,
        'totals' => $totals
    ], "Sales summary fetched successfully");

} catch (Throwable $e) {
    sendError($e->getMessage(), 500);
}
