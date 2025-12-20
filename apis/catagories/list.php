<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Method Not Allowed", 405);
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   PARAMS
------------------------------------------------- */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, (int)($_GET['limit'] ?? 10));
$search = trim($_GET['search'] ?? '');

$offset = ($page - 1) * $limit;

/* -------------------------------------------------
   COUNT
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM categories
    WHERE org_id = ?
      AND status = 1
      AND name LIKE ?
");
$stmt->execute([
    $authUser['org_id'],
    "%$search%"
]);
$total = (int)$stmt->fetchColumn();

/* -------------------------------------------------
   DATA (⚠ FIX HERE)
------------------------------------------------- */
$sql = "
    SELECT id, name, created_at
    FROM categories
    WHERE org_id = ?
      AND status = 1
      AND name LIKE ?
    ORDER BY id DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $authUser['org_id'],
    "%$search%"
]);

sendSuccess([
    "categories" => $stmt->fetchAll(PDO::FETCH_ASSOC),
    "pagination" => [
        "page"  => $page,
        "limit" => $limit,
        "total" => $total
    ]
]);
