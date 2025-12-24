<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

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

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$q = strtolower(trim($_GET['q'] ?? ''));

$outlet_id = $_GET['outlet_id'] ?? null;
if (!$outlet_id) sendError("outlet_id is required", 422);
$outlet_id = (int)$outlet_id;

/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   FETCH CUSTOMERS
------------------------------------------------- */
if ($q) {
    $stmt = $pdo->prepare("
        SELECT id, name, phone, created_at
        FROM customers
        WHERE org_id=? 
          AND outlet_id=? 
          AND (LOWER(name) LIKE ? OR phone LIKE ?)
        ORDER BY id DESC
    ");
    $like = "%$q%";
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id,
        $like,
        $like
    ]);
} else {
    $stmt = $pdo->prepare("
        SELECT id, name, phone, created_at
        FROM customers
        WHERE org_id=? 
          AND outlet_id=?
        ORDER BY id DESC
    ");
    $stmt->execute([
        $authUser['org_id'],
        $outlet_id
    ]);
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   CSV DOWNLOAD HEADERS
------------------------------------------------- */
$filename = "customers_outlet_{$outlet_id}_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

/* -------------------------------------------------
   OUTPUT CSV
------------------------------------------------- */
$output = fopen('php://output', 'w');

/* CSV HEADER */
fputcsv($output, ['Customer ID', 'Name', 'Phone', 'Created At']);

foreach ($rows as $r) {
    fputcsv($output, [
        $r['id'],
        $r['name'],
        $r['phone'],
        $r['created_at']
    ]);
}

fclose($output);
exit;
