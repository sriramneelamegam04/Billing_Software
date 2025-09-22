<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);

// --- Query Parameters ---
$outlet_id   = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;
$q           = isset($_GET['q']) ? trim($_GET['q']) : '';
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$start_date  = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
$end_date    = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;
$limit       = isset($_GET['limit']) ? min(100,(int)$_GET['limit']) : 20; // max 100
$offset      = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$sort_by     = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'id';
$sort_order  = (isset($_GET['sort_order']) && strtolower($_GET['sort_order'])=='asc') ? 'ASC' : 'DESC';

// --- Validate outlet ---
if(!$outlet_id) sendError("Parameter 'outlet_id' is required", 422);
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
if(!$stmt->fetch()) sendError("Invalid outlet_id or does not belong to your organization", 403);

// --- Base query ---
$sql = "SELECT id, invoice_no, total_amount, discount, customer_id, created_at 
        FROM sales WHERE org_id=? AND outlet_id=?";
$params = [$authUser['org_id'], $outlet_id];

// --- Apply search filter ---
if(!empty($q)){
    $q = strtolower($q);
    if(is_numeric($q)){
        $sql .= " AND (id=? OR invoice_no LIKE ?)";
        $params[] = $q;
        $params[] = "%$q%";
    } else {
        $sql .= " AND LOWER(invoice_no) LIKE ?";
        $params[] = "%$q%";
    }
}

// --- Filter by customer ---
if($customer_id){
    $sql .= " AND customer_id=?";
    $params[] = $customer_id;
}

// --- Filter by date range ---
if($start_date){
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $start_date;
}
if($end_date){
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $end_date;
}

// --- Sorting ---
$allowedSort = ['id','invoice_no','total_amount','created_at'];
if(!in_array($sort_by,$allowedSort)) $sort_by='id';
$sql .= " ORDER BY $sort_by $sort_order";

// --- Pagination ---
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// --- Fetch records ---
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Get total count for pagination ---
$countSql = "SELECT COUNT(*) FROM sales WHERE org_id=? AND outlet_id=?";
$countParams = [$authUser['org_id'], $outlet_id];

if(!empty($q)){
    if(is_numeric($q)){
        $countSql .= " AND (id=? OR invoice_no LIKE ?)";
        $countParams[] = $q;
        $countParams[] = "%$q%";
    } else {
        $countSql .= " AND LOWER(invoice_no) LIKE ?";
        $countParams[] = "%$q%";
    }
}
if($customer_id){
    $countSql .= " AND customer_id=?";
    $countParams[] = $customer_id;
}
if($start_date){
    $countSql .= " AND DATE(created_at) >= ?";
    $countParams[] = $start_date;
}
if($end_date){
    $countSql .= " AND DATE(created_at) <= ?";
    $countParams[] = $end_date;
}

$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$totalCount = (int)$stmt->fetchColumn();

// --- Response ---
sendSuccess([
    'records' => $sales,
    'total'   => $totalCount,
    'limit'   => $limit,
    'offset'  => $offset
], count($sales)." record(s) found");
