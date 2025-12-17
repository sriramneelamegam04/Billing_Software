<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$outlet_id    = (int)($_GET['outlet_id'] ?? 0);
$product_id   = (int)($_GET['product_id'] ?? 0);
$variant_id   = isset($_GET['variant_id']) ? (int)$_GET['variant_id'] : null;

$from         = $_GET['from_date'] ?? null;
$to           = $_GET['to_date'] ?? null;
$type         = $_GET['type'] ?? null;
$page         = (int)($_GET['page'] ?? 1);
$limit        = (int)($_GET['limit'] ?? 20);
$summary      = ($_GET['summary'] ?? "no") === "yes";

if (!$outlet_id) sendError("outlet_id required");

$offset = ($page - 1) * $limit;


// ---------------------------
// Validate outlet
// ---------------------------
$stmt = $pdo->prepare("
    SELECT id FROM outlets 
    WHERE id=? AND org_id=? LIMIT 1
");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if (!$stmt->fetch()) sendError("Invalid outlet");


// ---------------------------
// Build WHERE
// ---------------------------
$where = "WHERE l.org_id = :org AND l.outlet_id = :outlet";
$params = [
    ':org'    => $authUser['org_id'],
    ':outlet' => $outlet_id
];

if ($product_id > 0) {
    $where .= " AND l.product_id = :pid";
    $params[':pid'] = $product_id;
}

if (!empty($variant_id)) {
    $where .= " AND l.variant_id = :vid";
    $params[':vid'] = $variant_id;
}

if ($type) {
    $where .= " AND l.change_type = :type";
    $params[':type'] = $type;
}

if ($from) {
    $where .= " AND l.created_at >= :from";
    $params[':from'] = $from . " 00:00:00";
}

if ($to) {
    $where .= " AND l.created_at <= :to";
    $params[':to'] = $to . " 23:59:59";
}


// ---------------------------
// MAIN HISTORY QUERY
// ---------------------------
$sql = "
    SELECT 
        l.id,
        l.product_id,
        p.name AS product_name,
        l.variant_id,
        v.name AS variant_name,
        l.change_type,
        l.quantity_change,
        l.note,
        l.reference_id,
        l.created_at
    FROM inventory_logs l
    LEFT JOIN products p 
        ON p.id = l.product_id
       AND p.org_id = l.org_id
       AND p.outlet_id = l.outlet_id
    LEFT JOIN product_variants v
        ON v.id = l.variant_id
    $where
    ORDER BY l.id DESC
    LIMIT $offset, $limit
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ---------------------------
// SUMMARY SECTION
// ---------------------------
if ($summary) {
    $sumSQL = "
        SELECT 
            SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) AS total_in,
            SUM(CASE WHEN quantity_change < 0 THEN quantity_change ELSE 0 END) AS total_out
        FROM inventory_logs l
        $where
    ";

    $sumStmt = $pdo->prepare($sumSQL);
    $sumStmt->execute($params);
    $sumData = $sumStmt->fetch(PDO::FETCH_ASSOC);

    $summaryData = [
        "total_in"  => (float)$sumData['total_in'],
        "total_out" => (float)$sumData['total_out'],
        "balance"   => (float)$sumData['total_in'] + (float)$sumData['total_out']
    ];
} else {
    $summaryData = null;
}


// ---------------------------
// RESPONSE
// ---------------------------
sendSuccess([
    "page"     => $page,
    "limit"    => $limit,
    "records"  => $rows,
    "summary"  => $summaryData
], "Stock history loaded");

?>
