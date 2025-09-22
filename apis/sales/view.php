<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized", 401);

$input = json_decode(file_get_contents("php://input"), true);
if(!$input) sendError("Invalid JSON format");

// Either id OR invoice_no required
if (empty($input['id']) && empty($input['invoice_no'])) {
    sendError("Either sale id or invoice_no is required");
}

$sale_id    = !empty($input['id']) ? (int)$input['id'] : null;
$invoice_no = !empty($input['invoice_no']) ? trim($input['invoice_no']) : null;

try {
    if ($authUser['role'] === 'admin') {
        // ✅ Admin query (org level)
        $query = "
            SELECT s.*
            FROM sales s
            INNER JOIN outlets o ON s.outlet_id = o.id
            WHERE o.org_id = :org_id
        ";
        $params = [':org_id' => $authUser['org_id']];

        if ($sale_id) {
            $query .= " AND s.id = :id";
            $params[':id'] = $sale_id;
        } elseif ($invoice_no) {
            $query .= " AND s.invoice_no = :invoice_no";
            $params[':invoice_no'] = $invoice_no;
        }

        $query .= " LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

    } else {
        // ✅ Outlet manager query (strict outlet check)
        $query = "
            SELECT s.*
            FROM sales s
            INNER JOIN outlets o ON s.outlet_id = o.id
            WHERE o.org_id = :org_id
              AND o.id = :outlet_id
        ";
        $params = [
            ':org_id' => $authUser['org_id'],
            ':outlet_id' => $authUser['outlet_id']
        ];

        if ($sale_id) {
            $query .= " AND s.id = :id";
            $params[':id'] = $sale_id;
        } elseif ($invoice_no) {
            $query .= " AND s.invoice_no = :invoice_no";
            $params[':invoice_no'] = $invoice_no;
        }

        $query .= " LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
    }

    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$sale){
        sendError("Sale not found", 404);
    }

    sendSuccess($sale, "Sale fetched successfully");

} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}
