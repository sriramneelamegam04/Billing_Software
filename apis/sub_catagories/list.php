<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendError("Method Not Allowed", 405);

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   PARAMS
------------------------------------------------- */
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, (int)($_GET['limit'] ?? 10));
$search = trim($_GET['search'] ?? '');

$offset = ($page - 1) * $limit;

/* -------------------------------------------------
   BASE WHERE
------------------------------------------------- */
$where = "
    WHERE c.org_id = ?
      AND sc.status = 1
      AND sc.name LIKE ?
";
$params = [
    $authUser['org_id'],
    "%$search%"
];

if ($category_id) {
    $where .= " AND sc.category_id = ? ";
    $params[] = $category_id;
}

/* -------------------------------------------------
   COUNT
------------------------------------------------- */
$countSql = "
    SELECT COUNT(*)
    FROM sub_categories sc
    JOIN categories c ON sc.category_id = c.id
    $where
";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

/* -------------------------------------------------
   DATA
------------------------------------------------- */
$dataSql = "
    SELECT sc.id, sc.name, sc.category_id, c.name AS category_name
    FROM sub_categories sc
    JOIN categories c ON sc.category_id = c.id
    $where
    ORDER BY sc.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($dataSql);
$stmt->execute($params);

sendSuccess([
    "sub_categories" => $stmt->fetchAll(PDO::FETCH_ASSOC),
    "pagination" => [
        "page" => $page,
        "limit" => $limit,
        "total" => $total
    ]
]);
