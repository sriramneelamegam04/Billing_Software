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

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$org_id    = (int)($_REQUEST['org_id'] ?? 0);
$outlet_id = $_REQUEST['outlet_id'] ?? null;
$date_from = $_REQUEST['date_from'] ?? null;
$date_to   = $_REQUEST['date_to'] ?? null;

/* 🔥 NEW (OPTIONAL) PERIOD PARAM */
$period = $_REQUEST['period'] ?? null;

/* -------------------------------------------------
   ROLE BASED FILTER
------------------------------------------------- */
if ($authUser['role'] === 'manager') {
    $org_id = $authUser['org_id'];
    if (!empty($outlet_id) && $outlet_id != $authUser['outlet_id']) {
        sendError("Forbidden: cannot access other outlets", 403);
    }
    $outlet_id = $authUser['outlet_id'];
}

if ($org_id <= 0) sendError("org_id is required", 422);

/* =================================================
   🔥 DATE RANGE AUTO RESOLUTION (ADDED)
   (Old logic untouched)
================================================= */
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

        case 'custom':
            if (!$date_from || !$date_to) {
                sendError("date_from & date_to required for custom period", 422);
            }
            break;

        default:
            sendError("Invalid period value", 422);
    }
}

/* -------------------------------------------------
   EXISTING CODE CONTINUES (UNCHANGED)
------------------------------------------------- */
try {

    /* -------------------------------------------------
       WHERE + PARAMS
    ------------------------------------------------- */
    $whereSales = "s.org_id = :org_id";
    $whereCust  = "c.org_id = :org_id";
    $whereProd  = "p.org_id = :org_id";

    $paramsSales = [':org_id' => $org_id];
    $paramsCust  = [':org_id' => $org_id];
    $paramsProd  = [':org_id' => $org_id];

    if ($outlet_id) {
        $whereSales .= " AND s.outlet_id = :outlet_id";
        $whereCust  .= " AND c.outlet_id = :outlet_id";
        $whereProd  .= " AND p.outlet_id = :outlet_id";

        $paramsSales[':outlet_id'] = $outlet_id;
        $paramsCust[':outlet_id']  = $outlet_id;
        $paramsProd[':outlet_id']  = $outlet_id;
    }

   if ($date_from) {
    $whereSales .= " AND s.created_at >= :df_start";
    $paramsSales[':df_start'] = $date_from . " 00:00:00";
}

if ($date_to) {
    $whereSales .= " AND s.created_at <= :dt_end";
    $paramsSales[':dt_end'] = $date_to . " 23:59:59";
}


    /* -------------------------------------------------
       CUSTOMERS COUNT
    ------------------------------------------------- */
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereCust");
    $stmt->execute($paramsCust);
    $customers = (int)$stmt->fetchColumn();

    /* -------------------------------------------------
       PRODUCTS COUNT
    ------------------------------------------------- */
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $whereProd");
    $stmt->execute($paramsProd);
    $products = (int)$stmt->fetchColumn();

  /* -------------------------------------------------
   SALES TOTAL (FIXED FOR DOUBLE-ENCODED META)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE 
            WHEN s.total_amount >= 0 THEN si.amount 
            ELSE 0 
        END),0) AS sales_amount,

        COALESCE(SUM(CASE 
            WHEN s.total_amount < 0 THEN ABS(si.amount)
            ELSE 0
        END),0) AS return_amount,

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
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN payments p 
        ON p.sale_id = s.id AND p.is_active = 1
    WHERE $whereSales
");
$stmt->execute($paramsSales);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
/* ---------- RETURN COUNT ---------- */
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT s.id)
    FROM sales s
    WHERE $whereSales
      AND s.status = 2
      AND s.total_amount < 0
");
$stmt->execute($paramsSales);
$return_count = (int)$stmt->fetchColumn();


$sales_amount  = (float)$row['sales_amount'];
$return_amount = (float)$row['return_amount'];
$discount      = (float)$row['discount'];

// loyalty_point//

/* -------------------------------------------------
   LOYALTY REDEEMED (SOURCE OF TRUTH)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(lp.points_redeemed), 0)
    FROM loyalty_points lp
    JOIN sales s ON s.id = lp.sale_id
    WHERE s.org_id = :org_id
     AND s.status = 1
");

$paramsLp = [':org_id' => $org_id];

if ($outlet_id) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(lp.points_redeemed), 0)
        FROM loyalty_points lp
        JOIN sales s ON s.id = lp.sale_id
        WHERE s.org_id = :org_id
          AND s.outlet_id = :outlet_id
    ");
    $paramsLp[':outlet_id'] = $outlet_id;
}

if ($date_from) {
    $stmt = $pdo->prepare($stmt->queryString . " AND s.created_at >= :df_start");
    $paramsLp[':df_start'] = $date_from . " 00:00:00";
}

if ($date_to) {
    $stmt = $pdo->prepare($stmt->queryString . " AND s.created_at <= :dt_end");
    $paramsLp[':dt_end'] = $date_to . " 23:59:59";
}

$stmt->execute($paramsLp);
$loyalty_redeemed = (float)$stmt->fetchColumn();



/* -------------------------------------------------
   COLLECTIONS (CASH ONLY – CORRECT)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(p.amount), 0)
    FROM sales s
    JOIN payments p
      ON p.sale_id = s.id
     AND p.is_active = 1
    WHERE $whereSales
      AND s.total_amount > 0
      AND s.status = 1
      AND p.amount > 0
");
$stmt->execute($paramsSales);
$collections = (float)$stmt->fetchColumn();


//refunf //

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(ABS(p.amount)),0)
    FROM sales s
    JOIN payments p
      ON p.sale_id = s.id
     AND p.is_active = 1
    WHERE $whereSales
      AND s.total_amount < 0
      AND p.amount < 0
");
$stmt->execute($paramsSales);
$refunds = (float)$stmt->fetchColumn();
// ✅ NET SALES (CREDIT BASIS)
$net_sales = round(
    $sales_amount
    - $return_amount
    - $discount
    - $loyalty_redeemed,
2);
if ($net_sales < 0) $net_sales = 0;

$outstanding = round(max(0, $net_sales - $collections), 2);

/* -------------------------------------------------
   TOP PRODUCTS (LATEST POSITIVE SALE ONLY)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        si.product_id,
        pr.name AS product_name,
        si.quantity AS qty,
        si.amount   AS amount
    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    JOIN products pr   ON pr.id = si.product_id
    WHERE $whereSales
      AND s.total_amount > 0
      AND s.id = (
          SELECT s2.id
          FROM sales s2
          WHERE s2.org_id = s.org_id
            AND s2.total_amount > 0
          ORDER BY s2.created_at DESC
          LIMIT 1
      )
");

$stmt->execute($paramsSales);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);



/* -------------------------------------------------
   SALES SUMMARY (DATE WISE – CORRECTED)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        DATE(s.created_at) AS sdate,

        COUNT(DISTINCT CASE
            WHEN s.total_amount >= 0 THEN s.id
        END) AS sale_bills,

        COUNT(DISTINCT CASE
            WHEN s.total_amount < 0 AND s.status = 2 THEN s.id
        END) AS return_bills,

        /* ✅ SALES AMOUNT (ITEM LEVEL) */
        COALESCE(SUM(
            CASE
                WHEN s.total_amount >= 0 THEN si.amount
                ELSE 0
            END
        ), 0) AS sales_amount,

        /* ✅ DISCOUNT (SALE LEVEL – SAFE) */
        COALESCE(SUM(pd.manual_discount), 0) AS discount

    FROM sales s
    LEFT JOIN sale_items si
        ON si.sale_id = s.id

    /* 🔥 PAYMENT DISCOUNT AS SUBQUERY */
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

    WHERE $whereSales
    GROUP BY DATE(s.created_at)
    ORDER BY sdate ASC
");

$stmt->execute($paramsSales);
$sales_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);



    /* -------------------------------------------------
       FINAL RESPONSE
    ------------------------------------------------- */
    sendSuccess([
        'filters' => [
            'period'    => $period,
            'date_from' => $date_from,
            'date_to'   => $date_to
        ],
        'kpis' => [
    'customers'    => $customers,
    'products'     => $products,
    'total_sales'  => $sales_amount,
    'return_count'  => $return_count,
    'return_amount' => $return_amount,
    'loyalty_redeemed' => $loyalty_redeemed,
    'discount'     => $discount,

    'net_sales'    => $net_sales,

    'collections'  => $collections,
    'refunds'      => $refunds,

    'outstanding'  => $outstanding
        ],
        'sales_summary' => $sales_summary,
        'top_products'  => $top_products
    ], "Dashboard data fetched successfully");

} catch (Throwable $e) {
    sendError($e->getMessage(), 500);
}