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

// Either id OR barcode required
if (empty($input['id']) && empty($input['barcode'])) {
    sendError("Either product id or barcode is required");
}

$product_id = !empty($input['id']) ? (int)$input['id'] : null;
$barcode    = !empty($input['barcode']) ? trim($input['barcode']) : null;

try {
    if ($authUser['role'] === 'admin') {
        // ✅ Admin query
        $query = "
            SELECT p.*
            FROM products p
            INNER JOIN outlets o ON p.outlet_id = o.id
            WHERE o.org_id = :org_id
        ";
        $params = [':org_id' => $authUser['org_id']];

        if ($product_id) {
            $query .= " AND p.id = :id";
            $params[':id'] = $product_id;
        } elseif ($barcode) {
            $query .= " AND JSON_UNQUOTE(JSON_EXTRACT(p.meta, '$.barcode')) = :barcode";
            $params[':barcode'] = $barcode;
        }

        $query .= " LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

    } else {
        // ✅ Outlet manager query
        $query = "
            SELECT p.*
            FROM products p
            INNER JOIN outlets o ON p.outlet_id = o.id
            WHERE o.org_id = :org_id
              AND o.id = :outlet_id
        ";
        $params = [
            ':org_id' => $authUser['org_id'],
            ':outlet_id' => $authUser['outlet_id']
        ];

        if ($product_id) {
            $query .= " AND p.id = :id";
            $params[':id'] = $product_id;
        } elseif ($barcode) {
            $query .= " AND JSON_UNQUOTE(JSON_EXTRACT(p.meta, '$.barcode')) = :barcode";
            $params[':barcode'] = $barcode;
        }

        $query .= " LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
    }

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$product){
        sendError("Product not found", 404);
    }

    sendSuccess($product, "Product fetched successfully");

} catch (Exception $e) {
    sendError($e->getMessage(), 500);
}
