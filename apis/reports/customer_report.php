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
$org_id    = (int)($_GET['org_id'] ?? 0);
$outlet_id = $_GET['outlet_id'] ?? null;
$date_from = $_GET['date_from'] ?? null;
$date_to   = $_GET['date_to'] ?? null;
$today     = isset($_GET['today']) && (int)$_GET['today'] === 1;

/* PAGINATION */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

/* ================= ROLE ================= */
if ($authUser['role'] === 'manager') {
    $org_id    = $authUser['org_id'];
    $outlet_id = $authUser['outlet_id'];
}

if ($org_id <= 0) sendError("org_id required", 422);

try {

    /* ================= WHERE ================= */
    $whereCust  = "c.org_id = :org_id";
    $whereSales = "s.org_id = :org_id";

    $paramsCust  = [':org_id' => $org_id];
    $paramsSales = [':org_id' => $org_id];

    if ($outlet_id) {
        $whereCust  .= " AND c.outlet_id = :outlet_id";
        $whereSales .= " AND s.outlet_id = :outlet_id";
        $paramsCust[':outlet_id']  = $outlet_id;
        $paramsSales[':outlet_id'] = $outlet_id;
    }

    if ($today && !$date_from && !$date_to) {
        $whereSales .= " AND DATE(s.created_at) = CURDATE()";
    }

    if ($date_from) {
        $whereSales .= " AND s.created_at >= :df";
        $paramsSales[':df'] = $date_from . " 00:00:00";
    }

    if ($date_to) {
        $whereSales .= " AND s.created_at <= :dt";
        $paramsSales[':dt'] = $date_to . " 23:59:59";
    }

    /* ================= TOTAL CUSTOMERS ================= */
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereCust");
    $stmt->execute($paramsCust);
    $total_customers = (int)$stmt->fetchColumn();

    /* ================= FETCH CUSTOMERS ================= */
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.phone,
            o.name AS outlet_name
        FROM customers c
        JOIN outlets o ON o.id = c.outlet_id
        WHERE $whereCust
        ORDER BY c.id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($paramsCust as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================= SALES + CASH AGG ================= */
    $stmt = $pdo->prepare("
        SELECT
            s.customer_id,

            COUNT(DISTINCT CASE WHEN s.status = 1 THEN s.id END) AS bills,
            COUNT(DISTINCT CASE WHEN s.status = 2 THEN s.id END) AS refund_count,

            COALESCE(SUM(CASE WHEN s.total_amount > 0 THEN si.amount ELSE 0 END),0) AS sales_amount,

            COALESCE(SUM(CASE WHEN p.amount > 0 THEN p.amount ELSE 0 END),0) AS collections,
            COALESCE(SUM(CASE WHEN p.amount < 0 THEN ABS(p.amount) ELSE 0 END),0) AS refunds

        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN payments p
            ON p.sale_id = s.id
           AND p.is_active = 1
        WHERE $whereSales
        GROUP BY s.customer_id
    ");
    $stmt->execute($paramsSales);
    $agg = $stmt->fetchAll(PDO::FETCH_UNIQUE);

    /* ================= MANUAL DISCOUNT (SOURCE OF TRUTH) ================= */
$stmt = $pdo->prepare("
    SELECT
        s.customer_id,
        COALESCE(SUM(
            CAST(
                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        JSON_UNQUOTE(p.meta),
                        '$.manual_discount'
                    )
                ) AS DECIMAL(10,2)
            )
        ),0) AS discount
    FROM sales s
    JOIN payments p
        ON p.sale_id = s.id
       AND p.is_active = 1
    WHERE $whereSales
    GROUP BY s.customer_id
");
$stmt->execute($paramsSales);
$discountAgg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);


    /* ================= LOYALTY (SOURCE OF TRUTH) ================= */
    $stmt = $pdo->prepare("
        SELECT
            s.customer_id,
            COALESCE(SUM(lp.points_redeemed),0) AS loyalty_redeemed
        FROM loyalty_points lp
        JOIN sales s ON s.id = lp.sale_id
        WHERE $whereSales
        AND s.status = 1
        GROUP BY s.customer_id
    ");
    $stmt->execute($paramsSales);
    $loyaltyAgg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    /* ================= MERGE ================= */
    foreach ($customers as &$c) {

        $cid = $c['id'];
        $r = $agg[$cid] ?? [
            'bills'        => 0,
            'refund_count' => 0,
            'sales_amount' => 0,
            'collections'  => 0,
            'refunds'      => 0
        ];

        $loyalty = (float)($loyaltyAgg[$cid] ?? 0);

        $discount = (float)($discountAgg[$cid] ?? 0);

        $net_sales = max(
        0,
        $r['sales_amount']
        - $discount
        - $loyalty
);

        $outstanding = max(0, $net_sales - $r['collections']);

        $c['bills']            = (int)$r['bills'];
        $c['refund_count']     = (int)$r['refund_count'];
        $c['sales_amount']     = (float)$r['sales_amount'];
        $c['discount'] = $discount;
        $c['loyalty_redeemed'] = $loyalty;
        $c['collections']      = (float)$r['collections'];
        $c['refunds']          = (float)$r['refunds'];
        $c['net_sales']        = round($net_sales, 2);
        $c['outstanding']      = round($outstanding, 2);
    }
    unset($c);

    /* ================= TOTALS ================= */
    $totals = [
        'customers'        => $total_customers,
        'bills'            => 0,
        'refund_count'     => 0,
        'sales_amount'     => 0,
        'discount'         => 0,
        'loyalty_redeemed' => 0,
        'collections'      => 0,
        'refunds'          => 0,
        'net_sales'        => 0,
        'outstanding'      => 0
    ];

    foreach ($customers as $c) {
        $totals['bills']            += $c['bills'];
        $totals['refund_count']     += $c['refund_count'];
        $totals['sales_amount']     += $c['sales_amount'];
        $totals['discount'] += $c['discount'];
        $totals['loyalty_redeemed'] += $c['loyalty_redeemed'];
        $totals['collections']      += $c['collections'];
        $totals['refunds']          += $c['refunds'];
    }

    $totals['net_sales'] = round(
    $totals['sales_amount']
    - $totals['discount']
    - $totals['loyalty_redeemed'],
    2
);

    $totals['outstanding'] = round(max(0, $totals['net_sales'] - $totals['collections']), 2);

    /* ================= RESPONSE ================= */
    sendSuccess("Customer report fetched", [
        'filters' => [
            'today'     => $today,
            'date_from' => $date_from,
            'date_to'   => $date_to
        ],
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_rows'  => $total_customers,
            'total_pages' => ceil($total_customers / $limit)
        ],
        'rows'   => $customers,
        'totals' => $totals
    ]);

} catch (Throwable $e) {
    sendError($e->getMessage(), 500);
}
